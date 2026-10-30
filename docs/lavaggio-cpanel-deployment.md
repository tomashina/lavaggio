# Lavaggio cPanel deployment

The entire Laravel project, its Git checkout and runtime live at `/home/baredahr/shop.lavaggio.hr`. The cPanel document root for `shop.lavaggio.hr` must be `/home/baredahr/shop.lavaggio.hr/public`. Use `main` from `https://github.com/tomashina/lavaggio.git`. The tracked `public/index.php` is Laravel's normal front controller; deployment does not replace it with a wrapper or copy code to another runtime.

## Initial preparation

Preserve the current `.env`, `APP_KEY` and SQLite database inside the project. The database belongs at `database/database.sqlite`. Do not commit the database, credentials, private archives or native uploads. The project/public directories must be traversable (`0755`); `.git` is private (`0700`), and `.env`/SQLite are owner-only (`0600`).

Native media follows Laravel's standard layout: real files in `storage/app/public/`, with `public/storage` pointing to that directory. The prepared archive already has the prefix `storage/app/public/`, so extract it from the project root:

```sh
tar -xzf /home/baredahr/.lavaggio-transfer/public-media.tar.gz \
    -C /home/baredahr/shop.lavaggio.hr
```

Preserve the tracked `storage/app/public/.gitignore`. If `public/storage` is absent, deployment creates the normal symlink. It will not replace an existing physical directory or a link pointing outside this project; preserve/move the media explicitly before deploying.

The domain must have working HTTPS and use PHP 8.4 for web requests. CLI PHP is `/opt/alt/php84/usr/bin/php` (verified 8.4.27). Composer is the verified PHAR at `/home/baredahr/.local/bin/composer.phar`, with existing standard Composer installations as fallbacks.

The account currently inherits web PHP 8.4 from PHP Selector. Keep `LAVAGGIO_INHERITED_WEB_PHP84_VERIFIED=true` in the private `.env` only after verifying the actual HTTPS web version. Recheck after changing hosting PHP settings. A temporary randomly named probe may return only `PHP_VERSION`; remove it immediately after verification. Deployment does not inject a PHP handler into `.htaccess`.

Keep tracked `public/index.php`, `public/.htaccess` and `public/.user.ini` identical to Git so cPanel can pull updates cleanly. Preserve any local `public/php.ini` and `public/.well-known/` through server-local `.git/info/exclude` rules, without ignoring changes to tracked files. Old hosting-specific PHP directives should remain in the migration backup if superseded by the account's normal PHP logging configuration. Deployment does not rewrite these hosting files.

Use these preview settings alongside the preserved application key and locale/theme settings:

```dotenv
APP_ENV=staging
APP_DEBUG=false
APP_URL=https://shop.lavaggio.hr
FORCE_HTTPS=true
DB_CONNECTION=sqlite
DB_DATABASE=/home/baredahr/shop.lavaggio.hr/database/database.sqlite
SESSION_DRIVER=file
SESSION_COOKIE=lavaggio_shop_session
SESSION_SECURE_COOKIE=true
CACHE_STORE=file
QUEUE_CONNECTION=sync
MAIL_MAILER=log
STOREFRONT_THEME=herrera
STOREFRONT_HOME=lavaggio
STOREFRONT_BRAND=libermedia
STORE_B2C_ONLY=true
STORE_B2B_ONLY=false
STORE_B2B_DISPLAY_NET=false
HERRERA_LOCAL_SAFE_MODE=true
LAVAGGIO_INHERITED_WEB_PHP84_VERIFIED=true
LEGACY_MEDIA_ENABLED=false
STOCK_NOTIFICATIONS_ENABLED=false
STOCK_NOTIFICATIONS_EMAILS_ENABLED=false
GIFT_VOUCHERS_ENABLED=false
GIFT_VOUCHER_EMAILS_ENABLED=false
```

Keep store email, GLS API and inherited integrations disabled in the database. This remains a preview profile: robots are blocked, mail is logged, and external payment integrations remain inactive. BOX NOW requires actual product package measurements outside the local environment.

## Updating

Run `bash scripts/deploy-lavaggio-cpanel.sh` from the registered project checkout to validate prerequisites and inspect the plan. The default does not boot Artisan, change permissions, create symlinks or write to application/database files.

In cPanel **Git Version Control → Lavaggio → Manage → Pull or Deploy**, choose **Update from Remote**, then **Deploy HEAD Commit**. The committed `.cpanel.yml` invokes the script with `--apply`. The checkout must be clean and on `main`; `.env`, SQLite, dependencies, sessions, caches and uploads stay ignored. The script never pulls, resets or force-updates Git.

The checkout is already the running application, so deployment does not copy source/assets elsewhere. It backs up generated caches and Composer metadata under `/home/baredahr/.lavaggio-shop-backups/`, installs locked dependencies without development packages/scripts, checks platform requirements and rebuilds Laravel caches. It preserves `.env`, SQLite and uploaded media, and never runs migrations/seeds, generates a key or activates mail/integrations/cron. Pending migrations stop deployment and are listed for separate review.

After deployment, check HTTPS, `/up`, the shop, one image, search, administration and checkout using ordinary requests. If deployment fails, inspect the private backup and cPanel deployment log before retrying. Cache/Composer-metadata backups are not a full source, dependency or database rollback snapshot; code history remains in Git and database/media backups are managed separately.
