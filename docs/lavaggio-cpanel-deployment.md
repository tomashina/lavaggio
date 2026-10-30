# Lavaggio cPanel deployment

The entire Laravel project, its Git checkout and runtime live at `/home/baredahr/shop.lavaggio.hr`. The cPanel document root for `shop.lavaggio.hr` must be `/home/baredahr/shop.lavaggio.hr/public`. Use `main` from `https://github.com/tomashina/lavaggio.git`. The tracked `public/index.php` is Laravel's normal front controller; deployment does not replace it with a wrapper or copy code to another runtime.

## Initial preparation

Preserve the current `.env` and `APP_KEY`. The hosted webshop uses its own MySQL database, `baredahr_lavaggioshop`, on `localhost:3306`, with a dedicated cPanel database user granted access only to that database. It is visible in cPanel and phpMyAdmin. The existing `baredahr_lavaggio` database belongs to the main site and must never be used, imported into, renamed or modified by this deployment.

The SQLite-to-MySQL transfer is a separate, deliberate one-time operation: preserve a consistent private SQLite backup, import its complete schema and data into the empty shop database, and compare table counts and stored data before switching `.env` and rebuilding Laravel configuration. Keep the original SQLite backup for rollback. Deployment does not create database users, import data or change the active connection. Do not commit databases, credentials, private archives or native uploads. The project/public directories must be traversable (`0755`); `.git` is private (`0700`), and `.env`/retained SQLite backups are owner-only (`0600`).

The one-time transfer utility is `scripts/migrate-lavaggio-sqlite-to-mysql.php`. Its default is a read-only preflight; `--apply` requires the server project to be in maintenance mode and the dedicated target to be completely empty. Supply `--credentials` with an owner-only JSON file outside `public/`, containing `host`, `port`, `database`, `username` and `password`. The live database and username must both be `baredahr_lavaggioshop`. It creates the current MySQL schema, copies every source table, and compares row counts, content digests and foreign keys before committing the copied rows. Where SQLite contains a larger integer than a non-identity/non-foreign-key MySQL column accepts, it widens that target column rather than truncating data; each such column is reported.

Keep administration idle during cutover: Laravel maintenance mode still allows certain administration routes. Run the utility with `--verify` against the current source immediately before changing the active connection, so any source change stops cutover. Only after verification, switch the private database settings and rebuild Laravel configuration. If schema creation or copying fails, the application remains on SQLite and the new target is retained for inspection; the utility never drops a database or automatically clears a nonempty target.

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
DB_CONNECTION=mysql
DB_HOST=localhost
DB_PORT=3306
DB_DATABASE=baredahr_lavaggioshop
DB_USERNAME=baredahr_lavaggioshop
DB_PASSWORD='REPLACE_WITH_THE_PRIVATE_SHOP_DATABASE_PASSWORD'
DB_CHARSET=utf8mb4
DB_COLLATION=utf8mb4_unicode_ci
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

Use the actual dedicated cPanel shop user if its name differs from the example. The deployment preflight allows only a `baredahr_` user other than the main site's `baredahr_lavaggio` user, and only the exact `baredahr_lavaggioshop` database. Keep `DB_URL` and `DB_SOCKET` unset so Laravel and the preflight use the same local connection. Put the one-line database password inside single quotes; `$`, backslashes, `#` and double quotes then remain literal, as they do in Laravel's Dotenv parser. An embedded single quote in the password requires separate review. Simple unquoted/double-quoted passwords remain accepted without interpolation or escapes. Password values that Laravel would convert to a boolean/null/empty value or strip another pair of surrounding quotes are rejected to keep preflight and runtime credentials identical. Other protected `.env` values must be explicit literals without interpolation or escape sequences. The MySQL preflight opens a read-only transaction and reads the migrations/settings; it never changes stored data. A deliberately configured project-local SQLite preview remains supported, but the hosted shop's active `.env` must use MySQL after the transfer.

## Updating

Run `bash scripts/deploy-lavaggio-cpanel.sh` from the registered project checkout to validate prerequisites and inspect the plan. The default does not boot Artisan, change permissions, create symlinks or write to application/database files.

In cPanel **Git Version Control → Lavaggio → Manage → Pull or Deploy**, choose **Update from Remote**, then **Deploy HEAD Commit**. The committed `.cpanel.yml` invokes the script with `--apply`. The checkout must be clean and on `main`; `.env`, retained SQLite backups, dependencies, sessions, caches and uploads stay ignored. The script never pulls, resets or force-updates Git.

The checkout is already the running application, so deployment does not copy source/assets elsewhere. It backs up generated caches and Composer metadata under `/home/baredahr/.lavaggio-shop-backups/`, installs locked dependencies without development packages/scripts, checks platform requirements and rebuilds Laravel caches. It preserves `.env`, the active database, retained SQLite backups and uploaded media, and never runs migrations/seeds, generates a key or activates mail/integrations/cron. Pending migrations stop deployment and are listed for separate review.

After deployment, check HTTPS, `/up`, the shop, one image, search, administration and checkout using ordinary requests. If deployment fails, inspect the private backup and cPanel deployment log before retrying. Cache/Composer-metadata backups are not a full source, dependency or database rollback snapshot; code history remains in Git and database/media backups are managed separately.
