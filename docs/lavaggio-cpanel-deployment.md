# Lavaggio cPanel deployment

The cPanel Git checkout is `/home/baredahr/repositories/lavaggio`, on `main` from the public repository `https://github.com/tomashina/lavaggio.git`. Application code runs privately in `/home/baredahr/apps/lavaggio-shop`; only public assets and the front controller are served from `/home/baredahr/shop.lavaggio.hr`.

## Initial preparation

Create the runtime directory with its current SQLite database at `database/database.sqlite` and a private `.env`. Preserve the source installation's `APP_KEY` so encrypted settings remain readable. Do not commit the database, `.env`, credentials or media archives.

The prepared `public-media.tar.gz` has the prefix `storage/app/public/`. Extract it into the real public storage directory while removing those three path components:

```sh
mkdir -p /home/baredahr/shop.lavaggio.hr/storage
tar -xzf /home/baredahr/.lavaggio-transfer/public-media.tar.gz \
    --strip-components=3 -C /home/baredahr/shop.lavaggio.hr/storage
```

The storage directory must contain the numeric media directories directly. Leave runtime `public` and `storage/app/public` absent; deployment creates outward symlinks to the document root and its `storage/` directory. If media already exists in the runtime, move/preserve it explicitly before deployment.

The domain must have working HTTPS and use PHP 8.4 for web requests. The script uses `/opt/alt/php84/usr/bin/php` (verified PHP 8.4.27) and the verified Composer PHAR at `/home/baredahr/.local/bin/composer.phar` (falling back to an existing standard server Composer installation). Existing `.user.ini`, `.well-known`, cPanel-generated PHP ini directives, explicit PHP handler and public uploads are preserved.

If the account inherits its PHP version through PHP Selector and `.htaccess` contains no explicit handler, verify the actual **web** version first: create a randomly named temporary PHP file in the document root that returns only `PHP_VERSION`, fetch it over the shop's HTTPS URL, confirm 8.4, and remove the file immediately. Only after that check add `LAVAGGIO_INHERITED_WEB_PHP84_VERIFIED=true` to the private `.env`. Deployment then preserves the existing cPanel ini directives and inherits the verified handler; it does not inject a guessed handler. Recheck the web version after changes to hosting PHP settings.

Use these explicit preview settings alongside the preserved application key and normal locale/theme settings:

```dotenv
APP_ENV=staging
APP_DEBUG=false
APP_URL=https://shop.lavaggio.hr
FORCE_HTTPS=true
DB_CONNECTION=sqlite
DB_DATABASE=/home/baredahr/apps/lavaggio-shop/database/database.sqlite
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
LEGACY_MEDIA_ENABLED=false
STOCK_NOTIFICATIONS_ENABLED=false
STOCK_NOTIFICATIONS_EMAILS_ENABLED=false
GIFT_VOUCHERS_ENABLED=false
GIFT_VOUCHER_EMAILS_ENABLED=false
```

Keep store email, GLS API and inherited integrations disabled in the copied database. This profile is a preview: robots are blocked, mail is logged, and external payment integrations remain inactive. BOX NOW requires actual product package measurements outside the local environment.

## Updating

Run `bash scripts/deploy-lavaggio-cpanel.sh` from the registered checkout to validate prerequisites and inspect the copy plan. The default does not boot Artisan or write to the runtime/database.

In cPanel **Git Version Control → Lavaggio → Manage → Pull or Deploy**, choose **Update from Remote**, then **Deploy HEAD Commit**. The committed `.cpanel.yml` invokes the same script with `--apply`. The checkout must be clean and on `main`; deployment never pulls, resets or force-updates Git itself.

Only selected tracked application files and public assets are copied. Dependencies are installed from the committed lock, then Laravel caches are rebuilt. Existing changed files are backed up under `/home/baredahr/.lavaggio-shop-backups/`; public files are never copied with `--delete`. The script does not overwrite `.env` or SQLite, run migrations/seeds, generate a key, or activate mail/integrations/cron. Pending migrations stop deployment and are listed for separate review.

After deployment, verify HTTPS, `/up`, the shop, product images, search, `/admin/login`, checkout and `/robots.txt`. If deployment fails, inspect the private backup and cPanel deployment log before retrying. Backups contain changed files and previous cache files; they are not a complete database/dependency rollback snapshot.
