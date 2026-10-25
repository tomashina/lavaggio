# Watchline staging na cPanelu

Skripta `scripts/deploy-watchline-staging.sh` namijenjena je isključivo računu `watchline`, Git izvoru `/home/watchline/repositories/watchline-new` na čistom `main`, privatnoj aplikaciji `/home/watchline/apps/watchline-staging` i javnom direktoriju `/home/watchline/new.watchline.hr`. Ne povlači Git promjene i ne dira postojeći OpenCart u `public_html`.

Prije prvog pokretanja operater priprema privatni runtime direktorij, njegov `.env` i zasebnu postojeću `database/database.sqlite`. Sačuvati aplikacijski ključ koji pripada kopiranim šifriranim podacima. Skripta ne generira ključ, ne stvara bazu, ne uvozi SQL, ne pokreće migracije ni seedove. Pristup stagingu s kopijom stvarnih podataka obavezno zaštititi kroz cPanel Directory Privacy; `noindex` nije zaštita pristupa. Bez postojeće BasicAuth zaštite `--apply` se zaustavlja. Dry-run jasno prijavljuje nedostatak, a može nastaviti s preostalim provjerama ako su baza i konfiguracija pripremljene. Nema opcije za zaobilaženje provjere i skripta ne stvara pristupne podatke.

Obavezne staging vrijednosti (bez stvarnog ključa):

```dotenv
APP_ENV=staging
APP_URL=https://new.watchline.hr
APP_DEBUG=false
HERRERA_LOCAL_SAFE_MODE=true
STOREFRONT_HOME=watchline
STORE_B2C_ONLY=true
DB_CONNECTION=sqlite
DB_DATABASE=/home/watchline/apps/watchline-staging/database/database.sqlite
MAIL_MAILER=log
QUEUE_CONNECTION=sync
CACHE_STORE=file
SESSION_DRIVER=file
SESSION_COOKIE=watchline_staging_session
SESSION_DOMAIN=null
SESSION_SECURE_COOKIE=true
GIFT_VOUCHER_EMAILS_ENABLED=false
STOCK_NOTIFICATIONS_EMAILS_ENABLED=false
```

`DB_URL`, `SESSION_CONNECTION` i `DB_CACHE_CONNECTION` moraju ostati prazni. U staging kopiji baze pošta, GLS API, glavni prekidač integracija, svi rasporedi i naslijeđeni Corvus moraju biti isključeni. Provjera ih samo čita i zaustavlja objavu ako nisu sigurni. Ne postavljati cron ni queue worker. Izvan produkcije ili uz sigurni način frontend ne emitira GA/Meta/ecommerce analitiku.

Potreban je već provjereni PHP `/opt/alt/php84/usr/bin/php` i privatni, prethodno checksumom provjeren Composer `/home/watchline/.local/bin/composer-2.10.3.phar`. Skripta provjerava zahtjeve iz lock datoteke; ne zaobilaziti nedostajuće ekstenzije. Postojeći `.htaccess` poddomene mora sadržavati `SetHandler application/x-httpd-alt-php84___lsphp`. Objavljeni Laravel `.htaccess` zadržava taj handler i postojeću cPanel BasicAuth zaštitu direktorija; ne mijenja se globalni PHP niti MultiPHP postavka. Postojeći `.user.ini` ostaje sačuvan. Javni resursi izričito dobivaju direktorije 755 i datoteke 644 čak i kada je Git izvor privatan; vršni runtime ostaje 700.

Na ovoj testnoj domeni zasebni `new.watchline.hr/.user.ini` postavlja `memory_limit=256M`; ne mijenjaju se globalni PHP ni OpenCart. Široki upit vodiča za poklone lokalno je dosegnuo 122,5 MiB, pa 128 MiB ostavlja premalo prostora. Stvarnu web vrijednost provjeriti kroz autentificirani HTTPS, bez javnog `phpinfo()` prikaza.

Iz registriranog repozitorija prvo pregledati plan:

```sh
bash scripts/deploy-watchline-staging.sh
bash scripts/deploy-watchline-staging.sh --apply
```

Bez `--apply` izvode se samo provjere, privremeni privatni popisi, prikaz veličina i `rsync --dry-run`; nema Composer instalacije ili pokretanja Laravela. Primjena kopira samo Git datoteke, instalira zaključane produkcijske ovisnosti bez lifecycle skripti te obnavlja package/config/route/view cache. Lokalni `bootstrap/cache` se ne kopira. Promijenjene postojeće datoteke spremaju se u privatni `/home/watchline/.watchline-staging-backups/`; to nije potpuna sigurnosna kopija baze/vendor direktorija niti automatski rollback. Prije primjene zasebno sačuvati postojeću staging bazu i uploadove. Kod neuspjeha zadržati staging zatvorenim i pregledati izvještaj prije ponavljanja.

Javni direktorij sadrži samo javne resurse i zaseban `index.php` s izričitom privatnom runtime putanjom za održavanje, autoload i bootstrap. `runtime/public` pokazuje na javni direktorij kako bi `public_path()` radio i iz konzole. Za native uploadove fizički direktorij je `new.watchline.hr/storage`, a privatni `storage/app/public` pokazuje prema njemu. Skripta odbija postojeću drugačiju poveznicu ili direktorij s native medijima: njih treba prethodno pažljivo premjestiti, bez prepisivanja uploadova.

Nema `--delete` nad javnim direktorijem. Izvorni `image/catalog`, cache slika i ostali ignorirani podaci ne kopiraju se kroz Git deployment; potrebni mediji prenose se zasebnim provjerenim manifestom. Izostavljeni su nepotrebni izvorni generirani kategorijski PNG-ovi. Font Awesome originalni spriteovi ostaju jer ih renderer još koristi kao rezervu za dinamičke ikone. Ponavljanje preskače sadržajno nepromijenjene datoteke.

Provjera stvarnog izvornog direktorija na hostingu potvrdila je **14.344 neprazna originala, ukupno 1.688.353.145 bajtova**, od 14.369 putanja iz manifesta. Preostalih 17 datoteka nedostaje i pripada neaktivnim artiklima/jednom neaktivnom blogu; osam datoteka postoji, ali je prazno, i predstavljaju dodatne fotografije u galerijama aktivnih artikala. Njihove glavne fotografije postoje. To su zatečene izvorne iznimke, a ne datoteke izgubljene prijenosom. Ne mijenjaju se zapisi kataloga, ne izmišljaju zamjenske fotografije i ne prenosi se cijeli OpenCart cache. Za selektivni popis i provjeru putanja koristiti [upute manifesta slika](watchline-media-manifest.md).

Tek nakon potvrđenog prijenosa tih 14.344 originala, **samo staging** koristi `LEGACY_MEDIA_ENABLED=false` uz osvježen konfiguracijski cache. Ta vrijednost isključuje udaljeni fallback: provjereni neprazni lokalni originali i dalje se prikazuju, a navedene nepostojeće/prazne fotografije ne dodaju se kao neispravni udaljeni URL-ovi galerije. Postavka ne briše datoteke ni metapodatke i ne mijenja postojeći lokalni razvojni shop ili produkcijski OpenCart. Prije budućeg live prelaska ponovno provjeriti medije prema svježim podacima; ova staging provjera nije dokaz buduće potpunosti.

Nakon primjene provjeriti HTTPS, statusne odgovore, `/robots.txt`, `X-Robots-Tag: noindex`, assete, spremljene uploadove i da su svi outbound prekidači ostali isključeni. Ova skripta nije produkcijski deploy niti dokaz završnog svježeg uvoza.
