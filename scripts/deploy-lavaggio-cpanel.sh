#!/usr/bin/env bash
# cPanel pull deployment for the isolated Lavaggio shop preview.
# Without --apply this only validates prerequisites and shows a copy plan.
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
source_root=/home/baredahr/repositories/lavaggio
runtime_root=/home/baredahr/apps/lavaggio-shop
public_root=/home/baredahr/shop.lavaggio.hr
php_bin=/opt/alt/php84/usr/bin/php
backup_base=/home/baredahr/.lavaggio-shop-backups
work_dir=''
backup_root=''
fail() { printf '%s\n' "Lavaggio deployment stopped: $*" >&2; exit 1; }
cleanup() { [[ -z "$work_dir" ]] || rm -rf -- "$work_dir"; }
trap cleanup EXIT
trap 'printf "%s\n" "Deployment failed. Review the private backup: ${backup_root:-not created}. No database restore or migration was attempted." >&2' ERR

[[ "$(id -un)" == baredahr && "${HOME:-}" == "$account_root" ]] || fail 'Run as the baredahr account with its normal HOME.'
for tool in rsync realpath stat du df flock; do command -v "$tool" >/dev/null || fail "Required tool is unavailable: $tool"; done
git_bin="$(command -v git || true)"
[[ -n "$git_bin" ]] || git_bin=/usr/local/cpanel/3rdparty/lib/path-bin/git
[[ -x "$git_bin" ]] || fail 'Git is unavailable.'
[[ -x "$php_bin" ]] || fail 'The verified PHP 8.4 CLI is unavailable.'
for directory in "$account_root" "$source_root" "$runtime_root" "$public_root"; do
    [[ -d "$directory" && ! -L "$directory" && "$(realpath "$directory")" == "$directory" ]] || fail "Unsafe or missing fixed directory: $directory"
    [[ "$(stat -c '%U' "$directory")" == baredahr ]] || fail "Unexpected directory owner: $directory"
done
[[ "$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd -P)" == "$source_root" ]] || fail 'Use the script from the registered Lavaggio checkout.'
[[ "$("$git_bin" -C "$source_root" rev-parse --show-toplevel)" == "$source_root" ]] || fail 'The source must be the exact registered checkout.'
[[ "$("$git_bin" -C "$source_root" branch --show-current)" == main ]] || fail 'The checkout must be on main.'
case "$("$git_bin" -C "$source_root" remote get-url origin)" in
    https://github.com/tomashina/lavaggio.git|git@github.com:tomashina/lavaggio.git|ssh://git@github.com/tomashina/lavaggio.git) ;;
    *) fail 'Unexpected source Git origin.' ;;
esac
[[ -z "$("$git_bin" -C "$source_root" status --porcelain --untracked-files=normal)" ]] || fail 'The cPanel checkout must be clean; this script never pulls or resets Git.'
revision="$("$git_bin" -C "$source_root" rev-parse --verify HEAD)"
[[ -f "$source_root/public/build/manifest.json" && -f "$source_root/composer.lock" ]] || fail 'The committed frontend build and Composer lock are required.'
[[ -f "$runtime_root/.env" && ! -L "$runtime_root/.env" && -s "$runtime_root/database/database.sqlite" && ! -L "$runtime_root/database/database.sqlite" ]] || fail 'Prepare the private .env and separate current SQLite database before deployment.'
[[ -f "$public_root/.htaccess" && ! -L "$public_root/.htaccess" ]] || fail 'The existing domain .htaccess is required.'
[[ ! -L "$public_root/.user.ini" ]] || fail 'The domain .user.ini must not be a symlink.'

composer_bin=''
composer_from_path="$(command -v composer || true)"
for candidate in /home/baredahr/.local/bin/composer.phar "$composer_from_path" /opt/cpanel/composer/bin/composer /usr/local/bin/composer /usr/bin/composer /bin/composer; do
    if [[ -n "$candidate" && -f "$candidate" && -r "$candidate" ]]; then composer_bin="$candidate"; break; fi
done
[[ -n "$composer_bin" ]] || fail 'Install/configure Composer on the server first; this script does not download executables.'

work_dir="$(mktemp -d /tmp/lavaggio-deploy.XXXXXX)"
mkdir "$work_dir/public-files"
# Validate without booting Laravel, loading old caches, printing secrets or writing SQLite.
"$php_bin" /dev/stdin "$runtime_root" "$source_root" "$public_root" "$work_dir" <<'LAVAGGIO_PREREQUISITES'
<?php
function stopDeploy(string $message): never { fwrite(STDERR, "Lavaggio prerequisite failed: ".$message."\n"); exit(1); }
[$script, $root, $source, $public, $work] = $argv;
if (PHP_VERSION_ID < 80400 || PHP_VERSION_ID >= 80500) stopDeploy('PHP 8.4 is required.');
$bootstrapKeys = ['APP_CONFIG_CACHE', 'APP_BASE_PATH', 'APP_ENV_PATH', 'APP_SERVICES_CACHE', 'APP_PACKAGES_CACHE', 'APP_ROUTES_CACHE', 'APP_EVENTS_CACHE', 'VIEW_COMPILED_PATH'];
$required = ['APP_ENV'=>'staging', 'APP_URL'=>'https://shop.lavaggio.hr', 'APP_DEBUG'=>'false',
    'HERRERA_LOCAL_SAFE_MODE'=>'true', 'MAIL_MAILER'=>'log', 'QUEUE_CONNECTION'=>'sync',
    'DB_CONNECTION'=>'sqlite', 'DB_DATABASE'=>$root.'/database/database.sqlite',
    'SESSION_DRIVER'=>'file', 'SESSION_COOKIE'=>'lavaggio_shop_session', 'SESSION_SECURE_COOKIE'=>'true',
    'CACHE_STORE'=>'file', 'STOREFRONT_HOME'=>'lavaggio', 'STORE_B2C_ONLY'=>'true', 'STORE_B2B_ONLY'=>'false',
    'GIFT_VOUCHER_EMAILS_ENABLED'=>'false', 'STOCK_NOTIFICATIONS_EMAILS_ENABLED'=>'false'];
$watched = array_fill_keys([...array_keys($required), ...$bootstrapKeys, 'APP_KEY', 'DB_URL', 'SESSION_DOMAIN', 'SESSION_CONNECTION', 'DB_CACHE_CONNECTION', 'LAVAGGIO_INHERITED_WEB_PHP84_VERIFIED'], true);
$values = [];
foreach (file($root.'/.env', FILE_IGNORE_NEW_LINES) as $line) {
    if (! preg_match('/^\s*(?:export\s+)?([A-Za-z_][A-Za-z0-9_]*)\s*=(.*)$/', $line, $match) || ! isset($watched[$match[1]])) continue;
    $key = $match[1];
    if (array_key_exists($key, $values)) stopDeploy('A protected environment key is duplicated.');
    $value = trim($match[2]);
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
foreach (['DB_URL', 'SESSION_DOMAIN', 'SESSION_CONNECTION', 'DB_CACHE_CONNECTION'] as $key) {
    if (! in_array(strtolower($values[$key] ?? ''), ['', 'null', '(null)'], true)) stopDeploy('Shared connection/domain overrides are not allowed: '.$key);
}
foreach ($watched as $key=>$_) {
    $inherited = getenv($key);
    if ($inherited !== false && $inherited !== ($values[$key] ?? '')) stopDeploy('Inherited environment conflicts with .env: '.$key);
}
$key = $values['APP_KEY'] ?? '';
$key = str_starts_with($key, 'base64:') ? base64_decode(substr($key, 7), true) : $key;
if (! is_string($key) || strlen($key) !== 32) stopDeploy('Preserve the current valid application key; this script never generates one.');
$database = $root.'/database/database.sqlite';
if (realpath($database) !== $database || (stat($database)['nlink'] ?? 0) !== 1) stopDeploy('SQLite must be a separate regular file.');
$pdo = new PDO('sqlite:file:'.$database.'?mode=ro', options: [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
$pdo->exec('PRAGMA query_only=ON');
$ran = $pdo->query('SELECT migration FROM migrations')->fetchAll(PDO::FETCH_COLUMN);
$pending = array_values(array_diff(array_map(fn ($file)=>basename($file, '.php'), glob($source.'/database/migrations/*.php')), $ran));
if ($pending !== []) stopDeploy("Pending migrations require separate review; none were run:\n".implode("\n", $pending));
$settings = [];
foreach ($pdo->query("SELECT key,value FROM system_settings WHERE key IN ('store_email_enabled','gls_api_enabled','watchline_integrations')") as $row) {
    $settings[$row['key']] = json_decode($row['value'], true, flags: JSON_THROW_ON_ERROR);
}
if (filter_var($settings['store_email_enabled'] ?? false, FILTER_VALIDATE_BOOLEAN)) stopDeploy('Mail must remain disabled for this preview.');
if (filter_var($settings['gls_api_enabled'] ?? false, FILTER_VALIDATE_BOOLEAN)) stopDeploy('GLS API must remain disabled for this preview.');
$integrations = $settings['watchline_integrations'] ?? [];
if (filter_var($integrations['live_enabled'] ?? false, FILTER_VALIDATE_BOOLEAN)) stopDeploy('Inherited integrations must remain disabled.');
foreach ($integrations['schedules'] ?? [] as $schedule) if (filter_var($schedule['enabled'] ?? false, FILTER_VALIDATE_BOOLEAN)) stopDeploy('Inherited integration schedules must remain disabled.');
$manifest = json_decode(file_get_contents($source.'/public/build/manifest.json'), true, flags: JSON_THROW_ON_ERROR);
if (! is_array($manifest) || $manifest === []) stopDeploy('Missing frontend manifest entries.');
foreach ($manifest as $entry) foreach ([($entry['file'] ?? ''), ...($entry['css'] ?? []), ...($entry['assets'] ?? [])] as $file) {
    if (! is_string($file) || $file === '' || str_starts_with($file, '/') || in_array('..', explode('/', $file), true)
        || ! is_file($source.'/public/build/'.$file) || is_link($source.'/public/build/'.$file)) stopDeploy('Missing or unsafe frontend asset.');
}
// Retain cPanel's PHP configuration without copying old application routing rules.
$htaccess = file_get_contents($public.'/.htaccess');
$phpConfiguration = [];
preg_match_all('/^# BEGIN cPanel-generated php ini directives.*?^# END cPanel-generated php ini directives[^\r\n]*/ms', $htaccess, $matches);
array_push($phpConfiguration, ...$matches[0]);
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
if (preg_match('/^\s*AuthType\s+/mi', $htaccess)) stopDeploy('Existing directory authentication requires explicit preservation before using this script.');
file_put_contents($work.'/php-handler', implode("\n", array_unique([...$phpConfiguration, ...$handlers]))."\n");
echo "Private environment, database, migrations, frontend and PHP handler: OK (secrets hidden).\n";
LAVAGGIO_PREREQUISITES

# Native media stays physically in the document root, with private runtime links pointing outward.
if [[ -e "$runtime_root/public" || -L "$runtime_root/public" ]]; then
    [[ -L "$runtime_root/public" && "$(readlink "$runtime_root/public")" == "$public_root" ]] || fail 'runtime/public must be absent or the exact document-root symlink.'
fi
[[ -d "$public_root/storage" && ! -L "$public_root/storage" ]] || fail 'Extract the current public-media archive into the real document-root storage directory first.'
if [[ -e "$runtime_root/storage/app/public" || -L "$runtime_root/storage/app/public" ]]; then
    [[ -L "$runtime_root/storage/app/public" && "$(readlink "$runtime_root/storage/app/public")" == "$public_root/storage" ]] || fail 'Preserve/move native media first; runtime/storage/app/public must be absent or the exact outward symlink.'
fi
for relative in database bootstrap bootstrap/cache storage storage/app storage/framework storage/framework/cache storage/framework/cache/data storage/framework/sessions storage/framework/views storage/logs vendor; do
    [[ ! -L "$runtime_root/$relative" ]] || fail "Unexpected runtime symlink: $relative"
done

: > "$work_dir/runtime.list"
: > "$work_dir/public.list"
"$git_bin" -C "$source_root" ls-files -z > "$work_dir/tracked.list"
while IFS= read -r -d '' file; do
    case "$file" in
        public/index.php|public/.htaccess|public/.user.ini|public/storage|public/storage/*|public/image/*|public/cache/*|public/hot|public/.well-known/*|public/cgi-bin/*) continue ;;
        public/*) relative="${file#public/}"; destination="$public_root"; list="$work_dir/public.list" ;;
        app/*|bootstrap/app.php|bootstrap/providers.php|config/*|routes/*|resources/*|lang/*|database/migrations/*|artisan|composer.json|composer.lock) relative="$file"; destination="$runtime_root"; list="$work_dir/runtime.list" ;;
        *) continue ;;
    esac
    [[ -f "$source_root/$file" && ! -L "$source_root/$file" ]] || fail "Unsupported tracked file: $file"
    IFS=/ read -r -a components <<< "$relative"
    for component in "${components[@]}"; do
        destination="$destination/$component"
        [[ ! -L "$destination" ]] || fail "Refusing to overwrite a destination symlink: $relative"
    done
    printf '%s\0' "$relative" >> "$list"
done < "$work_dir/tracked.list"

"$php_bin" "$composer_bin" check-platform-reqs --no-dev --lock --working-dir="$source_root"
printf '%s\n' "Mode: $([[ "$apply" == true ]] && printf APPLY || printf DRY-RUN)" "Revision: $revision"
du -sh -- "$runtime_root" "$public_root"
df -h -- "$runtime_root" "$public_root"
rsync_options=(-a --no-owner --no-group --checksum --from0 --stats --chmod=Du=rwx,Dgo=rx,Fu=rw,Fgo=r)
if [[ "$apply" == false ]]; then
    rsync "${rsync_options[@]}" --dry-run --files-from="$work_dir/runtime.list" "$source_root/" "$runtime_root/"
    rsync "${rsync_options[@]}" --dry-run --files-from="$work_dir/public.list" "$source_root/public/" "$public_root/"
    printf '%s\n' 'Plan only: no deployment, Composer install, Artisan boot, database/settings writes, migrations, seeds, key generation, mail/integration activation or cron changes.'
    exit 0
fi

[[ "$("$git_bin" -C "$source_root" rev-parse HEAD)" == "$revision" && -z "$("$git_bin" -C "$source_root" status --porcelain --untracked-files=normal)" ]] || fail 'The reviewed checkout changed while preparing deployment.'
[[ ! -L "$backup_base" && ( ! -e "$backup_base" || "$(realpath "$backup_base")" == "$backup_base" ) ]] || fail 'Unsafe private backup directory.'
mkdir -p "$backup_base"
chmod 700 "$backup_base" "$runtime_root"
exec 9>"$backup_base/deploy.lock"
flock -n 9 || fail 'Another Lavaggio deployment is running.'
backup_root="$(mktemp -d "$backup_base/$(date -u +%Y%m%dT%H%M%SZ)-${revision:0:12}-XXXXXX")"
mkdir "$backup_root/runtime" "$backup_root/public" "$backup_root/bootstrap-cache"
printf '%s\n' "$revision" > "$backup_root/revision"
for file in index.php .htaccess .user.ini; do
    [[ ! -f "$public_root/$file" ]] || cp -p "$public_root/$file" "$backup_root/public/$file"
done
mkdir -p "$runtime_root/bootstrap/cache" "$runtime_root/storage/framework/cache/data" "$runtime_root/storage/framework/sessions" "$runtime_root/storage/framework/views" "$runtime_root/storage/logs" "$runtime_root/storage/app/private"
[[ -L "$runtime_root/public" ]] || ln -s "$public_root" "$runtime_root/public"
[[ -L "$runtime_root/storage/app/public" ]] || ln -s "$public_root/storage" "$runtime_root/storage/app/public"
# Copy only explicitly selected Git-tracked code/assets; preserve uploads and every unlisted file.
rsync "${rsync_options[@]}" --backup --backup-dir="$backup_root/runtime" --files-from="$work_dir/runtime.list" "$source_root/" "$runtime_root/"
rsync "${rsync_options[@]}" --backup --backup-dir="$backup_root/public" --files-from="$work_dir/public.list" "$source_root/public/" "$public_root/"
chmod 700 "$runtime_root"

cd "$runtime_root"
# Old absolute cache paths must never bootstrap the new runtime.
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

cat > "$work_dir/public-files/index.php" <<'LAVAGGIO_PUBLIC_INDEX'
<?php
use Illuminate\Foundation\Application;
use Illuminate\Http\Request;
define('LARAVEL_START', microtime(true));
$lavaggioRuntime = '/home/baredahr/apps/lavaggio-shop';
if (file_exists($maintenance = $lavaggioRuntime.'/storage/framework/maintenance.php')) require $maintenance;
require $lavaggioRuntime.'/vendor/autoload.php';
/** @var Application $app */
$app = require_once $lavaggioRuntime.'/bootstrap/app.php';
$app->usePublicPath('/home/baredahr/shop.lavaggio.hr');
$app->handleRequest(Request::capture());
LAVAGGIO_PUBLIC_INDEX
cat "$source_root/public/.htaccess" > "$work_dir/public-files/.htaccess"
printf '\n# Preserve the domain-specific cPanel PHP configuration.\n' >> "$work_dir/public-files/.htaccess"
cat "$work_dir/php-handler" >> "$work_dir/public-files/.htaccess"
"$php_bin" -l "$work_dir/public-files/index.php"
[[ "$("$git_bin" -C "$source_root" rev-parse HEAD)" == "$revision" && -z "$("$git_bin" -C "$source_root" status --porcelain --untracked-files=normal)" ]] || fail 'The source changed during deployment; wrapper not published.'
rsync -a --no-owner --no-group --checksum --chmod=Du=rwx,Dgo=rx,Fu=rw,Fgo=r --backup --backup-dir="$backup_root/public" "$work_dir/public-files/" "$public_root/"
printf '%s\n' "$revision" > "$runtime_root/.lavaggio-deploy-revision"
printf '%s\n' "Lavaggio deployed: $revision" "Private changed-file backup: $backup_root" \
    'The existing .env, SQLite data, media, .user.ini and .well-known were preserved.' \
    'No migrations, seeds, key rotation, mail/integration activation or cron changes were performed.'
