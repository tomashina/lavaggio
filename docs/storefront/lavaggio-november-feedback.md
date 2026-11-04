# Lavaggio — sadržaj i adrese, feedback 04.11.2026.

`php artisan lavaggio:apply-november-feedback` prikazuje plan bez upisa. Nakon pregleda, `php artisan lavaggio:apply-november-feedback --apply` primjenjuje uske izmjene samo na provjerenoj lokalnoj SQLite bazi. Komanda odbija produkcijsku ili drugu bazu. Prije upisa sprema konzistentnu privatnu SQLite kopiju u `storage/app/private/lavaggio-feedback-backups/` i primjenjuje plan unutar transakcije. Ne pokretati globalni seed/import preko sadržaja koji je klijent uređivao.

Plan deaktivira uvezenu samoposlužnu granu i cjenik (69, 87, 92, 154, 155), informativni poklon bon (322) i posrednu stranicu Cjenik (70). Uslužni cjenik (88) ostaje aktivan, dostupan izravno na `/cjenik`. Navigacija zadržava ostale korisnikove stavke. Opća webshop funkcija poklon bonova i postojeće narudžbe ne mijenjaju se.

Samo prepoznati izvorni tekstovi FAQ-a, načina plaćanja, radnog vremena i uvjeta praonice usklađuju se s prestankom samoposlužne usluge. Ako je urednik promijenio tekst, komanda ga čuva i prijavljuje za ručni pregled. Poveznice se prevode zasebno, bez prepisivanja ostalog sadržaja. Povijesne blog objave zadržavaju tekst, uključujući povijesne spomene ugašene usluge; nedostupne poveznice postaju običan tekst.

Na izričitu potvrdu korisnika svi postojeći iznosi i uređena tablica uslužnog cjenika ostaju isti. Mijenja se samo poznati tekst `Cijene vrijede od: 01.06.2026` u `Cijene vrijede od: 10.09.2026`. Nestandardne datume komanda ne nagađa. Prazno radno vrijeme u podnožju popunjava se s `Pon-Pet: 08h - 16h`; uređena vrijednost ostaje.

Hrvatske javne adrese vrijede samo za Lavaggio. Postojeća imena ruta ostaju, a hrvatski aliasi koriste iste kontrolere i pravila pristupa. Stare javne GET/HEAD adrese vode 301 na hrvatske, uz sačuvan upit. Ugašene stranice vraćaju 410 s poveznicom na kontakt i uslužni cjenik. Adrese platnih providera, callbackovi, administracija, API i potpisane obavijesti ostaju u postojećem protokolu. Drugi storefrontovi zadržavaju svoje adrese.

Za objavu slijedi projektni Git postupak iz `docs/lavaggio-cpanel-deployment.md`. Ova lokalna komanda nije postupak objave i ne pristupa poslužitelju.
