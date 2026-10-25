#!/usr/bin/env bash
# Deploy only the isolated Watchline staging account. Default: read-only plan.
set -Eeuo pipefail
umask 077

apply=false
case "${1:-}" in
    '') ;;
    --apply) apply=true ;;
    --help|-h) printf '%s\n' 'Usage: bash scripts/deploy-watchline-staging.sh [--apply]' 'Without --apply, validate and show a copy plan only.'; exit 0 ;;
    *) printf '%s\n' 'Unsupported argument. Only --apply or --help is accepted.' >&2; exit 1 ;;
esac
[[ $# -le 1 ]] || { printf '%s\n' 'Too many arguments.' >&2; exit 1; }

account_root=/home/watchline
source_root=/home/watchline/repositories/watchline-new
runtime_root=/home/watchline/apps/watchline-staging
public_root=/home/watchline/new.watchline.hr
php_bin=/opt/alt/php84/usr/bin/php
composer_phar=/home/watchline/.local/bin/composer-2.10.3.phar
backup_base=/home/watchline/.watchline-staging-backups
work_dir=''
backup_root=''
fail() { printf '%s\n' "Staging deployment stopped: $*" >&2; exit 1; }
cleanup() { [[ -z "$work_dir" ]] || rm -rf -- "$work_dir"; }
trap cleanup EXIT
trap 'printf "%s\n" "Deployment failed. Keep staging protected; inspect the private backup: ${backup_root:-not created}. No automatic database restore was attempted." >&2' ERR

[[ "$(id -un)" == watchline && "${HOME:-}" == "$account_root" ]] || fail 'Run as the watchline account with its normal HOME.'
for tool in git rsync realpath stat du df flock; do command -v "$tool" >/dev/null || fail "Required tool is unavailable: $tool"; done
[[ -x "$php_bin" && -f "$composer_phar" && ! -L "$composer_phar" ]] || fail 'The verified private Composer PHAR and PHP 8.4 CLI are required.'
for directory in "$account_root" "$source_root" "$runtime_root" "$public_root"; do
    [[ -d "$directory" && ! -L "$directory" && "$(realpath "$directory")" == "$directory" ]] || fail "Unsafe or missing fixed directory: $directory"
    [[ "$(stat -c '%U' "$directory")" == watchline ]] || fail "Unexpected directory owner: $directory"
done
[[ "$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd -P)" == "$source_root" ]] || fail 'Run the script from the registered Watchline Git checkout.'
[[ "$(git -C "$source_root" rev-parse --show-toplevel)" == "$source_root" ]] || fail 'The source must be the exact registered checkout.'
[[ "$(git -C "$source_root" branch --show-current)" == main ]] || fail 'The checkout must be on main, not a detached revision or another branch.'
case "$(git -C "$source_root" remote get-url origin)" in
    https://github.com/tomashina/watchline-new.git|git@github.com:tomashina/watchline-new.git|ssh://git@github.com/tomashina/watchline-new.git) ;;
    *) fail 'Unexpected source Git origin.' ;;
esac
[[ -z "$(git -C "$source_root" status --porcelain --untracked-files=normal)" ]] || fail 'Commit/stash checkout changes before deploying; this script never pulls or resets Git.'
revision="$(git -C "$source_root" rev-parse --verify HEAD)"
[[ -f "$public_root/.htaccess" && ! -L "$public_root/.htaccess" ]] || fail 'The existing reviewed domain PHP handler is required.'
grep -Eq '^[[:space:]]*SetHandler[[:space:]]+application/x-httpd-alt-php84___lsphp[[:space:]]*$' "$public_root/.htaccess" || fail 'The domain must already use the validated alt-PHP 8.4 LiteSpeed handler.'
if ! grep -Eqi '^[[:space:]]*AuthType[[:space:]]+' "$public_root/.htaccess"; then
    printf '%s\n' 'Access protection: MISSING. Enable cPanel Directory Privacy before --apply; dry-run does not create credentials.'
    [[ "$apply" == false ]] || fail 'Publishing a real-data staging shop requires existing verified BasicAuth protection.'
fi
[[ -f "$runtime_root/.env" && ! -L "$runtime_root/.env" && -s "$runtime_root/database/database.sqlite" && ! -L "$runtime_root/database/database.sqlite" ]] || fail 'Prepare the private staging .env and a separate existing SQLite database first.'
[[ -f "$source_root/public/build/manifest.json" && -f "$source_root/composer.lock" ]] || fail 'Commit a frontend build and Composer lock before deployment.'

# This check does not boot Laravel, print .env values, write SQLite, or load old cached config.
"$php_bin" /dev/stdin "$runtime_root" <<'WATCHLINE_ENV_CHECK'
<?php
function stopDeploy(string $message): never { fwrite(STDERR, "Staging prerequisite failed: ".$message."\n"); exit(1); }
$root = $argv[1];
if (PHP_VERSION_ID < 80400 || PHP_VERSION_ID >= 90000) stopDeploy('PHP 8.4+ is required.');
$bootstrapKeys = ['APP_CONFIG_CACHE', 'APP_BASE_PATH', 'APP_ENV_PATH', 'APP_SERVICES_CACHE', 'APP_PACKAGES_CACHE', 'APP_ROUTES_CACHE', 'APP_EVENTS_CACHE', 'VIEW_COMPILED_PATH'];
foreach ($bootstrapKeys as $key) {
    if (getenv($key) !== false && getenv($key) !== '') stopDeploy('Custom bootstrap/cache environment paths are not permitted.');
}
$required = ['APP_ENV'=>'staging', 'APP_URL'=>'https://new.watchline.hr', 'APP_DEBUG'=>'false',
    'HERRERA_LOCAL_SAFE_MODE'=>'true', 'MAIL_MAILER'=>'log', 'QUEUE_CONNECTION'=>'sync',
    'DB_CONNECTION'=>'sqlite', 'DB_DATABASE'=>$root.'/database/database.sqlite',
    'SESSION_DRIVER'=>'file', 'SESSION_COOKIE'=>'watchline_staging_session', 'SESSION_SECURE_COOKIE'=>'true',
    'CACHE_STORE'=>'file', 'STOREFRONT_HOME'=>'watchline', 'STORE_B2C_ONLY'=>'true',
    'GIFT_VOUCHER_EMAILS_ENABLED'=>'false', 'STOCK_NOTIFICATIONS_EMAILS_ENABLED'=>'false'];
$watched = array_fill_keys([...array_keys($required), ...$bootstrapKeys, 'APP_KEY', 'DB_URL', 'SESSION_DOMAIN', 'SESSION_CONNECTION', 'DB_CACHE_CONNECTION'], true);
$values = [];
foreach (file($root.'/.env', FILE_IGNORE_NEW_LINES) as $line) {
    if (! preg_match('/^\s*(?:export\s+)?([A-Za-z_][A-Za-z0-9_]*)\s*=(.*)$/', $line, $match)) continue;
    $key = $match[1];
    if (! isset($watched[$key])) continue;
    if (array_key_exists($key, $values)) stopDeploy('A protected environment key is duplicated.');
    $value = trim($match[2]);
    if ($value !== '' && ($value[0] === '"' || $value[0] === "'")) {
        if (! preg_match('/\A([\x22\x27])(.*)\1(?:\s+#.*)?\z/', $value, $quoted)) stopDeploy('A protected environment value has unsupported quoting.');
        $value = $quoted[2];
    } else {
        $value = trim(preg_replace('/\s+#.*$/', '', $value));
    }
    if (str_contains($value, '$') || str_contains($value, '\\')) stopDeploy('Protected environment values must be explicit literals.');
    $values[$key] = $value;
}
foreach ($required as $key=>$expected) {
    if (($values[$key] ?? null) !== $expected) stopDeploy('Required staging setting is missing or unsafe: '.$key);
}
foreach ($bootstrapKeys as $key) {
    if (($values[$key] ?? '') !== '') stopDeploy('Custom bootstrap/cache paths are not permitted: '.$key);
}
foreach (['DB_URL', 'SESSION_DOMAIN', 'SESSION_CONNECTION', 'DB_CACHE_CONNECTION'] as $key) {
    if (! in_array(strtolower($values[$key] ?? ''), ['', 'null', '(null)'], true)) stopDeploy('Shared connection/domain overrides are not permitted: '.$key);
}
foreach ($watched as $key=>$_) {
    $inherited = getenv($key);
    if ($inherited !== false && $inherited !== ($values[$key] ?? '')) stopDeploy('Inherited environment conflicts with the private staging file: '.$key);
}
$key = $values['APP_KEY'] ?? '';
$key = str_starts_with($key, 'base64:') ? base64_decode(substr($key, 7), true) : $key;
if (! is_string($key) || strlen($key) !== 32) stopDeploy('Preserve the existing valid application encryption key; no key is generated.');
$file = $root.'/database/database.sqlite';
if (realpath($file) !== $file || (stat($file)['nlink'] ?? 0) !== 1) stopDeploy('The database must be a separate regular file, not an alias.');
$pdo = new PDO('sqlite:file:'.$file.'?mode=ro', options: [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
$pdo->exec('PRAGMA query_only=ON');
$statement = $pdo->query("SELECT key,value FROM system_settings WHERE key IN ('store_email_enabled','watchline_integrations','gls_api_enabled')");
$settings = [];
foreach ($statement as $row) $settings[$row['key']] = json_decode($row['value'], true, flags: JSON_THROW_ON_ERROR);
if (filter_var($settings['store_email_enabled'] ?? false, FILTER_VALIDATE_BOOLEAN)) stopDeploy('Store email delivery must be disabled in the staging database.');
if (filter_var($settings['gls_api_enabled'] ?? false, FILTER_VALIDATE_BOOLEAN)) stopDeploy('GLS API delivery must stay disabled in staging.');
$integrations = $settings['watchline_integrations'] ?? [];
if (filter_var($integrations['live_enabled'] ?? false, FILTER_VALIDATE_BOOLEAN)) stopDeploy('The integration master switch must be disabled.');
foreach ($integrations['schedules'] ?? [] as $schedule) {
    if (filter_var($schedule['enabled'] ?? false, FILTER_VALIDATE_BOOLEAN)) stopDeploy('Every integration schedule must be disabled.');
}
if ($pdo->query("SELECT COUNT(*) FROM payment_methods WHERE is_active=1 AND lower(code) IN ('corvus','corvuspay','corvus_pay')")->fetchColumn() > 0) stopDeploy('Inherited Corvus payment must stay inactive in staging.');
echo "Private environment/database checks: OK (values hidden).\n";
WATCHLINE_ENV_CHECK

work_dir="$(mktemp -d /tmp/watchline-staging-plan.XXXXXX)"
mkdir "$work_dir/public-files"
: > "$work_dir/directory-privacy"
if grep -Eqi '^[[:space:]]*AuthType[[:space:]]+' "$public_root/.htaccess"; then
    grep -Eqi '^[[:space:]]*AuthType[[:space:]]+Basic[[:space:]]*$' "$public_root/.htaccess" || fail 'Review the non-Basic directory access policy explicitly before deployment.'
    # Preserve cPanel Directory Privacy without lifting unrelated <Files> restrictions globally.
    awk 'tolower($0) ~ /^[[:space:]]*auth(type|name|userfile|groupfile|basicprovider)[[:space:]]/ || tolower($0) ~ /^[[:space:]]*require[[:space:]]+(valid-user|user|group)([[:space:]]|$)/ { print }' "$public_root/.htaccess" > "$work_dir/directory-privacy"
    grep -Eqi '^[[:space:]]*AuthUserFile[[:space:]]+' "$work_dir/directory-privacy" || fail 'The existing BasicAuth password-file directive is missing.'
    grep -Eqi '^[[:space:]]*Require[[:space:]]+(valid-user|user|group)([[:space:]]|$)' "$work_dir/directory-privacy" || fail 'The existing BasicAuth access requirement is missing.'
    printf '%s\n' 'Access protection: existing BasicAuth directives validated and preserved.'
fi
: > "$work_dir/runtime.list"
: > "$work_dir/public.list"
runtime_bytes=0
public_bytes=0
runtime_count=0
public_count=0
# Git tracked files only. No source images, ignored snapshots, private files or native uploads.
git -C "$source_root" ls-files -z > "$work_dir/tracked.list"
while IFS= read -r -d '' file; do
    case "$file" in
        .git*|.env*|.local/*|.agents/*|.codex/*|.cpanel.yml|auth.json|node_modules/*|vendor/*|storage/*|tests/*|docs/*|bootstrap/cache/*|database/database.sqlite*|database/sql/*|public/image/*|public/cache/*|public/storage|public/storage/*|public/hot|image/*|temp/*|tmp/*|output/*|outputs/*|*.sql|*.sql.gz) continue ;;
    esac
    [[ -f "$source_root/$file" && ! -L "$source_root/$file" ]] || fail "Unsupported tracked file: $file"
    case "$file" in
        public/index.php|public/.htaccess|public/.user.ini) continue ;;
        public/assets/categories/generated/originals/*|public/*.map|public/*/README*|public/*/*.md) continue ;;
        public/*) relative="${file#public/}"; destination="$public_root"; printf '%s\0' "$relative" >> "$work_dir/public.list"; public_bytes=$((public_bytes + $(stat -c '%s' "$source_root/$file"))); public_count=$((public_count + 1)) ;;
        *) relative="$file"; destination="$runtime_root"; printf '%s\0' "$relative" >> "$work_dir/runtime.list"; runtime_bytes=$((runtime_bytes + $(stat -c '%s' "$source_root/$file"))); runtime_count=$((runtime_count + 1)) ;;
    esac
    IFS=/ read -r -a components <<< "$relative"
    for component in "${components[@]}"; do
        destination="$destination/$component"
        [[ ! -L "$destination" ]] || fail "Refusing to follow a deployment destination symlink: $relative"
    done
done < "$work_dir/tracked.list"

# Native media is physically public; no Apache traversal into the private runtime is required.
if [[ -e "$runtime_root/public" || -L "$runtime_root/public" ]]; then
    [[ -L "$runtime_root/public" && "$(readlink "$runtime_root/public")" == "$public_root" ]] || fail 'runtime/public must be absent or the exact approved docroot symlink.'
fi
[[ ! -L "$public_root/storage" && ( ! -e "$public_root/storage" || -d "$public_root/storage" ) ]] || fail 'Public native storage must be a real directory.'
if [[ -e "$runtime_root/storage/app/public" || -L "$runtime_root/storage/app/public" ]]; then
    [[ -L "$runtime_root/storage/app/public" && "$(readlink "$runtime_root/storage/app/public")" == "$public_root/storage" ]] || fail 'Preserve/move existing native media explicitly first; storage/app/public must be absent or the approved outward symlink.'
fi
for relative in database bootstrap bootstrap/cache storage storage/app storage/framework storage/framework/cache storage/framework/cache/data storage/framework/sessions storage/framework/views storage/logs vendor; do
    [[ ! -L "$runtime_root/$relative" ]] || fail "Unexpected runtime directory symlink: $relative"
done

"$php_bin" /dev/stdin "$source_root" <<'WATCHLINE_BUILD_CHECK'
<?php
$root = $argv[1];
$manifest = json_decode(file_get_contents($root.'/public/build/manifest.json'), true, flags: JSON_THROW_ON_ERROR);
if (! is_array($manifest) || $manifest === []) exit(1);
foreach ($manifest as $entry) {
    foreach ([($entry['file'] ?? ''), ...($entry['css'] ?? []), ...($entry['assets'] ?? [])] as $file) {
        if (! is_string($file) || $file === '' || str_starts_with($file, '/') || in_array('..', explode('/', $file), true)
            || ! is_file($root.'/public/build/'.$file) || is_link($root.'/public/build/'.$file)) { fwrite(STDERR, "Missing or unsafe committed frontend asset.\n"); exit(1); }
    }
}
WATCHLINE_BUILD_CHECK

printf '%s\n' "Mode: $([[ "$apply" == true ]] && printf APPLY || printf DRY-RUN)" "Revision: $revision" \
    "Runtime tracked files: $runtime_count; bytes: $runtime_bytes" "Public tracked files: $public_count; bytes: $public_bytes" \
    'Original product images and native uploads: excluded; no public --delete.'
du -sh -- "$runtime_root" "$public_root"
df -h -- "$runtime_root" "$public_root"
"$php_bin" "$composer_phar" check-platform-reqs --no-dev --lock --working-dir="$source_root"
rsync_options=(-a --no-owner --no-group --checksum --from0 --stats --chmod=Du=rwx,Dgo=rx,Fu=rw,Fgo=r)
if [[ "$apply" == false ]]; then
    rsync "${rsync_options[@]}" --dry-run --files-from="$work_dir/runtime.list" "$source_root/" "$runtime_root/"
    rsync "${rsync_options[@]}" --dry-run --files-from="$work_dir/public.list" "$source_root/public/" "$public_root/"
    printf '%s\n' 'Plan only: no deployment, Composer install, Artisan boot, settings changes, imports, migrations, key generation or cron changes occurred.'
    exit 0
fi

[[ "$(git -C "$source_root" rev-parse HEAD)" == "$revision" && -z "$(git -C "$source_root" status --porcelain --untracked-files=normal)" ]] || fail 'The reviewed checkout changed while preparing the plan.'
[[ ! -L "$backup_base" && ( ! -e "$backup_base" || "$(realpath "$backup_base")" == "$backup_base" ) ]] || fail 'Unsafe private backup directory.'
mkdir -p "$backup_base"
chmod 700 "$backup_base" "$runtime_root"
exec 9>"$backup_base/deploy.lock"
flock -n 9 || fail 'Another staging deployment is running.'
backup_root="$(mktemp -d "$backup_base/$(date -u +%Y%m%dT%H%M%SZ)-${revision:0:12}-XXXXXX")"
mkdir "$backup_root/runtime" "$backup_root/public"
printf '%s\n' "$revision" > "$backup_root/revision"
for file in index.php .htaccess .user.ini; do
    [[ ! -f "$public_root/$file" ]] || cp -p "$public_root/$file" "$backup_root/public/$file"
done
chmod 600 "$runtime_root/.env" "$runtime_root/database/database.sqlite"
mkdir -p "$runtime_root/bootstrap/cache" "$runtime_root/storage/framework/cache/data" "$runtime_root/storage/framework/sessions" "$runtime_root/storage/framework/views" "$runtime_root/storage/logs" "$runtime_root/storage/app" "$public_root/storage"
chmod 755 "$public_root" "$public_root/storage"
[[ -L "$runtime_root/public" ]] || ln -s "$public_root" "$runtime_root/public"
[[ -L "$runtime_root/storage/app/public" ]] || ln -s "$public_root/storage" "$runtime_root/storage/app/public"
rsync "${rsync_options[@]}" --backup --backup-dir="$backup_root/runtime" --files-from="$work_dir/runtime.list" "$source_root/" "$runtime_root/"
rsync "${rsync_options[@]}" --backup --backup-dir="$backup_root/public" --files-from="$work_dir/public.list" "$source_root/public/" "$public_root/"
chmod 700 "$runtime_root"
[[ "$(git -C "$source_root" rev-parse HEAD)" == "$revision" && -z "$(git -C "$source_root" status --porcelain --untracked-files=normal)" ]] || fail 'The source changed during copying; do not publish the wrapper.'

cd "$runtime_root"
# Never boot Artisan with a cached configuration inherited from another location.
mkdir "$backup_root/bootstrap-cache"
for cache in bootstrap/cache/*.php; do
    [[ -e "$cache" || -L "$cache" ]] || continue
    [[ -f "$cache" && ! -L "$cache" ]] || fail 'Cached bootstrap files must be regular files.'
    cp -p "$cache" "$backup_root/bootstrap-cache/"
    rm -- "$cache"
done
"$php_bin" "$composer_phar" install --no-dev --prefer-dist --no-interaction --optimize-autoloader --no-scripts
"$php_bin" "$composer_phar" check-platform-reqs --no-dev
"$php_bin" artisan package:discover --ansi
"$php_bin" artisan config:cache
"$php_bin" artisan route:cache
"$php_bin" artisan view:cache

cat > "$work_dir/public-files/index.php" <<'WATCHLINE_PUBLIC_INDEX'
<?php
use Illuminate\Foundation\Application;
use Illuminate\Http\Request;
define('LARAVEL_START', microtime(true));
$watchlineRuntime = '/home/watchline/apps/watchline-staging';
if (file_exists($maintenance = $watchlineRuntime.'/storage/framework/maintenance.php')) {
    require $maintenance;
}
require $watchlineRuntime.'/vendor/autoload.php';
/** @var Application $app */
$app = require_once $watchlineRuntime.'/bootstrap/app.php';
$app->usePublicPath('/home/watchline/new.watchline.hr');
$app->handleRequest(Request::capture());
WATCHLINE_PUBLIC_INDEX
cat "$source_root/public/.htaccess" > "$work_dir/public-files/.htaccess"
if [[ -s "$work_dir/directory-privacy" ]]; then
    printf '\n# Preserve existing cPanel Directory Privacy.\n' >> "$work_dir/public-files/.htaccess"
    cat "$work_dir/directory-privacy" >> "$work_dir/public-files/.htaccess"
fi
cat >> "$work_dir/public-files/.htaccess" <<'WATCHLINE_PHP_HANDLER'

# Preserve the domain-specific, verified alt-PHP 8.4 LiteSpeed handler.
<FilesMatch "\.(php|phtml)$">
    SetHandler application/x-httpd-alt-php84___lsphp
</FilesMatch>
WATCHLINE_PHP_HANDLER
"$php_bin" -l "$work_dir/public-files/index.php"
rsync -a --no-owner --no-group --checksum --chmod=Du=rwx,Dgo=rx,Fu=rw,Fgo=r --backup --backup-dir="$backup_root/public" "$work_dir/public-files/" "$public_root/"
printf '%s\n' "Staging deployed: $revision" "Private changed-file backup: $backup_root" \
    'No database import/migration, seed, key rotation, mail activation, integration activation, production file or cron change was performed.' \
    'Keep staging private; verify HTTPS, robots/noindex, assets, uploads and one safe page before sharing the preview.'
