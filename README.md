# Lavaggio webshop

Laravel 12 trgovina za Lavaggio. Zadržana je struktura elemenata iz izvornog projekta, uz Lavaggio logo, Satoshi font, ljubičastu paletu i originalni uzorak pozadine.

## Lokalno

- Trgovina: http://lavaggioshop.test
- Administracija: http://lavaggioshop.test/admin
- PHP 8.4+, Composer i Node 22.22.0 prema `.nvmrc`.

```sh
composer install
cp .env.example .env
php artisan key:generate
touch database/database.sqlite
php artisan migrate
php artisan db:seed --class=SystemSettingsSeeder
php artisan db:seed --class=LavaggioStorefrontSeeder
php artisan db:seed --class=LavaggioCatalogSeeder
npm ci
npm run build
php artisan storage:link
```

Herd poslužuje projekt iz ove mape. `DB_DATABASE` u lokalnom `.env` treba sadržavati apsolutnu putanju do `database/database.sqlite`. Na postojećoj instalaciji sačuvajte `.env` i njezin `APP_KEY`.

## Početno stanje

Katalog proizvoda, brandovi, kupci, narudžbe i uvezeni sadržaj su prazni. Uneseno je pet glavnih kategorija i 17 podkategorija s dostavljenim opisima i SEO podacima. Lokalno je sačuvan postojeći administratorski račun `tomislav@agmedia.hr` s nepromijenjenom lozinkom. Baza, pristupni podaci i privatne sigurnosne kopije nisu u Gitu; nova instalacija ne stvara javno poznatu administratorsku lozinku.

Kategorije u navigaciji „Web shop”, početnoj stranici i podnožju nastaju iz aktivnih kategorija u administraciji. Nazivi, poredak i podkategorije nisu ugrađeni u predloške. `LavaggioCatalogSeeder` ponovljivim unosom postavlja početnu strukturu iz [uputa](docs/lavaggio-requirements.md). Stupac „Web shop” u podnožju prikazuje glavne kategorije, a „Informacije” ostale poveznice iz navigacije. Poveznice na praonicu, usluge, cjenik, pitanja, blog i kontakt vode na postojeću stranicu lavaggio.hr.

U administraciji **Sadržaj → Navigacija → Slike kategorija u Web shop izborniku** moguće je učitati, zamijeniti ili ukloniti fotografiju svake glavne kategorije i promijeniti njezin alt opis. Slike se mijenjaju zajedno s odabranom kategorijom u desktop izborniku. `LavaggioNavigationImagesSeeder` postavlja početnih pet fotografija samo ako kategorija još nema spremljen odabir; čuva zamjene i namjerna uklanjanja. Optimizirane slike, izvori referentnih fotografija i korišteni promptovi nalaze se u `public/assets/lavaggio/images/categories/`.

Postavke brenda i početne stranice nalaze se u `system_settings`, početne vrijednosti u `LavaggioStorefrontSeeder`. Poruke se lokalno bilježe u zapisnik; plaćanja, dostavne metode i stare integracije nisu konfigurirani.

Naredba `php artisan lavaggio:prepare-store` prikazuje pregled lokalnog čišćenja. Opcija `--execute` radi privatnu sigurnosnu kopiju i prazni trgovinu uz očuvanje administratorskog računa. Naredba dopušta samo izdvojenu lokalnu mapu `lavaggioshop` i njezinu SQLite bazu.

## Provjere

```sh
php artisan test --filter=Lavaggio
```
