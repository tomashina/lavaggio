# Lavaggio — sadržaj i adrese, feedback 04.11.2026.

`php artisan lavaggio:apply-november-feedback` prikazuje plan bez upisa. Nakon pregleda, `php artisan lavaggio:apply-november-feedback --apply` primjenjuje uske izmjene samo na provjerenoj lokalnoj SQLite bazi. Komanda odbija produkcijsku ili drugu bazu. Prije upisa sprema konzistentnu privatnu SQLite kopiju u `storage/app/private/lavaggio-feedback-backups/` i primjenjuje plan unutar transakcije. Ne pokretati globalni seed/import preko sadržaja koji je klijent uređivao.

Plan deaktivira uvezenu samoposlužnu granu i cjenik (69, 87, 92, 154, 155), informativni poklon bon (322) i posrednu stranicu Cjenik (70). Uslužni cjenik (88) ostaje aktivan, dostupan izravno na `/cjenik`. Navigacija zadržava ostale korisnikove stavke. Opća webshop funkcija poklon bonova i postojeće narudžbe ne mijenjaju se.

Samo prepoznati izvorni tekstovi FAQ-a, načina plaćanja, radnog vremena i uvjeta praonice usklađuju se s prestankom samoposlužne usluge. Ako je urednik promijenio tekst, komanda ga čuva i prijavljuje za ručni pregled. Poveznice se prevode zasebno, bez prepisivanja ostalog sadržaja. Povijesne blog objave zadržavaju tekst, uključujući povijesne spomene ugašene usluge; nedostupne poveznice postaju običan tekst.

Na izričitu potvrdu korisnika svi postojeći iznosi i uređena tablica uslužnog cjenika ostaju isti. Mijenja se samo poznati tekst `Cijene vrijede od: 01.06.2026` u `Cijene vrijede od: 10.09.2026`. Nestandardne datume komanda ne nagađa. Prazno radno vrijeme u podnožju popunjava se s `Pon-Pet: 08h - 16h`; uređena vrijednost ostaje.

Hrvatske javne adrese vrijede samo za Lavaggio. Postojeća imena ruta ostaju, a hrvatski aliasi koriste iste kontrolere i pravila pristupa. Stare javne GET/HEAD adrese vode 301 na hrvatske, uz sačuvan upit. Ugašene stranice vraćaju 410 s poveznicom na kontakt i uslužni cjenik. Adrese platnih providera, callbackovi, administracija, API i potpisane obavijesti ostaju u postojećem protokolu. Drugi storefrontovi zadržavaju svoje adrese.

Za objavu slijedi projektni Git postupak iz `docs/lavaggio-cpanel-deployment.md`. Ova lokalna komanda nije postupak objave i ne pristupa poslužitelju.

## Dopuna 06.11.2026.

Recenzije klijenata prikazuju se u prilagodljivim karticama. Dostava koristi postojeće naslove h2/h3 za grupiranje sadržaja u boksove, bez zamjene uredničkog teksta ili cijena. Uvod FAQ-a već se uređuje u **Sadržaj → Stranice → Pitanja i odgovori → Sadržaj**, pa ostaje sačuvan.

Radno vrijeme u podnožju sada stoji odmah ispod telefona, a zakonsko jamstvo u donjim pravnim poveznicama. Dodani su postojeći izvorni logotipi CorvusPay, Visa, Mastercard, Maestro, Diners, Apple Pay i Google Pay. Dodatna pregledana migracija `2026_11_06_090000_add_lavaggio_payment_information` dopisuje korisnikov IBAN `HR9423800061171000708` u uređivu adresu te identificira sustav i prihvaćene načine plaćanja na webshop stranici plaćanja. Ne aktivira platni servis ni digitalne novčanike.

Corvus povratne adrese su `https://shop.lavaggio.hr/checkout/corvus/success` za uspjeh i `https://shop.lavaggio.hr/checkout/corvus/cancel` za neuspjeh, uz HTTP metodu `POST`. U administraciji webshopa CorvusPay ima polja Mode (Test/Live), Store ID i Secret key; ID i ključ moraju pripadati odabranom okruženju. Apple Pay i Google Pay uključuje Corvus podrška za odgovarajuće prodajno mjesto.

Uslužni cjenik prikazuje zaseban stupac **Sidrena cijena na dan 10.09.2026.** uz trenutnu cijenu. Fiksni iznosi su prema korisnikovoj uputi preuzeti iz postojećeg odobrenog cjenika, potvrđenog na javnoj stranici 06.11.2026., i zapisani u `database/data/lavaggio-service-anchor-prices-20260910.json`. Prikaz ih povezuje s nazivom usluge i varijantom, bez upisa u urednički sadržaj baze. Buduća promjena trenutne cijene ne mijenja sidrenu; za novu nepoznatu uslugu prikazuje se crtica umjesto izmišljene povijesne cijene.
