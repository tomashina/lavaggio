# Lavaggio dostava i plaćanje

`php artisan lavaggio:configure-checkout` konfigurira samo lokalnu Lavaggio SQLite trgovinu. Prije izmjene radi privatnu konzistentnu kopiju baze u `storage/app/private/lavaggio-checkout-backups/`. Ponovno pokretanje čuva postojeće metode, cijene, aktivaciju, podatke banke i naknadne izmjene iz administracije. Stari ogledni način dostave ostaje u bazi radi povijesti, ali se isključuje.

GLS dostava na adresu, GLS paketomat i BOX NOW paketomat nasljeđuju postojeću cijenu dostave i prag besplatne dostave. U ovoj lokalnoj trgovini to je bilo 4,90 € i 50 €. Cijene se uređuju u **Dostava**, a zajednički prag u **Postavke → Trgovina → Cijene**. Početne cijene mogu se zadati preko `config/lavaggio_checkout.php` prije prve konfiguracije.

GLS i BOX NOW otvaraju službene karte paketomata. Odabrana lokacija sprema se uz narudžbu i prikazuje na potvrdi te u e-mailu. Za GLS metodu paketomata ne prihvaća se ParcelShop odabir. Server traži ID, naziv i potpunu hrvatsku adresu odabranog paketomata; nedostajuće i zamijenjene GLS/BOX NOW podatke odbija prije spremanja narudžbe.

Službena BOX NOW karta dopušta rad bez partnerskog ID-a i tada prikazuje lokacije s velikim pretincima. Granice velikog paketa su 20 kg, 60 × 45 × 36 cm. U lokalnom sigurnom pregledu nepotpune mjere oglednih proizvoda ne skrivaju metodu; poznate težine, dimenzije i posebne zabrane i dalje se provjeravaju. Izvan lokalnog sigurnog pregleda proizvodi bez mjera blokiraju BOX NOW. Partnerski API podaci za automatsko stvaranje naljepnica uređuju se zasebno u administraciji dostave.

Plaćanja su **Pouzećem** i **Bankovna transakcija**. Podaci banke uređuju se na `/admin/settings/local/payment-methods` → **Bankovna transakcija → Uredi**: naziv primatelja, ulica, mjesto, IBAN, model, šifra namjene i opis. Naknadne narudžbe odmah koriste spremljene podatke. Već izdane potvrde zadržavaju svoj izvorni račun, referencu i kodove.

Za stvarni ispravni IBAN potvrda narudžbe i e-mail prikazuju HUB3 PDF417 2D kod te stvarni kvadratni SEPA EPC QR. Oba nastaju lokalno, bez slanja osobnih ili bankovnih podataka vanjskom servisu. EPC QR sadrži ime primatelja, IBAN, iznos u EUR i model/referencu u opisu; podrška skeniranju ovisi o bankovnoj aplikaciji. Bankovne podatke kupac vidi i u tekstu.

Početni ogledni IBAN `XXXX` dopušten je samo u lokalnom sigurnom pregledu i jasno označava potvrdu i e-mail kao **NIJE ZA UPLATU**. Njegov QR sadrži isključivo tekst `OGLEDNI PRIKAZ - NIJE ZA UPLATU`, bez platnog naloga. Izvan tog pregleda neispravan bankovni način plaćanja nije dostupan ni za redovne ni za poklon narudžbe. U tekućoj lokalnoj trgovini ogledni račun zamijenjen je stvarnim IBAN-om koji je dostavio vlasnik; ponovno pokretanje konfiguracije ga čuva.

Izvori specifikacija:

- [BOX NOW upute za widget](https://boxnow.hr/diy/widget/developers)
- [BOX NOW konfiguracija javne karte](https://map-docs.boxnow.bg/configuration)
- [BOX NOW granice paketa](https://www.boxnow.hr/terms-of-use-for-shipping-services)
- [HUB3A EUR specifikacija](https://hub.hr/sites/default/files/inline-files/2DBK_EUR_Uputa_1.pdf)
- [EPC069-12 v3.1 specifikacija SEPA QR koda](https://www.europeanpaymentscouncil.eu/document-library/guidance-documents/quick-response-code-guidelines-enable-data-capture-initiation)

Regresijske provjere pokrivaju čuvanje administratorskih izmjena, unos stvarnog IBAN-a kroz postojeći obrazac, izračun i ograničenja dostave, obavezni odabir paketomata, lokalne ogledne naloge, zabranu neispravnog računa izvan pregleda te generiranje stvarnih kodova bez mrežnih poziva.
