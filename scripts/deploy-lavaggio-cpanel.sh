#!/usr/bin/env bash
# cPanel pull deployment for the standard Lavaggio Laravel installation.
# The Git checkout is the running application; only its public/ is web-accessible.
# Without --apply this only validates prerequisites and shows the deployment plan.
set -Eeuo pipefail
umask 077

apply=false
case "${1:-}" in
    '') ;;
    --apply) apply=true ;;
    --help|-h) printf '%s\n' 'Usage: bash scripts/deploy-lavaggio-cpanel.sh [--apply]' 'The default is a read-only deployment plan.'; exit 0 ;;
    *) printf '%s\n' 'Unsupported argument. Use --apply or --help.' >&2; exit 1 ;;
esac
[[ $# -le 1 ]] || { printf '%s\n' 'Only one argument is supported.' >&2; exit 1; }

account_root=/home/baredahr
source_root=/home/baredahr/shop.lavaggio.hr
runtime_root="$source_root"
public_root="$source_root/public"
php_bin=/opt/alt/php84/usr/bin/php
backup_base=/home/baredahr/.lavaggio-shop-backups
backup_root=''
fail() { printf '%s\n' "Lavaggio deployment stopped: $*" >&2; exit 1; }
trap 'printf "%s\n" "Deployment failed. Review the private backup: ${backup_root:-not created}. No database restore or migration was attempted." >&2' ERR

[[ "$(id -un)" == baredahr && "${HOME:-}" == "$account_root" ]] || fail 'Run as the baredahr account with its normal HOME.'
for tool in realpath stat du df flock; do command -v "$tool" >/dev/null || fail "Required tool is unavailable: $tool"; done
git_bin="$(command -v git || true)"
[[ -n "$git_bin" ]] || git_bin=/usr/local/cpanel/3rdparty/lib/path-bin/git
[[ -x "$git_bin" ]] || fail 'Git is unavailable.'
[[ -x "$php_bin" ]] || fail 'The verified PHP 8.4 CLI is unavailable.'
for directory in "$account_root" "$source_root" "$public_root"; do
    [[ -d "$directory" && ! -L "$directory" && "$(realpath "$directory")" == "$directory" ]] || fail "Unsafe or missing fixed directory: $directory"
    [[ "$(stat -c '%U' "$directory")" == baredahr ]] || fail "Unexpected directory owner: $directory"
done
[[ "$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd -P)" == "$source_root" ]] || fail 'Use the script from the registered Lavaggio checkout.'
[[ -d "$source_root/.git" && ! -L "$source_root/.git" ]] || fail 'The project must contain its own ordinary .git directory.'
[[ "$("$git_bin" -C "$source_root" rev-parse --show-toplevel)" == "$source_root" ]] || fail 'The source must be the exact registered checkout.'
[[ "$("$git_bin" -C "$source_root" branch --show-current)" == main ]] || fail 'The checkout must be on main.'
case "$("$git_bin" -C "$source_root" remote get-url origin)" in
    https://github.com/tomashina/lavaggio.git|git@github.com:tomashina/lavaggio.git|ssh://git@github.com/tomashina/lavaggio.git) ;;
    *) fail 'Unexpected source Git origin.' ;;
esac
[[ -z "$("$git_bin" -C "$source_root" status --porcelain --untracked-files=normal)" ]] || fail 'The cPanel checkout must be clean; this script never pulls or resets Git.'
revision="$("$git_bin" -C "$source_root" rev-parse --verify HEAD)"
[[ -f "$source_root/public/build/manifest.json" && -f "$source_root/composer.lock" ]] || fail 'The committed frontend build and Composer lock are required.'
[[ -f "$runtime_root/.env" && ! -L "$runtime_root/.env" ]] || fail 'Prepare the existing private .env inside the project before deployment.'
[[ -f "$public_root/.htaccess" && ! -L "$public_root/.htaccess" ]] || fail 'The existing domain .htaccess is required.'
[[ ! -L "$public_root/.user.ini" ]] || fail 'The domain .user.ini must not be a symlink.'

composer_bin=''
composer_from_path="$(command -v composer || true)"
for candidate in /home/baredahr/.local/bin/composer.phar "$composer_from_path" /opt/cpanel/composer/bin/composer /usr/local/bin/composer /usr/bin/composer /bin/composer; do
    if [[ -n "$candidate" && -f "$candidate" && -r "$candidate" ]]; then composer_bin="$candidate"; break; fi
done
[[ -n "$composer_bin" ]] || fail 'Install/configure Composer on the server first; this script does not download executables.'

# Validate without booting Laravel, loading old caches, printing secrets or writing data.
"$php_bin" /dev/stdin "$runtime_root" "$public_root" <<'LAVAGGIO_PREREQUISITES'
<?php
function stopDeploy(string $message): never { fwrite(STDERR, "Lavaggio prerequisite failed: ".$message."\n"); exit(1); }
[$script, $root, $public] = $argv;
if (PHP_VERSION_ID < 80400 || PHP_VERSION_ID >= 80500) stopDeploy('PHP 8.4 is required.');
$bootstrapKeys = ['APP_CONFIG_CACHE', 'APP_BASE_PATH', 'APP_ENV_PATH', 'APP_SERVICES_CACHE', 'APP_PACKAGES_CACHE', 'APP_ROUTES_CACHE', 'APP_EVENTS_CACHE', 'VIEW_COMPILED_PATH'];
$required = ['APP_ENV'=>'staging', 'APP_URL'=>'https://shop.lavaggio.hr', 'APP_DEBUG'=>'false',
    'HERRERA_LOCAL_SAFE_MODE'=>'true', 'MAIL_MAILER'=>'log', 'QUEUE_CONNECTION'=>'sync',
    'SESSION_DRIVER'=>'file', 'SESSION_COOKIE'=>'lavaggio_shop_session', 'SESSION_SECURE_COOKIE'=>'true',
    'CACHE_STORE'=>'file', 'STOREFRONT_HOME'=>'lavaggio', 'STORE_B2C_ONLY'=>'true', 'STORE_B2B_ONLY'=>'false',
    'GIFT_VOUCHER_EMAILS_ENABLED'=>'false', 'STOCK_NOTIFICATIONS_EMAILS_ENABLED'=>'false'];
$watched = array_fill_keys([...array_keys($required), ...$bootstrapKeys, 'APP_KEY', 'DB_CONNECTION', 'DB_HOST', 'DB_PORT', 'DB_DATABASE', 'DB_USERNAME', 'DB_PASSWORD', 'DB_SOCKET', 'DB_CHARSET', 'DB_COLLATION', 'DB_URL', 'SESSION_DOMAIN', 'SESSION_CONNECTION', 'DB_CACHE_CONNECTION', 'LAVAGGIO_INHERITED_WEB_PHP84_VERIFIED'], true);
$values = [];
foreach (file($root.'/.env', FILE_IGNORE_NEW_LINES) as $line) {
    if (! preg_match('/^\s*(?:export\s+)?([A-Za-z_][A-Za-z0-9_]*)\s*=(.*)$/', $line, $match) || ! isset($watched[$match[1]])) continue;
    $key = $match[1];
    if (array_key_exists($key, $values)) stopDeploy('A protected environment key is duplicated.');
    $value = trim($match[2]);
    if ($key === 'DB_PASSWORD') {
        // Dotenv single quotes preserve $, backslashes, # and double quotes literally.
        // Accept only forms whose value is identical in Dotenv and Laravel env().
        if (preg_match('/\A\x27([^\x27]*)\x27(?:\s*#.*)?\z/', $value, $password)) {
            $value = $password[1];
        } elseif (preg_match('/\A\x22([^\x22\\\\$]*)\x22(?:\s*#.*)?\z/', $value, $password)) {
            $value = $password[1];
        } elseif (preg_match('/\A([^\s#\x22\x27\\\\$]*)(?:\s*#.*)?\z/', $value, $password)) {
            $value = $password[1];
        } else {
            stopDeploy('Use a one-line single-quoted DB_PASSWORD for literal special characters; embedded single quotes require separate review.');
        }
        if (in_array(strtolower($value), ['true', '(true)', 'false', '(false)', 'null', '(null)', 'empty', '(empty)'], true)
            || preg_match('/\A([\x22\x27]).*\1\z/', $value)) stopDeploy('DB_PASSWORD must remain an explicit string after Laravel environment parsing.');
        $values[$key] = $value;
        continue;
    }
    if ($value !== '' && ($value[0] === '"' || $value[0] === "'")) {
        if (! preg_match('/\A([\x22\x27])(.*)\1(?:\s+#.*)?\z/', $value, $quoted)) stopDeploy('Unsupported protected environment quoting.');
        $value = $quoted[2];
    } else {
        $value = trim(preg_replace('/\s+#.*$/', '', $value));
    }
    if (str_contains($value, '$') || str_contains($value, '\\')) stopDeploy('Protected environment values must be explicit literals.');
    $values[$key] = $value;
}
foreach ($required as $key=>$expected) if (($values[$key] ?? null) !== $expected) stopDeploy('Missing or unsafe setting: '.$key);
foreach ($bootstrapKeys as $key) if (($values[$key] ?? '') !== '') stopDeploy('Custom bootstrap/cache paths are not allowed: '.$key);
foreach (['DB_URL', 'DB_SOCKET', 'SESSION_DOMAIN', 'SESSION_CONNECTION', 'DB_CACHE_CONNECTION'] as $key) {
    if (! in_array(strtolower($values[$key] ?? ''), ['', 'null', '(null)'], true)) stopDeploy('Shared connection/domain overrides are not allowed: '.$key);
}
foreach ($watched as $key=>$_) {
    $inherited = getenv($key);
    if ($inherited !== false && $inherited !== ($values[$key] ?? '')) stopDeploy('Inherited environment conflicts with .env: '.$key);
}
$key = $values['APP_KEY'] ?? '';
$key = str_starts_with($key, 'base64:') ? base64_decode(substr($key, 7), true) : $key;
if (! is_string($key) || strlen($key) !== 32) stopDeploy('Preserve the current valid application key; this script never generates one.');
$connection = $values['DB_CONNECTION'] ?? '';
if ($connection === 'mysql') {
    // This allowlist must never include the main site's baredahr_lavaggio database.
    if (($values['DB_DATABASE'] ?? '') !== 'baredahr_lavaggioshop') stopDeploy('MySQL must use the dedicated baredahr_lavaggioshop database.');
    if (($values['DB_HOST'] ?? '') !== 'localhost' || ($values['DB_PORT'] ?? '3306') !== '3306') stopDeploy('MySQL must use the account-local localhost:3306 server.');
    $username = $values['DB_USERNAME'] ?? '';
    if (! preg_match('/\Abaredahr_[A-Za-z0-9_]+\z/', $username) || $username === 'baredahr_lavaggio') stopDeploy('MySQL requires a dedicated shop database user.');
    if (($values['DB_PASSWORD'] ?? '') === '') stopDeploy('MySQL requires the existing private shop database password.');
    if (($values['DB_CHARSET'] ?? 'utf8mb4') !== 'utf8mb4' || ($values['DB_COLLATION'] ?? 'utf8mb4_unicode_ci') !== 'utf8mb4_unicode_ci') stopDeploy('MySQL must use utf8mb4 with utf8mb4_unicode_ci collation.');
    try {
        $pdo = new PDO('mysql:host=localhost;port=3306;dbname=baredahr_lavaggioshop;charset=utf8mb4', $username, $values['DB_PASSWORD'], [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION, PDO::ATTR_EMULATE_PREPARES=>false]);
        $pdo->exec('SET SESSION TRANSACTION READ ONLY');
        $pdo->beginTransaction();
        if ($pdo->query('SELECT DATABASE()')->fetchColumn() !== 'baredahr_lavaggioshop') stopDeploy('MySQL connected to an unexpected database.');
    } catch (Throwable $exception) {
        stopDeploy('The dedicated MySQL database could not be opened read-only; check its credentials, grants and PDO driver privately.');
    }
} elseif ($connection === 'sqlite') {
    // Retained for the existing private snapshot and deliberate SQLite previews.
    $database = $root.'/database/database.sqlite';
    if (($values['DB_DATABASE'] ?? '') !== $database || ! is_file($database) || is_link($database) || realpath($database) !== $database || (stat($database)['nlink'] ?? 0) !== 1) stopDeploy('SQLite must be the project\'s separate regular database/database.sqlite file.');
    $pdo = new PDO('sqlite:file:'.$database.'?mode=ro', options: [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
    $pdo->exec('PRAGMA query_only=ON');
} else {
    stopDeploy('Only the dedicated shop MySQL database or the existing project SQLite snapshot is allowed.');
}
$ran = $pdo->query('SELECT migration FROM migrations')->fetchAll(PDO::FETCH_COLUMN);
$pending = array_values(array_diff(array_map(fn ($file)=>basename($file, '.php'), glob($root.'/database/migrations/*.php')), $ran));
if ($pending !== []) stopDeploy("Pending migrations require separate review; none were run:\n".implode("\n", $pending));
$settings = [];
foreach ($pdo->query("SELECT `key`,`value` FROM system_settings WHERE `key` IN ('store_email_enabled','gls_api_enabled','watchline_integrations')") as $row) {
    $settings[$row['key']] = json_decode($row['value'], true, flags: JSON_THROW_ON_ERROR);
}
if (filter_var($settings['store_email_enabled'] ?? false, FILTER_VALIDATE_BOOLEAN)) stopDeploy('Mail must remain disabled for this preview.');
if (filter_var($settings['gls_api_enabled'] ?? false, FILTER_VALIDATE_BOOLEAN)) stopDeploy('GLS API must remain disabled for this preview.');
$integrations = $settings['watchline_integrations'] ?? [];
if (filter_var($integrations['live_enabled'] ?? false, FILTER_VALIDATE_BOOLEAN)) stopDeploy('Inherited integrations must remain disabled.');
foreach ($integrations['schedules'] ?? [] as $schedule) if (filter_var($schedule['enabled'] ?? false, FILTER_VALIDATE_BOOLEAN)) stopDeploy('Inherited integration schedules must remain disabled.');
if ($connection === 'mysql') $pdo->rollBack();
$manifest = json_decode(file_get_contents($root.'/public/build/manifest.json'), true, flags: JSON_THROW_ON_ERROR);
if (! is_array($manifest) || $manifest === []) stopDeploy('Missing frontend manifest entries.');
foreach ($manifest as $entry) foreach ([($entry['file'] ?? ''), ...($entry['css'] ?? []), ...($entry['assets'] ?? [])] as $file) {
    if (! is_string($file) || $file === '' || str_starts_with($file, '/') || in_array('..', explode('/', $file), true)
        || ! is_file($root.'/public/build/'.$file) || is_link($root.'/public/build/'.$file)) stopDeploy('Missing or unsafe frontend asset.');
}
// Validate the existing web PHP setup; tracked public bootstrap/config files stay unchanged.
$htaccess = file_get_contents($public.'/.htaccess');
$handlers = [];
preg_match_all('/^# php -- BEGIN cPanel-generated handler.*?^# php -- END cPanel-generated handler[^\r\n]*/ms', $htaccess, $matches);
foreach ($matches[0] as $block) {
    if (! preg_match('/(?:alt-php84|ea-php84|x-httpd-php84)/i', $block)) stopDeploy('The existing cPanel PHP handler is not PHP 8.4.');
    $handlers[] = $block;
}
if ($handlers === []) {
    preg_match_all('/<FilesMatch\b[^>]*>.*?<\/FilesMatch>/is', $htaccess, $matches);
    foreach ($matches[0] as $block) {
        if (preg_match('/^\s*SetHandler\s+application\/x-httpd-(?:alt-php84|ea-php84|php84)[^\r\n]*/mi', $block)) $handlers[] = $block;
    }
    preg_match_all('/^\s*AddHandler\s+application\/x-httpd-(?:alt-php84|ea-php84|php84)[^\r\n]*/mi', $htaccess, $matches);
    array_push($handlers, ...$matches[0]);
}
if ($handlers === []) {
    if (preg_match('/^\s*(?:AddHandler|SetHandler)\s+[^\r\n]*php/mi', $htaccess)) stopDeploy('An unrecognized explicit PHP handler requires review.');
    if (($values['LAVAGGIO_INHERITED_WEB_PHP84_VERIFIED'] ?? '') !== 'true') {
        stopDeploy('No explicit PHP 8.4 handler. Verify inherited web PHP 8.4 with a temporary HTTPS probe, remove it, then set LAVAGGIO_INHERITED_WEB_PHP84_VERIFIED=true in the private .env.');
    }
    echo "Web PHP: using the previously HTTPS-verified inherited PHP 8.4 configuration.\n";
}
echo "Private environment, database, migrations, frontend and PHP handler: OK (secrets hidden).\n";
LAVAGGIO_PREREQUISITES

# Standard Laravel media: real storage/app/public and a public/storage symlink to it.
[[ -d "$runtime_root/storage/app/public" && ! -L "$runtime_root/storage/app/public" ]] || fail 'Prepare the current native media in the real storage/app/public directory first.'
if [[ -e "$public_root/storage" || -L "$public_root/storage" ]]; then
    [[ -L "$public_root/storage" && "$(realpath "$public_root/storage")" == "$runtime_root/storage/app/public" ]] || fail 'public/storage must be absent or point to this project storage/app/public.'
fi
for relative in database bootstrap bootstrap/cache storage storage/app storage/app/public storage/framework storage/framework/cache storage/framework/cache/data storage/framework/sessions storage/framework/views storage/logs vendor; do
    [[ ! -L "$runtime_root/$relative" ]] || fail "Unexpected runtime symlink: $relative"
done

"$php_bin" "$composer_bin" check-platform-reqs --no-dev --lock --working-dir="$source_root"
printf '%s\n' "Mode: $([[ "$apply" == true ]] && printf APPLY || printf DRY-RUN)" "Revision: $revision"
du -sh -- "$runtime_root"
df -h -- "$runtime_root"
if [[ "$apply" == false ]]; then
    printf '%s\n' 'Plan: use the current project checkout, preserve .env/database/uploads, back up generated caches, install locked dependencies, create public/storage if absent, rebuild Laravel caches.' \
        'Plan only: no Composer install, Artisan boot, permissions changes, symlink creation, database/settings writes, migrations, seeds, key generation, mail/integration activation or cron changes.'
    exit 0
fi

[[ "$("$git_bin" -C "$source_root" rev-parse HEAD)" == "$revision" && -z "$("$git_bin" -C "$source_root" status --porcelain --untracked-files=normal)" ]] || fail 'The reviewed checkout changed while preparing deployment.'
[[ ! -L "$backup_base" && ( ! -e "$backup_base" || "$(realpath "$backup_base")" == "$backup_base" ) ]] || fail 'Unsafe private backup directory.'
mkdir -p "$backup_base"
chmod 700 "$backup_base"
exec 9>"$backup_base/deploy.lock"
flock -n 9 || fail 'Another Lavaggio deployment is running.'
backup_root="$(mktemp -d "$backup_base/$(date -u +%Y%m%dT%H%M%SZ)-${revision:0:12}-XXXXXX")"
mkdir "$backup_root/bootstrap-cache"
printf '%s\n' "$revision" > "$backup_root/revision"
[[ ! -f "$runtime_root/storage/app/private/.lavaggio-deploy-revision" ]] || cp -p "$runtime_root/storage/app/private/.lavaggio-deploy-revision" "$backup_root/previous-deployed-revision"
[[ ! -d "$runtime_root/storage/framework/views" ]] || cp -a "$runtime_root/storage/framework/views" "$backup_root/compiled-views"
[[ ! -d "$runtime_root/vendor/composer" ]] || cp -a "$runtime_root/vendor/composer" "$backup_root/vendor-composer"
mkdir -p "$runtime_root/bootstrap/cache" "$runtime_root/storage/framework/cache/data" "$runtime_root/storage/framework/sessions" "$runtime_root/storage/framework/views" "$runtime_root/storage/logs" "$runtime_root/storage/app/private"
# The web server must traverse the project to reach public/; private data remains owner-only.
chmod 755 "$runtime_root" "$public_root" "$runtime_root/storage" "$runtime_root/storage/app" "$runtime_root/storage/app/public"
chmod 700 "$runtime_root/.git" "$runtime_root/storage/app/private"
chmod 600 "$runtime_root/.env"
if [[ -e "$runtime_root/database/database.sqlite" || -L "$runtime_root/database/database.sqlite" ]]; then
    [[ -f "$runtime_root/database/database.sqlite" && ! -L "$runtime_root/database/database.sqlite" ]] || fail 'The retained SQLite snapshot must be a regular file.'
    chmod 600 "$runtime_root/database/database.sqlite"
fi
[[ -L "$public_root/storage" ]] || ln -s "$runtime_root/storage/app/public" "$public_root/storage"

cd "$runtime_root"
# Generated caches may contain paths from a previous installation.
for cache in bootstrap/cache/*.php; do
    [[ -e "$cache" || -L "$cache" ]] || continue
    [[ -f "$cache" && ! -L "$cache" ]] || fail 'Cached bootstrap files must be regular files.'
    cp -p "$cache" "$backup_root/bootstrap-cache/"
    rm -- "$cache"
done
"$php_bin" "$composer_bin" install --no-dev --prefer-dist --no-interaction --optimize-autoloader --no-scripts
"$php_bin" "$composer_bin" check-platform-reqs --no-dev
"$php_bin" artisan package:discover --ansi
"$php_bin" artisan config:cache
"$php_bin" artisan route:cache
"$php_bin" artisan view:cache

[[ "$("$git_bin" -C "$source_root" rev-parse HEAD)" == "$revision" && -z "$("$git_bin" -C "$source_root" status --porcelain --untracked-files=normal)" ]] || fail 'The checkout changed or a runtime file is not ignored; review before continuing.'
printf '%s\n' "$revision" > "$runtime_root/storage/app/private/.lavaggio-deploy-revision"
printf '%s\n' "Lavaggio deployed in its project checkout: $revision" "Private generated-cache backup: $backup_root" \
    'The standard tracked index.php/.htaccess/.user.ini, existing .env, database, media and local hosting files were preserved.' \
    'No migrations, seeds, key rotation, mail/integration activation or cron changes were performed.'
