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
php artisan db:seed --class=LavaggioContentSeeder
npm ci
npm run build
php artisan storage:link
```

Herd poslužuje projekt iz ove mape. `DB_DATABASE` u lokalnom `.env` treba sadržavati apsolutnu putanju do `database/database.sqlite`. Na postojećoj instalaciji sačuvajte `.env` i njezin `APP_KEY`.

## Početno stanje

Katalog proizvoda, brandovi, kupci i narudžbe su prazni. Uneseno je pet glavnih kategorija i 17 podkategorija s dostavljenim opisima i SEO podacima. Javni sadržaj s lavaggio.hr prenesen je u lokalne stranice, TV studio i česta pitanja. Lokalno je sačuvan postojeći administratorski račun `tomislav@agmedia.hr` s nepromijenjenom lozinkom. Baza, pristupni podaci i privatne sigurnosne kopije nisu u Gitu; nova instalacija ne stvara javno poznatu administratorsku lozinku.

Kategorije u navigaciji „Web shop”, početnoj stranici i podnožju nastaju iz aktivnih kategorija u administraciji. Nazivi, poredak i podkategorije nisu ugrađeni u predloške. `LavaggioCatalogSeeder` ponovljivim unosom postavlja početnu strukturu iz [uputa](docs/lavaggio-requirements.md). Stupac „Web shop” u podnožju prikazuje glavne kategorije, a „Informacije” ostale poveznice iz navigacije. Nakon Web shopa slijede Praonica, Pitanja i odgovori, TV studio, Tko smo mi i Kontakt. Sve pripadajuće stranice otvaraju se lokalno.

## Sadržaj praonice

`LavaggioContentSeeder` uvozi javni snimak sadržaja od 28. 10. 2026. iz `database/data/lavaggio-content-20261028.json` i postavlja početnu navigaciju prema dostavljenoj strukturi. Uključuje 43 stranice, 344 video objave, 32 kategorije objava, 13 pitanja, cjenike i 17 recenzija. Slike su spremljene lokalno u `public/assets/lavaggio/content/`; videozapisi zadržavaju postojeće YouTube ugrađivanje. Neobjavljene i obrisane MODX stranice nisu uvezene. Poveznice prema njima zadržavaju tekst bez neispravne poveznice. Blog i kontakt koriste postojeće lokalne module.

Info stranice i pitanja imaju sadržaj širine do 1320 px; slike u tekstu su zaobljene, a uvodi ostaju unutar izvornog sadržaja. TV studio prikazuje četiri objave u redu na desktopu, izvorne kategorije i filtriranje. Kontakt zadržava izvorne kontakt podatke, polja obrasca i kartu, uz lokalno spremanje upita.

Detalj video objave prikazuje široku naslovnicu s Font Awesome Pro play gumbom. Klik otvara video u modalu; zatvaranje zaustavlja reprodukciju. Vezani članci koriste iste četiri kartice i format slika kao TV studio.

U administraciji **Sadržaj → Stranice**, **Blog** i **Česta pitanja** uređuje se preneseni sadržaj. U **Sadržaj → Navigacija** mogu se mijenjati nazivi, redoslijed i ugniježđene stavke Praonice. Ponovno pokretanje seedera namjerno vraća uvezeni sadržaj i početnu navigaciju iz snimka; ne pokrećite ga nakon ručnih izmjena ako ih želite sačuvati. Za pregled zasebnog snimka postoji `php artisan lavaggio:import-content putanja.json`, a `--execute` dopušta uvoz isključivo u provjerenu lokalnu Lavaggio SQLite bazu.

U administraciji **Sadržaj → Navigacija → Slike kategorija u Web shop izborniku** moguće je učitati, zamijeniti ili ukloniti fotografiju svake glavne kategorije i promijeniti njezin alt opis. Slike se mijenjaju zajedno s odabranom kategorijom u desktop izborniku. `LavaggioNavigationImagesSeeder` postavlja početnih pet fotografija samo ako kategorija još nema spremljen odabir; čuva zamjene i namjerna uklanjanja. Optimizirane slike, izvori referentnih fotografija i korišteni promptovi nalaze se u `public/assets/lavaggio/images/categories/`.

Postavke brenda i početne stranice nalaze se u `system_settings`, početne vrijednosti u `LavaggioStorefrontSeeder`. Poruke se lokalno bilježe u zapisnik; stare integracije nisu aktivirane.

`php artisan lavaggio:configure-checkout` postavlja lokalni pregled GLS dostave na adresu, GLS paketomata i BOX NOW paketomata te pouzeća i bankovne transakcije. Naredba čuva sigurnosnu kopiju baze, nasljeđuje postojeću cijenu dostave i prag besplatne dostave te pri ponovnom pokretanju čuva naknadne izmjene u administraciji. Početni lokalni pregled koristi 4,90 € i besplatnu dostavu iznad 50 €; cijene dostavnih službi uređuju se u administraciji.

Pod **Postavke → Lokalno → Načini plaćanja → Bankovna transakcija** upisuju se IBAN, primatelj i ostali podaci za uplatu. Lokalni testni IBAN `XXXX` jasno je označen „NIJE ZA UPLATU”, a ogledni QR kod sadrži samo testni tekst. Nakon unosa valjanih podataka nove narudžbe automatski dobivaju SEPA QR i hrvatski HUB3 barkod, generirane lokalno. Produkcija zahtijeva valjane bankovne podatke. BOX NOW lokalno dopušta pregled proizvoda bez mjera pakiranja; za produkciju treba dopuniti stvarne mjere i težine proizvoda.

Ikona pretrage otvara modal preko cijelog ekrana s prijedlozima proizvoda, kategorija i brendova. Društvene mreže u zaglavlju i podnožju koriste iste postavke. Pregled Lavaggio mailova na `/preview/lavaggio-mail` prikazuje 19 primjera na računalu i mobitelu; dostupan je samo u lokalnom i testnom okruženju i ne šalje poruke.

Naredba `php artisan lavaggio:prepare-store` prikazuje pregled lokalnog čišćenja. Opcija `--execute` radi privatnu sigurnosnu kopiju i prazni trgovinu uz očuvanje administratorskog računa. Naredba dopušta samo izdvojenu lokalnu mapu `lavaggioshop` i njezinu SQLite bazu.

## Provjere

```sh
php artisan test --filter=Lavaggio
```
