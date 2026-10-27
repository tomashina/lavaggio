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
npm ci
npm run build
php artisan storage:link
```

Herd poslužuje projekt iz ove mape. `DB_DATABASE` u lokalnom `.env` treba sadržavati apsolutnu putanju do `database/database.sqlite`. Na postojećoj instalaciji sačuvajte `.env` i njezin `APP_KEY`.

## Početno stanje

Katalog, kategorije, brandovi, kupci, narudžbe i uvezeni sadržaj su prazni. Lokalno je sačuvan postojeći administratorski račun `tomislav@agmedia.hr` s nepromijenjenom lozinkom. Baza, pristupni podaci i privatne sigurnosne kopije nisu u Gitu; nova instalacija ne stvara javno poznatu administratorsku lozinku.

Kategorije u navigaciji, početnoj stranici i podnožju nastaju iz aktivnih kategorija u administraciji. Nazivi, poredak i podkategorije nisu ugrađeni u predloške. Kategorije iz klijentskih uputa dodajemo u sljedećem koraku, uz ostale zahtjeve opisane u [uputama](docs/lavaggio-requirements.md).

Postavke brenda i početne stranice nalaze se u `system_settings`, početne vrijednosti u `LavaggioStorefrontSeeder`. Poruke se lokalno bilježe u zapisnik; plaćanja, dostavne metode i stare integracije nisu konfigurirani.

Naredba `php artisan lavaggio:prepare-store` prikazuje pregled lokalnog čišćenja. Opcija `--execute` radi privatnu sigurnosnu kopiju i prazni trgovinu uz očuvanje administratorskog računa. Naredba dopušta samo izdvojenu lokalnu mapu `lavaggioshop` i njezinu SQLite bazu.

## Provjere

```sh
php artisan test --filter=Lavaggio
```
