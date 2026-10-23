# Watchline New

Laravel 12 osnova za redizajn Watchline trgovine, kopirana iz lokalnog projekta `libermedia-new`. Zadržani su njegov kod, administracija i funkcionalnosti. Watchline sadržaj uvozi se iz OpenCarta 2.3.0.2 u zasebnu Laravel bazu.

Lokalno: **http://watchline-new.test**  
Administracija: **http://watchline-new.test/admin**

## Lokalni rad

Potrebni su PHP 8.4+, Composer i Node prema `.nvmrc` (22.22.0). Herd prepoznaje Laravel projekt u svojoj parkiranoj mapi.

```sh
composer install
cp .env.example .env
php artisan key:generate
touch database/database.sqlite
php artisan migrate
npm ci
npm run build
php artisan storage:link
```

U `.env` postavite apsolutni `DB_DATABASE` na projektnu `database/database.sqlite`. Baza stranice je SQLite i radi neovisno o izvornom MySQL servisu. Za daljnji razvoj koristite `npm run dev` po potrebi.

## Watchline podaci

Početni izvor je svježi `/Users/tomek/Desktop/watchlin_watch.sql`, izvezen 23. 10. 2026. Originalni `Herd/watchline`, `libermedia-new` i produkcija ostaju neizmijenjeni. Privatni podaci, SQL izvori i lokalni pristupni podaci nisu u Gitu.

SQL je uvezen u izdvojenu lokalnu MariaDB instancu u `.local/mysql`, na `127.0.0.1:33107`, baza `watchline_snapshot_20261023`. Postojeća globalna MySQL konfiguracija i baze ne mijenjaju se.

```sh
bash scripts/watchline-source-db.sh start
php artisan watchline:import --dry-run
php artisan watchline:import
php artisan watchline:configure-preview
```

Povezivanje izvora uređuje se kroz `WATCHLINE_SOURCE_*` u `.env`. Veza `watchline_source` postavljena je samo za čitanje. Uvoz prenosi katalog, sadržaj, kupce, adrese, cjenike, povijesne narudžbe, SEO adrese i odnose. OpenCart 2 `url_alias` i hrvatski jezik ID 2 podržani su. Izvorni cjenici ostaju nacrti dok se izričito ne aktiviraju.

Izvorna MariaDB instanca potrebna je samo za uvoz i pripremu postavki. Nakon toga može se zaustaviti; stranica nastavlja koristiti SQLite:

```sh
bash scripts/watchline-source-db.sh stop
```

Na novoj instalaciji, nakon pokretanja izdvojene instance, SQL prvo uvezite u zasebnu praznu bazu:

```sh
mysql --protocol=tcp -h 127.0.0.1 -P 33107 -u root -e 'CREATE DATABASE watchline_snapshot_20261023 CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci'
mysql --protocol=tcp -h 127.0.0.1 -P 33107 -u root watchline_snapshot_20261023 < /putanja/do/watchlin_watch.sql
```

Slike se zasad učitavaju izravno s `https://www.watchline.hr/image/`, uz prednost lokalnih originala ako se naknadno dodaju u `public/image`. Aktivne postavke su `LEGACY_MEDIA_ENABLED=true` i `LEGACY_MEDIA_ORIGIN=https://www.watchline.hr`. Za testnu instalaciju koristite isti izvor. U ovoj fazi nema masovnog preuzimanja slika.

`STOREFRONT_THEME=herrera` zadržava raspored kopirane osnove, a `STOREFRONT_HOME=watchline` prikazuje Watchline sadržaj, a `STOREFRONT_BRAND=libermedia` zadržava izvorni Liber Media izgled, boje i komponente. Ovo je početna osnova; redizajn tek slijedi.

Detaljna usporedba izvora i prijenosa: [docs/watchline-migration.md](docs/watchline-migration.md). Lokalni pristup administraciji nalazi se u privatnoj `.local/admin-access.txt`.

Lokalni pregled koristi `MAIL_MAILER=log` i lokalni sigurni način rada. Obavijesti, poklon bonovi i stvarna naplata nisu uključeni. Naslijeđene `libermedia:*`, `herrera:*`, integracije i deployment skripte zadržane su kao dio izvornog koda te zahtijevaju prilagodbu prije uporabe za Watchline. Uklonjen je automatski cPanel deployment za Liber Media.

## Provjere

```sh
php artisan test --filter=HerreraOpenCartImportTest
npm run build
```
