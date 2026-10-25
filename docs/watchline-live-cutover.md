# Watchline — priprema završnog prijenosa i objave

Stanje 25. listopada 2026. Ovo je postupak za budući prelazak. Produkcija nije mijenjana, svježi SQL još nije preuzet i stvarni završni uvoz nije izvršen. Postojeći lokalni SQL nije dokaz današnjeg stanja stare trgovine.

## Što je pripremljeno

`watchline:final-sync` uspoređuje **izvorni zamrznuti snimak**, **novi zamrznuti snimak** i postojeći novi shop. Zadano samo planira promjene. S `--rehearse` stvara novu privatnu SQLite kopiju sadašnjeg shopa i na njoj provjerava osnovni prijenos dva puta. Sadašnja aplikacijska baza i oba izvora ostaju nepromijenjeni.

Osnovni prijenos obuhvaća artikle, kategorije, brandove, opcije, atribute, kupce, adrese, povijesne narudžbe, stare SEO poveznice i odnose. Koristi izvorne ID oznake i mapiranja, a ne samo šifru artikla ili broj redaka. Čuva naknadno uređena lokalna polja, slike, postojeće domaće narudžbe i nadograđene lozinke. Konflikt se prijavljuje umjesto tihog prepisivanja. Povijesna narudžba ne pokreće naplatu, slanje poruke ili ponovno skidanje zalihe.

Ovo **nije naredba za produkcijsku primjenu**: nema `--apply`, a izvještaj uvijek ima `ready_for_live=false`. Uz osnovni prijenos plan navodi promjene četiri dodatna područja: osoblje, akcije, dodatni sadržaji/filtri i naslijeđeni prodajni podaci. Njihov završni prijenos i provjera još moraju biti dovršeni prema stvarnim razlikama u svježem SQL-u. Ponovno pokretanje punog početnog uvoza ili prisilnog uvoza akcija nije zamjena za taj korak.

## Priprema unaprijed, dok stara trgovina radi

1. Sačuvati rezervnu kopiju sadašnjeg novog shopa: bazu, datoteke, učitane slike i aplikacijski ključ. Posebno čuvati privatne vjerodajnice i postojeći ključ za šifrirane integracije. SQL i privatne izvještaje ne spremati u Git niti u javnu web mapu.
2. Potvrditi datum i SHA-256 **originalnog** SQL-a korištenog pri početnom uvozu. Datum uvoza nije nužno datum izrade SQL snimka. Sačuvati taj snimak nepromijenjen.
3. Preuzeti novi konzistentni SQL sa stare trgovine i zabilježiti datum, vrijeme, zonu i SHA-256. U lokalni MySQL vratiti svaki snimak u zasebnu bazu naziva `watchline_snapshot_GGGGMMDD_oznaka`. Ne koristiti aktivnu produkcijsku bazu kao izvor niti novu kopiju nazivati starim imenom.
4. `watchline_source` treba pokazivati na originalni lokalni snimak. U privatnoj konfiguraciji postaviti `WATCHLINE_SOURCE_DATABASE` na njegovo ime. `WATCHLINE_FINAL_SOURCE_DATABASE` postaviti na ime zasebnog svježeg snimka. Nova veza `watchline_fresh_source` nasljeđuje lokalne pristupne podatke i način samo za čitanje; ne dodaje produkcijsku vezu.
5. Unaprijed preseliti originalne slike iz starog `image/catalog` u novi `public/image/catalog`. Sačuvati podmape, razmake i točna velika/mala slova. Prvo kopirati većinu, pred objavu samo završnu razliku. Sam SQL ne sadrži slikovne datoteke. Zasebno provjeriti slike u sadržajima, bannerima i widgetima te nove učitane medije.
6. Pokrenuti plan i probu ispod. Pregledati razlike i konflikte dok stari shop još radi. Zabilježiti trajanje izvoza, prijenosa, uvoza i provjera; vrijeme prekida odrediti prema toj probi, bez nagađanja.

Primjer naredbi za dva snimka od 23. i 25. listopada. Datume treba zamijeniti stvarnim datumima izrade snimaka i uskladiti ih s imenima baza:

```sh
php artisan watchline:final-sync --baseline-date=2026-10-23 --source-date=2026-10-25
php artisan watchline:final-sync --baseline-date=2026-10-23 --source-date=2026-10-25 --rehearse
php artisan watchline:preflight --profile=local --media-report
```

Izvještaji završnog uvoza nalaze se u `storage/app/private/watchline/final-sync/`. Probni zapis ima vlastitu mapu s `target.sqlite`, dokazom odvojenosti i `report.json`; ne otvarati tu bazu kao aktivnu aplikacijsku bazu. Plan i probni izvještaj sadrže sažete brojeve i otiske, bez adresa kupaca ili lozinki. Sama probna baza sadrži privatne podatke i mora ostati zaštićena.

| Status | Značenje i sljedeći korak |
| --- | --- |
| `planned` | Izvori i identiteti prošli su preduvjete za plan; slijedi proba. |
| `blocked` | Preduvjet nije prošao; ne nastavljati s prijenosom. |
| `core_rehearsed_review_required` | Osnovni prijenos, ponovljivost i provjere prošli su na kopiji; dodatna područja i objava još nisu odobreni ni završeni. |
| `rehearsal_blocked` | Konflikt, razlika u iznosima, očuvanju ili nepromjenjivosti; najprije riješiti konkretan nalaz. |
| `rehearsal_failed` | Proba je sigurno stala; izvještaj daje klasu greške bez privatnih SQL podataka. |

Prije objave dodatno treba uskladiti `pending_domains` i `remaining_gates`: akcije i datirane referentne cijene, newsletter/pristanke, recenzije, dodatne filtre, kupone, iskorištene poklon bonove, povrate i promjene osoblja. Promjena osnovne cijene traži i provjeru akcijske cijene, čak ako sam zapis akcije nije promijenjen. Izvorno obrisani redak mora se pregledati; ne provodi se masovno brisanje lokalnih podataka.

## Završni prozor prelaska

Ovaj dio se izvršava tek nakon uspješne probe i dovršetka navedenih dodatnih prijenosa.

1. Privremeno zaustaviti nove kupnje i administratorske upise na staroj trgovini. Pričekati i evidentirati otvorene naplate i povratne pozive pružatelja plaćanja. Zaustaviti stare cronove, izvoze za Wolt i slanje automatiziranih poruka.
2. Zabilježiti vrijeme zaključavanja, zadnju narudžbu i kontrolne ukupne iznose. Napraviti finalni konzistentni SQL i završnu razliku slikovnih datoteka. Ako se stara trgovina ponovno mijenja, snimak više nije završni i korak treba ponoviti.
3. Obaviti prethodno uvježbani prijenos na kandidat novog shopa. Pregledati nove i promijenjene artikle, kupce i narudžbe, statuse/valute/iznose/stavke, osoblje, akcije i sve dodatne razlike. Potvrditi da su lokalna uređivanja i prijave kupaca sačuvani, a ponavljanje ne stvara duplikate. Aktualna naredba proizvodi samo probni kandidat; završna promocija kandidata ostaje zaseban, pregledan korak.
4. Potvrditi lokalne slike i njihove izvedenice, build resursa, pristup datotekama, sigurnosne kopije i vezu aplikacije na konačnu bazu. Nakon konačnih postavki osvježiti konfiguracijski cache i cache javnih prikaza tako da se ne prenesu probni canonical URL-ovi ili stare meta oznake. Ne mijenjati ključ aplikacije bez zasebnog plana za šifrirane podatke.
5. Na produkcijskoj domeni potvrditi HTTPS, jedinstvenu glavnu domenu, isključeni debug, sesije i povratne URL-ove plaćanja/dostave. Provjeriti izvorne stare URL-ove i 301 preusmjeravanja, canonical, sitemap i robots. Uklanjanje zabrane indeksiranja odnosi se samo na javnu produkciju; lokalne i probne adrese zadržavaju zabranu.
6. Kontrolirano provjeriti stvarnu poštu, newsletter kupon i njegovu jednokratnu primjenu, potvrdu narudžbe, lozinku, bon i dostupnost. Pregledna galerija potvrđuje dizajn, a ne SMTP isporučivost. Prije stvarnog slanja provjeriti odobrenog primatelja, pošiljatelja, SPF/DKIM/DMARC i da povijesni uvoz nije stvorio red starih poruka za slanje.
7. Potvrditi stvarnu GATH putanju koju ERP osvježava, pregledati svježinu CSV-a i razlike zaliha/cijena. Potvrditi Wolt vjerodajnice, asortiman, identifikatore artikala i pregled prije prve primjene. Tek zatim uključiti odabrane nove rasporede i glavni prekidač. Stare rasporede ostaviti isključene da se obrade ne ponavljaju.
8. Izvršiti odobrenu završnu provjeru kupnje, dostave, naplate i potvrde pa otvoriti novu trgovinu kupcima. Pratiti nove narudžbe, greške, plaćanja, poštu i rezultate GATH/Wolt poslova.

Za server je predviđen jedan Laravel scheduler koji svake minute pokreće `artisan schedule:run`. Točna PHP izvršna datoteka i putanja projekta određuju se na stvarnom hostingu. Postojeći poslovi i zadani rasporedi opisani su u [bilješkama o integracijama](watchline-migration.md#gath-wolt-i-cronovi). Tijekom pripreme nije postavljen niti uključen produkcijski cron.

```sh
php artisan watchline:preflight --profile=live --json
```

Ova provjera samo čita konfiguraciju i originale slika aktivnog kataloga. `ready=true` u tom izvještaju **nije ukupno odobrenje za objavu**: ne potvrđuje dovršenost uvoza, SMTP isporuku, stvarnu naplatu, Wolt vezu, sve slike u sadržajima ni prihvaćanje nove narudžbe. Lokalno će live profil očekivano biti blokiran.

## Povratak ako provjera ne prođe

Prije prihvaćanja novih narudžbi vratiti prethodni release/bazu/usmjeravanje i stare rasporede uključiti tek nakon provjere da novi više ne rade. Oba izvoza i kandidat sačuvati radi usporedbe.

Nakon što novi shop prihvati narudžbe, ne vraćati staru bazu preko novih podataka. Najprije zaustaviti upise, sačuvati obje baze i uskladiti nove narudžbe, plaćanja i promjene zaliha. Povrat tada zahtijeva prijenos razlike, a ne samo vraćanje DNS-a.

## E-mailovi za pregled

Lokalna [galerija e-mailova](http://watchline-new.test/preview/watchline-mail) prikazuje 18 varijanti sa stvarnim predlošcima i izmišljenim podacima. Dostupni su prikazi računala i mobitela. U produkciji i drugim storefrontovima galerija vraća 404. Ni pregled poruke ni odabir varijante ne šalju poštu, ne čitaju kupce, ne izrađuju valjani token i ne mijenjaju bazu.

Poruke dijele Watchline logo u PNG obliku, plavu boju, svijetloplavu pozadinu, tipografiju i gumbe. Bitni stilovi upisani su u HTML elemente radi e-mail klijenata; izgled u Gmailu/Outlooku i stvarnu isporuku još treba provjeriti kontrolnom isporukom pred objavu.

Bankovna uplata koristi jedan korisnički potvrđeni račun Zagrebačke banke. Strukturirani podaci primatelja i tekst uputa spremljeni su u lokalnim postavkama metode plaćanja. Preflight provjerava njihovu potpunost bez ispisa bankovnih podataka. Produkcijsko generiranje HUB3/PDF417 koda koristi iznos i poziv na broj pojedine narudžbe; u lokalnom sigurnom načinu vanjski generator ostaje blokiran. Galerija zato prikazuje zaseban ogledni PDF417 koji sadrži samo tekst „OGLEDNI PRIKAZ - NIJE ZA UPLATU”. Isti prikaz koda podržavaju obična potvrda narudžbe i neplaćena kupnja bona; u stvarnoj pošti slika se ugrađuje kao inline privitak. Stvarna isporuka i skeniranje produkcijske uplatnice provjeravaju se pred objavu.

| Skupina | Pregledi |
| --- | --- |
| Narudžbe | Potvrda kupcu, obavijest trgovini, uplata na račun, BOX NOW |
| Poklon bon | Kupnja prije/nakon uplate, bon primatelju s porukom i kodom |
| Obavijesti | Osobni newsletter kupon, ponovna dostupnost |
| Automatizacije | Poziv na recenziju, napuštena košarica |
| Povrati i kontakt | Potvrda/obavijest o raskidu, kontakt, stariji zahtjev za povrat |
| Račun | Obnova lozinke, potvrda e-mail adrese; postojeći status aktivacije potvrde ostaje nepromijenjen |
| Neaktivni modul | Ponuda kupcu; pregled predloška ne uključuje modul ponuda |
