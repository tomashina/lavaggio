# Watchline — lokalni prijenos podataka

Provjereno 23. listopada 2026. Aplikacija je kopija projekta `libermedia-new`, s vlastitom lokalnom bazom i Watchline podacima. Produkcijski OpenCart i izvorni projekti nisu mijenjani.

## Izvor i ponovljivo pokretanje

- OpenCart 2.3.0.2, prefiks `oc_`, hrvatski `language_id=2`, aktivna valuta EUR.
- Izvor je `~/Desktop/watchlin_watch.sql`: 44.289.124 bajta, 210 tablica.
- SHA-256 izvornog SQL-a: `0c56b9bdbb4538865730ab33c98581a1d793ac895dc64f616bd50f49c8b78a2f`.
- SQL se samo čitao. Lokalna veza `watchline_source` dodatno postavlja `SET SESSION TRANSACTION READ ONLY`; upisi idu u zasebnu aplikacijsku bazu. SQL, baze, privatni podaci i `.env` ostaju izvan Gita.
- `php artisan watchline:import --dry-run` provjerava izvor bez upisa. `php artisan watchline:import` prenosi osnovne i dodatne objekte u jednoj ciljnoj transakciji. `php artisan watchline:import --supplement-only` ponavlja samo dodatni prijenos, normalizaciju sadržaja i promocije.
- Ponovljeni dodatni prijenos čuva naknadne lokalne izmjene, moderiranje komentara i odluke o newsletteru. Ne šalje poruke, ne naplaćuje, ne ponavlja stare narudžbe i ne mijenja zalihe kao novu kupnju.

## Usklađeni osnovni podaci

| Podaci | Izvor | Preneseno | Objašnjenje |
| --- | ---: | ---: | --- |
| Proizvodi i hrvatski opisi | 6.763 | 6.763 | 2.510 aktivnih; izvorne zalihe, cijene, opcije, atributi i statusi sačuvani |
| Kategorije i hrvatski opisi | 25 | 25 | Sačuvana hijerarhija i veze proizvoda |
| Brandovi i prijevodi | 48 | 48 | 42 imaju neprazan opis ili SEO podatak; preostalih 6 je prazno i u izvoru |
| Kupci i profili | 630 | 630 | Povijesne vjerodajnice koriste postojeći verifier; dodatni lokalni administrator nije izvorni kupac |
| Izvorne adrese | 693 | 693 | Uz njih je pripremljeno 630 adresa za račun; ukupno 1.323 adrese |
| Narudžbe | 2.211 | 2.211 | Brojevi imaju prefiks `OC-WATCHLINE-` |
| Stavke narudžbi | 2.276 | 2.276 | Izvorni `model` koristi se kada OpenCart nema stupac `sku` |
| Zbrojevi narudžbi | 7.313 | 7.313 | Sačuvani izvorni iznosi i valute |
| Povijest statusa narudžbi | 2.500 | 2.500 | Sačuvani komentari i datumi |
| Info stranice i hrvatski sadržaji | 6 | 6 | Izvorni sadržaj, SEO i vidljivost |
| Blog zapisi | 27 | 27 | 23 hrvatska sadržaja; 4 stara ogledna engleska sadržaja imaju obrisani izvorni jezik pa su arhivirani i neobjavljeni |
| Liste želja | 181 | 179 | 2 veze upućuju na proizvode koji ne postoje u SQL-u; izvorne veze ostaju arhivirane |
| SEO aliasi | 6.851 | 6.851 | OpenCart 2 `url_alias` pretvoren u postojeći sustav starih URL-ova |

Svih 6.763 izvornih proizvoda koristi poreznu klasu 0. Prijenos čuva njihove EUR cijene bez dodavanja pretpostavljenog PDV-a. 443 ponovljene izvorne oznake artikla dobile su jedinstveni nastavak `-oc-ID`; originalne oznake ostaju u privatnim izvornim podacima proizvoda.

## Dodatni sadržaj i povijest

| Podaci | Rezultat |
| --- | --- |
| Ocjene proizvoda | Svih 41 u aktivnom sustavu komentara, s ocjenama, autorima, datumima i izvornim statusom moderiranja |
| Iskustva kupnje | Svih 6 u sustavu komentara kao ocjene trgovine |
| Blog komentari | Izvor nema zapisa; prijenos podržava taj tip komentara |
| Pitanje o proizvodu | 1 izvorno pitanje i odgovor arhivirani; vezani proizvod ne postoji u izvornom katalogu |
| Newsletter | 2.219 jedinstvenih zapisa: 1.838 zasebnih prijava + 407 kupaca s newsletter oznakom − 26 preklapanja; ne izmišlja se pristanak za stare prijave koje nemaju takav podatak |
| Dodatni filtri | 6 grupa, 826 vrijednosti i svih 23.899 važećih veza; dodatnih 51 izvornih veza upućuje na nepostojeće proizvode i ostaje arhivirano |
| Blog i preporučeni proizvodi | Svih 47 važećih veza; 1 od 48 izvornih veza upućuje na nepostojeći proizvod i ostaje arhivirana |
| Bodovi kupaca | Sva 4 povijesna zapisa u postojećoj knjizi bodova |
| Opcije starih narudžbi | Svih 5 sačuvano uz odgovarajuće stavke narudžbe, uključujući izvorni naziv, vrijednost i tip |
| Napomene o kupcima | 1 izvorni zapis uz profil kupca i u arhivi |
| Kuponi | Svih 49 kao pravila s izvornim ograničenjima, ciljevima i 431 zapisom korištenja; duple izvorne veze proizvoda ne stvaraju duple ciljeve |
| Poklon bon | 1 bon i 1 iskorištavanje: početni iznos 150 EUR, izvorna povijest −150 EUR, preostali iznos 0 EUR; kod se čuva šifrirano |
| Povrati | Svih 12 povrata i 3 zapisa povijesti, s razlozima i statusima; povezani s postojećim narudžbama/profilima, a sačuvani i kada nedostaje stara narudžba |
| BoxNow | Svih 36 povijesnih zapisa o paketomatima/pošiljkama sačuvano kao privatni podaci |
| Referentne cijene | 2.509 potvrđenih cijena uz postojeće proizvode; 1 dodatna potvrđena cijena upućuje na nepostojeći proizvod; svih 6.764 izvornih redaka ostaje arhivirano |

Kuponi su preneseni isključeni jer naslijeđeni Laravel checkout još ne provodi izvorno ograničenje korištenja po kupcu ni zapisivanje novog korištenja. Izvorna pravila i povijest dostupni su za dovršetak te funkcije. Povrati, opcije narudžbi i dodatna OpenCart povijest čuvaju se kao strukturirani podaci uz zapise i u arhivi; time se ne stvaraju nove reklamacije ili povrati novca.

Referentne cijene imaju izvorni datum i prikazuju se kao **Cijena na datum**. Nisu preimenovane u najnižu cijenu posljednjih 30 dana. 4.254 nepotvrđene izvorne referentne cijene ostaju u arhivi. Provjera svih 2.510 aktivnih proizvoda prema izvornom OpenCart odabiru aktualne akcije dala je **0 razlika u cijeni**; 1.601 proizvod imao je aktualnu izvornu akciju.

## Arhiva i preostale granice

`herrera_source_records` zadržava izvorne dodatne objekte i povijest: reference i revizije cijena, bannere, rasporede, veze trgovina, kanonske URL-ove, brand opise, konfiguraciju filtara i kategorija, kupovne napomene i bodove, newsletter redove, košarice, partnerske podatke, opcije narudžbi, pošiljke, povrate, bonove, pitanja, iskustva kupnje, predloške atributa te OpenCart prodajna pravila. Puna izvorna baza svih 210 tablica ostaje u zasebnom SQL-u.

Provjereno je i arhiviranje 490 pregledanih proizvoda kupaca, 143 košarice, 37 napuštenih košarica s 283 pripadajuća sadržaja, 96 obavijesti, 9 dodatnih zapisa narudžbi te povijesti slanja zahtjeva za ocjene (38 + 377 redaka). Početni uvoz sačuvao je stare rasporede i podatke bez izvršavanja OpenCart modula. Naknadno su dodani izvorni Laravel poslovi za GATH, Wolt, recenzije i napuštene košarice, opisani u nastavku. Početni uvoz nije aktivirao API sesije ni produkcijske integracije. Stvarna naplata ostaje blokirana lokalnim sigurnim načinom. Modulima se u aplikacijskoj arhivi čuvaju identiteti; potpune postavke ostaju u privatnom SQL-u.

Izvorna četiri aktivna administratorska računa prenesena su zasebnim Watchline staff uvozom. Prijava na `/login` prihvaća izvorno korisničko ime ili e-mail s postojećom lozinkom. Izvorni salted SHA-1 hash i salt čuvaju se šifrirano u verifikatoru kompatibilnosti; uspješna prva prijava zamjenjuje ih Laravel hashom. Kupci i osoblje ostaju odvojeni čak i kada koriste isti e-mail. Lokalni pričuvni administrator zadržava svoju lozinku. Uvoz ne pohranjuje lozinke, hash ili salt u javne profile ili Git i ne mijenja izvorne račune.

Arhiva sadrži i tehničke zapise vlasništva nad uvozom te može čuvati više izvornih prikaza istog retka. Zato se njezin ukupan broj redaka ne koristi kao broj kupaca, narudžbi ili ostalih poslovnih objekata.

Nakon završnog SEO prijenosa 2.597 starih adresa ima aktivno odredište, a 4.254 zadržavaju izvorno neaktivno odredište. Stari obrazac povrata preusmjerava na lokalnu formu. Početni SEO pregled imao je 4.255 neriješenih odredišta: 4.253 neaktivna proizvoda, 1 neaktivan blog zapis i stari put `account/return/add`. Stari put za povrat sada ima preslikavanje na postojeću lokalnu formu `/forma-za-povrat-i-reklamacije`; nakon ponavljanja SEO faze ostaju samo 4.254 namjerno nedostupna zapisa. **Nijedan aktivan proizvod nema neriješeno odredište.**

## Provjere

- `HerreraOpenCartImportTest`: **43 testa, 291 provjera**; uključuje OpenCart 2 alias tablicu, hrvatski jezik, nulti porez, povijesne narudžbe, ponavljanje i zaštitu lokalnih izmjena.
- `WatchlineSupplementImportTest` + `WatchlineLegacySalesImportServiceTest`: **12 testova, 74 provjere**; komentari, moderiranje, newsletter, filtri, odnosi, datirane cijene, dupli ciljevi kupona, iskorišten bon i povrati.
- `WatchlineFoundationTest`, provjere odabira teme i Libermedia početne te `ProductPricePresentationTest`: **26 testova, 258 provjera**; kopirani raspored, normalizacija sadržaja, promocije i prikaz izvornih cijena.
- Stilska provjera svih novih uvoznih servisa, naredbe i pripadajućih testova prolazi.
- Završni kombinirani skup prije posljednje provjere starog puta za povrat: **112 testova, 692 provjere**. Preslikavanje starog puta za povrat dodatno je provjereno u `HerreraLegacyUrlTest`.
- Završno usklađivanje brojeva rađeno je čitanjem SQL-a i aplikacijske baze otvorene u načinu samo za čitanje, bez ispisivanja osobnih podataka.

## Završni lokalni prikaz

### Dostava i plaćanje

`watchline:configure-checkout --snapshot=watchline/checkout-source.json` prenosi postavke iz privatnog izvornog snimka. Alternativno čita lokalnu izvornu bazu preko veze samo za čitanje. Naredba je ograničena na lokalno/testno okruženje. Ponovljeno pokretanje čuva naknadne administratorske izmjene; `--force` izričito vraća izvorne postavke. Privatna sigurnosna kopija prethodnih zapisa nalazi se u `.local/watchline-checkout-before.json`. Snimak i kopija nisu u Gitu.

| Dostava | Uvjeti |
| --- | --- |
| GLS dostava | Hrvatska, 4,50 EUR; besplatno od 70 EUR |
| GLS Shipping | Slovenija, Austrija i Njemačka, 19 EUR |
| Osobno preuzimanje | Importanne Centar Zagreb, besplatno |
| BOX NOW paketomat | Hrvatska, besplatno; preneseni izvorni partner i API podaci |
| GLS paketomat | Dodana lokalna opcija za Hrvatsku, 4,50 EUR; besplatno od 70 EUR |

Prag dostave uzima aktualne cijene artikala nakon njihovih akcija, prije popusta na cijelu košaricu, kao izvorni xshipping modul. Ne koristi stare cijene prije akcije. Ograničenja dimenzija ostaju stroga za generičke BOX NOW metode; izvorna Watchline kompatibilnost izričito dopušta stare artikle bez upisanih mjera. Ne izmišljaju se težine ili dimenzije.

Uključeni su bankovna uplata, pouzeće, plaćanje pri osobnom preuzimanju, WSPay kartice i zasebna opcija za narudžbu s ukupnim iznosom nula. KEKS Pay ostaje **isključen kao na izvorniku**, s prenesenim postavkama. Izvorni minimalni iznosi bankovne uplate i pouzeća računaju se nakon bona, s dostavom i porezom, bez naknade za plaćanje. Bankovne upute čuvaju oba izvorna računa; ne generira se QR s proizvoljno odabranim IBAN-om.

KEKS izvorna potvrda koristi parametre `user` i `pass`; preneseni su privatno, provjeravaju se oba i ne spremaju u zapis transakcije. Naredba `--keks-callback-only` mijenja samo taj ugovor potvrde, bez uključivanja metode ili mijenjanja drugih dostava i plaćanja. Stvarna KEKS naplata i registracija javnog povratnog URL-a nisu potvrđene.

Uređivanje: **Dostava** (`/admin/shipping`) i **Postavke → Lokalno → Načini plaćanja** (`/admin/settings/local/payment-methods`). `commerce.local_safe_mode` ostaje uključen: lokalni pregled ne pokreće WSPay/KEKS naplatu. GLS karta ne treba API ključ; automatsko stvaranje GLS naljepnica treba MyGLS pristupne podatke kojih u izvorniku nema. Ne šalju se zahtjevi za stvaranje pošiljaka.

BOX NOW se prikazuje u vlastitom dijalogu preko cijelog zaslona s dostupnim zatvaranjem. Kontrole i odabrana lokacija paketomata nalaze se unutar odgovarajuće odabrane opcije dostave; osvježavanje cijena čuva iste kontrole i odabrane podatke. Svijetloplava zaglavlja, tablice košarice i sažeci koriste isti fiksni W uzorak. Watchline favicon koristi W iz izvornog vektorskog logotipa, sa SVG, PNG i ICO zamjenama.

Završni zajednički skup provjera dostave, naplate, povezanih artikala i promo kartica: **126 testova, 1.318 provjera**. Dodatno su provjereni službena BOX NOW konfiguracija i odabir JavaScript testovima te stvarni prikaz karata na računalu i mobitelu. Lokalna probna košarica očišćena je nakon provjere; nisu stvorene stvarne narudžbe ili naplate.

### Poklon bon i preporuke

Watchline poklon bon koristi vlastiti logotip i tekst za satove, naočale i nakit, uključujući predloške e-poruka. Početni iznos je 50 EUR, uz zadržan izbor uredno poslanog iznosa. Kupnja bona prihvaća samo konfigurirana kartična plaćanja; ista se provjera provodi na poslužitelju. Redovna kupnja artikala zadržava ostale načine plaćanja. U navigaciji je Font Awesome ikona poklona, a promotivne kartice u kategorijama i povezanim proizvodima nude samo poklon bon. Naslov iznad preporuka u košarici glasi „Odabrano za vas”.

Povezani proizvodi za Watchline dolaze iz istog brenda i poredani su po zbroju prodanih komada u dovršenim/plaćenim narudžbama, uz isključivanje otkazanih narudžbi. Broj pregleda ili ručna oznaka „Popularno” ne određuju taj redoslijed. Primjenjuju se postojeće postavke vidljivosti zalihe; kada nema prodaje, redoslijed je stabilan.

Na artiklu je odmah vidljivo prvih šest objedinjenih specifikacija. Ostale su u zatvorenom izvornom HTML elementu `details` pod „Ostale specifikacije”, s plus/minus oznakom i podrškom za tipkovnicu. Kraći popisi nemaju praznu sklopku; podaci i uklanjanje duplikata ostaju nepromijenjeni. Blok dostave na artiklu koristi aktivne Watchline metode i njihove uvjete, bez naslijeđenog filtra za Liber Media uvoz i bez stvaranja košarice ili upita dostavljaču. Prikazuje svih pet konfiguriranih usluga. Provjereno na Fossil ES4649 na desktopu i mobitelu, uz postojeće regresije specifikacija i nove provjere prikaza dostave.

Naslov, šifra i brand artikla imaju kompaktniji raspored. Aktualna cijena, popust i prekrižena stara cijena dijele red; porezni i referentni podaci ostaju čitljivi uz prirodno prelamanje. Na CLUSE CW16504 visina gornjeg bloka na širini 1440 px smanjena je s 320 na 155 px; provjeren je i mobilni prikaz širine 375 px bez vodoravnog preljeva.

### Kategorije i preostali prikaz

Iznad proizvoda na prve dvije razine kategorija prikazuju se svi aktivni brendovi zastupljeni u toj kategoriji i njezinim potomcima. Treća i dublje razine, primjerice Ženski satovi Cluse, nemaju taj gornji blok brendova. Taj izbor ne ovisi o trenutačno uključenim filtrima. Logotip otvara stranicu brenda uz parametar `category` s izvornom kategorijom i njezinim potomcima; primjerice Casio iz Muških satova prikazuje samo muške Casio satove. Brand stranica bez tog parametra i dalje prikazuje cijeli brand. Bez logotipa prikazuje se naziv. Broj nije ograničen na prvi vidljivi red. Provjereno je svih 28 brendova u kategoriji Satovi i 20 u Ženskim satovima, uključujući prikaz na širini 375 px bez vodoravnog preljeva.

Treća razina kategorija skrivena je samo iz prezentacijskih popisa: desktop/mobilne navigacije, bočnih filtara, kartica, prijedloga kategorija i vidljivih putanja. Hijerarhija, statusi, veze artikala, izvorni SEO URL-ovi te izravni naslovi i proizvodi tih odredišta ostaju sačuvani. Uređivanje/popis podataka u administraciji i puni SEO podaci nisu uklonjeni. Poveznica „Natrag na Satovi” koristi istu plavu i svijetloplavu pozadinu.

Facebook i Instagram imaju vidljive nazive uz ikone na desktopu te sivu boju ostalih poveznica gornje trake. Blog naslovne slike i slike unutar teksta pune širinu članka uz zadržane proporcije; potvrđena je naslovna slika članka Seiko Presage na punih 896 px.

Zaseban završni skup za kategorije, navigaciju, povezane artikle, favicon, poklon bon i preporuke košarice: **56 testova, 563 provjere**. Službeni BOX NOW odabir, adresa s gradom, zatvaranje i povrat fokusa dodatno su provjereni s 4 JavaScript testa i u pregledniku; Escape i izbor lokacije rade na mobilnom prikazu. Stvarne e-poruke i naplate nisu pokretane.

### Uređivanje početne kroz widgete

Sadržaj početne sada se prikazuje isključivo iz položaja `home.content`. U administraciji **Sadržaj → Blokovi** nalazi se pregled „Početna — raspored blokova” s pomicanjem gore/dolje, uključivanjem i uređivanjem svakog bloka. Prednosti kupnje u podnožju koriste zaseban položaj `footer.benefits` na svim stranicama.

Osam Watchline tipova koristi fiksne Blade predloške i uređiva polja u bazi: banneri, proizvodi, lokacije, brandovi, recenzije, blog, prednosti i podrška. Spremanje tih widgeta ne zapisuje predloške ni inline CSS. Tekstovi, poveznice, ikone i slike uređuju se kroz obična polja; slike se mogu izravno prenijeti kao JPG, PNG ili WebP do 5 MB. Napredni podaci o položaju i rasporedu ostaju u zatvorenim postavkama.

CLUSE i GUESS zadržavaju automatski odabir najprodavanijih odnosno najnovijih dostupnih modela. U widgetu se može odabrati ručni popis i promijeniti poredak proizvoda. Brandovi podržavaju ručni ili automatski izbor, recenzije odobrene ocjene 1–5 te opciju istaknutih, a blog posljednje objavljene, istaknute ili odabrane članke. Blok „Vaš izbor. Naša podrška.” ostaje isključen na zahtjev korisnika.

Svaki widget proizvoda može kombinirati više kategorija i brandova. Unutar svakog popisa vrijedi „bilo koji”, a kategorije i brandovi međusobno se presijecaju. Dostupni su uključivanje potkategorija, samo zaliha, samo akcije, samo popularni artikli te izbor redoslijeda i broja proizvoda. Filtri se primjenjuju prije ograničenja broja artikala. Automatski izvor „Najveći popusti” uzima samo trenutno primjenjive javne akcije proizvoda, kategorija ili brandova i uspoređuje stvarni postotak popusta prema zajedničkom obračunu cijene. Lokalni blok „Akcije i popusti” koristi taj izvor.

Izvor „Automatski iz kategorija i brandova” ne traži ručni odabir artikala. Prazan ručni widget prelazi na njega kada urednik odabere kategorije ili brandove; postojeći ručni popis ostaje ručan dok ima odabranih artikala. Padajući izbornici postavki koriste standardne select kontrole da prikazana opcija ostane usklađena s Livewire stanjem nakon promjene filtera. Ručni pretraživač prikazuje do 100 rezultata, ali traži po cijelom katalogu i zadržava ranije odabrane artikle.

U uređivanju artikla postoje neovisni checkboxi „Novo u ponudi” i „Popularno”, spremljeni kao boolean vrijednosti `payload.storefront.is_new` i `payload.storefront.is_popular`. Izvor widgeta „Novo u ponudi (oznaka Novo)” prikazuje samo označene artikle; „Najnoviji artikli” koristi datum dodavanja. „Samo popularni artikli” dodatno sužava bilo koji izvor. Nijedan postojeći proizvod nije automatski označen, a GUESS zadržava odabir najnovijih modela po datumu.

`watchline:configure-home` prenosi početni sadržaj i položaje samo jednom. Ponovljeno pokretanje čuva uređene tekstove, slike, popise, redoslijed i isključene blokove. Opcija `--force` namijenjena je izričitom vraćanju početnih postavki. Prije lokalnog prijenosa spremljena je privatna sigurnosna kopija prethodnih widget zapisa u `.local/watchline-widgets-before.json`.

Poklon bon je aktivan u navigaciji i na `/poklon-bon`; slanje stvarnih poruka ostaje isključeno. Početna zadržava Liber Media slajder recenzija i prikazuje 37 odobrenih komentara proizvoda, s ocjenama i poveznicama na artikle. Četiri neodobrena komentara ostaju skrivena. Isto vrijedi za stranicu svih iskustava kupaca. Završne provjere tog prikaza: 17 testova, 168 provjera. Lokalna početna, katalog, proizvod, blog, info stranice, prijave, poklon bon i iskustva kupaca vraćaju uspješan odgovor.

### Vodič za poklone i newsletter dobrodošlica

„Tražiš poklon?” koristi `/savjeti-za-poklone` i vlastiti Watchline prikaz. Poveznica s ikonom poklona nalazi se ispred poklon bona u desktop i mobilnoj navigaciji; dodana je bez resetiranja postojećih stavki. Filtrira stvarnu ponudu prema primatelju, vrsti artikla, budžetu i brandu. Odabir primatelja temelji se na postojećim ženskim, muškim i dječjim kategorijama, bez izmišljanja podjele za nakit i ostale nerazvrstane artikle. Budžet i redoslijed cijene koriste aktualnu cijenu s akcijom. Brand opcije prate ostale odabire; zaliha, akcije, prikaz stupaca i straničenje čuvaju cijeli odabir. Dodatno je ponuđen Watchline poklon bon. Izvorni Liber Media vodič ostaje nepromijenjen.

Nova prijava na newsletter uz privolu može dobiti vlastiti kod za dodatnih 10% na prvu kupnju. Koristi izvorni sustav akcija košarice, vrijedi i nakon postojećeg popusta na artikl, nema izmišljeni rok isteka i dopušta jedno iskorištavanje. Kod se veže uz e-mail, provjerava ranije kupnje i povezane račune te se ponovno provjerava i rezervira unutar transakcije završetka narudžbe. Ponovljena prijava ne izdaje novi kod ni ponovno šalje istu poruku. Povijesni zajednički WELCOME10 zapis nije aktivan.

Postavke **Newsletter** uređuju naslov, podnaslov, gumb, uključivanje koda i postotak. Lokalno su `store_newsletter_coupon_enabled=true`, postotak 10 i kuponi košarice uključeni. `store_email_enabled=false` i sigurnosni način ostaju uključeni; blokirani su stvarni mailovi i Mailchimp/Klaviyo pozivi. Nijedan stvarni pretplatnik nije dodan tijekom testiranja. Pokušaj ponovnog slanja postojećeg nedostavljenog koda dostupan je operateru kroz idempotentni servis, bez izdavanja nove akcije.

Newsletter naslov je „Poklanjamo vam dodatnih 10% popusta”, podnaslov „Prijavite se na newsletter i vaš osobni kod za popust stiže e-mailom.”, a gumb „Želim svoj kod”. CLUSE banner ima jednoredni naslov „CLUSE ženski satovi”; isti je naslov ažuriran u widget postavkama, ostaje uredljiv kroz admin i prilagođava veličinu manjim zaslonima. Rečenica „Iskustva iz prve ruke, za lakši odabir.” uklonjena je iz Watchline bloka recenzija i početnog predloška sadržaja.

### Filtri i pomicanje kataloga

Watchline koristi pet stvarnih grupa atributa: Boja kućišta, Vodootpornost, Promjer kućišta, Mehanizam i Kolekcija. Prikazuju se samo grupe i vrijednosti prisutne u aktivnoj ponudi odabrane kategorije i njezinih potomaka, uz postojeće postavke zalihe. Kolekcija objedinjuje izvorne grupe satova, naočala i nakita; jednaka imena ne stvaraju više izbora. Broji se različit proizvod, a ne broj veza atributa. Trenutačni odabir pojedinog filtra ne uklanja njegove ostale mogućnosti; ostali filtri ga sužavaju. Ne stvara se dodatni indeks ni kopija atributa u proizvodima.

Na desktopu od 1025 px bočni filtri ostaju ispod stvarne visine zaglavlja, uz vlastito pomicanje kada su viši od zaslona. Mobilni prikaz zadržava postojeće kontrole. U pregledniku potvrđeno: Ženski satovi 873 → 309 za 5 ATM / 50 M; Naočale 273 → 2 za ACTIVE LIFESTYLE. Na naočalama se ne prikazuju četiri grupe specifične za satove. Sve aktivne Watchline kartice poklon bona koriste Font Awesome `gift`, uključujući kategorije i povezane proizvode.

### GATH, Wolt i cronovi

Administracija **Integracije → GATH i automatizacije** (`/admin/integrations/watchline`) zamjenjuje nepovezane naslijeđene poveznice za dobavljače/e-Račune/EPREL u Watchline meniju. Koristi postojeću ovlast `integrations.stock.manage`; Excel/CSV i skupni uvoz slika naknadno su uklonjeni iz Watchline menija na zahtjev korisnika; ručno uređivanje medija artikla i GATH obrada ostaju dostupni. Posebna stranica **Wolt povezivanje** (`/admin/integrations/wolt`) uređuje vezu i ponudu. Dostupni su pregled, izričita ručna primjena, rasporedi i zajednička povijest izvršavanja bez pristupnih podataka ili primatelja poruka.

Stvarne skripte iz produkcijskog cPanela preuzete su samo za čitanje, privatno i izvan Gita. Nisu otvarani javni cron URL-ovi, izvođene stare PHP skripte ni mijenjana produkcija. API podaci u novoj bazi šifrirani su aplikacijskim ključem i ne vraćaju se u uređiva polja.

#### GATH

Izvorni `agm_api/updateQuantityFromCsv` čita lokalni `webstanje.csv`: šifra, vlastita količina, osnovna cijena EUR i brand, odvojeni točkom sa zarezom. Zasebni `gath.php` sadrži SOAP dijagnostički poziv; nije izvorni cron koji ažurira artikle. Korisnik navodi da GATH datoteku dostavlja na server, vjerojatno FTP-om. Nova stranica je potrošač iste automatski dostavljene datoteke; ručno učitavanje je dodatna mogućnost u administraciji, a ne obvezni korak.

Prije produkcije treba potvrditi postojeći način dostave i **stvarnu putanju koju ERP redovito osvježava**. Tu putanju postaviti u GATH postavkama; privatni lokalni snimak nije trajni produkcijski izvor. Web potrošaču ne trebaju FTP pristupni podaci ako ih za dostavu već koristi ERP. Ne pretpostavlja se nova SOAP struktura niti izmišlja API mapiranje.

`GathSyncService::preview()` ne mijenja katalog. `apply()` spaja izvornu SKU oznaku i ažurira osnovnu cijenu, vlastitu zalihu i aktivnost kao izvorna obrada, uz zapis povijesti cijene i izvršavanja. Čuva akcije i objavljene/ručne cjenike. Osvježava samo pripadajuće uvezene bazne stavke nacrta cjenika. Ne stvara proizvode za šifre kojih nema u novoj bazi. BOM se uklanja samo kada postoji; početni razmaci šifre ostaju značajni, kao u staroj bazi. Jednaki dupli SKU-jevi zadržavaju izvorno podudaranje s više proizvoda.

Prazan, oštećen, proturječan ili potpuno nepovezan izvještaj ne može poništiti zalihu. Poništavanje odsutnih vlastitih zaliha zasebna je isključena opcija za potpune izvještaje. Primjena iz admina zahtijeva pregled istog sadržaja datoteke te iste putanje i postavke poništavanja. Zaključavanja usklađuju obradu s postojećim uvozima. Produkcijski snimak od 23.10.2026. ima 1.248 redaka, povezuje 738 šifri sa 742 proizvoda, 510 šifri nema ciljni artikl; pregled pokazuje **0 promjena cijene, zalihe ili aktivnosti**. Stari lokalni snimak znatno je stariji i nije primijenjen.

Naredba `php artisan watchline:gath-sync` zadano samo pregledava; `--file` odabire datoteku, `--apply` izričito primjenjuje, a `--reset-missing` izričito omogućuje poništavanje. Zajednički `watchline:integrations-run gath` također zadano samo pregledava.

#### Wolt

Potvrđen je stvarni ugovor stare integracije: `index.php` šalje `model → sku` i `quantity → inventory` na `/items/inventory`; `inventory.php` šalje `model → sku`, osnovnu cijenu u centima te `enabled = quantity > 0` na `/items`. Oba koriste isto razriješeno prodajno mjesto i Basic autentikaciju. Imena datoteka ne opisuju njihov stvarni sadržaj. Prenesene postavke čuvaju izvorni model, osnovnu cijenu, fizičku zalihu i sve uvezene proizvode, uključujući neaktivne, bez uključivanja zalihe dobavljača.

Pregled inventara ima 6.761 jedinstvenu šifru. Jednaki izvozni retci spajaju se kao izvorni SQL `DISTINCT`. Dva stara neaktivna artikla bez zalihe dijele model `SAILBRACE PAPERSHIP SB1169`, ali imaju različite osnovne cijene. Cijela konfliktna grupa izostavlja se iz izvoza cijena, uz upozorenje i poveznice za uređivanje; preostalih **6.760 valjanih stavki** ostaje spremno za izvoz. Za inventar ta šifra ima jednoznačnu količinu 0. Ne odabire se proizvoljna cijena.

Šalju se samo promijenjeni retci; prihvaćena polazna vrijednost sprema se tek nakon HTTP 202. Zasebna zaključavanja i ograničenja štite pozive: cijene 15 minuta, inventar 5 minuta, uz obradu 429 i izostanak automatskog ponavljanja neizvjesnog slanja. Dopušteni su samo službeni fiksni Wolt hostovi, bez praćenja preusmjerenja s vjerodajnicama. Dostupan je i ručni OAuth token uz provjeru isteka. Veza ažurira postojeći Wolt asortiman; ne kreira nove Wolt artikle i ne uvozi narudžbe, kao izvorne dvije skripte. Pristup, mapiranje i slanje početno su isključeni. Lokalni način ne može slati vanjske izmjene ni kada se uključe pojedine postavke.

#### Poruke i rasporedi

Pozivi za recenzije zadržavaju izvorno pravilo: 7 dana nakon narudžbe, uz odabrani preneseni status 5. Napuštene košarice koriste predložak 7 i 60 minuta neaktivnosti; kupon od 10% vrijedi 7 dana samo za artikle iz te košarice i samo za jedno iskorištavanje. Novi Laravel mehanizam vodi zapis slanja i sprječava duplikate; potpisane poveznice omogućuju povrat košarice i odjavu. Obrađuju se samo novi događaji nakon uključivanja. Povijesne uvezene narudžbe i košarice ne šalju se ponovno. Napuštena košarica prati kontakt samo kada je posao uključen i postoji evidentirana privola; isključena automatizacija ne prikuplja dodatni kontakt. Lokalno slanje i slanje kada je `store_email_enabled=false` blokirani su i u samom servisu.

| Posao | Preneseni raspored, Europe/Zagreb |
| --- | --- |
| GATH CSV | `7,37 * * * *` |
| Wolt cijene/dostupnost, stari inventory.php | `12,32,52 * * * *` |
| Wolt inventar, stari index.php | `15,35,55 * * * *` |
| Recenzije | `22 11 * * *` |
| Napuštene košarice | `21 3,7,11,15,19,23 * * *` |

Svi rasporedi i glavni prekidač `watchline_integrations.live_enabled` početno su isključeni. Laravel svake minute razmatra uključene poslove; produkcijski uvjet, lokalni sigurnosni način, zaključavanje i oznaka već obrađene minute sprječavaju neželjena ili dvostruka izvršavanja. Za server treba jedan uobičajeni Laravel cron `* * * * * cd /PUTANJA/PROJEKTA && /PUTANJA/PHP artisan schedule:run >> /dev/null 2>&1`. Zatim potvrditi putanju ERP datoteke, Wolt vezu/asortiman, postavke pošte i uključiti željene poslove. Pri prelasku zamijeniti stare cronove kako stara i nova stranica ne bi paralelno slale iste obrade. To se ne postavlja desktop automatizacijom i nijedan produkcijski cron nije uključen tijekom lokalnog rada.

`watchline:integrations-run {job}` zadano je pregled. `--apply` izričito pokreće posao; vanjsko slanje i dalje zahtijeva sve produkcijske postavke. `--due --apply` razmatra samo uključene dospjele rasporede. Administracija prikazuje uvjete i primjer server crona.

Završni zajednički skup novih integracija, pet filtara i promo kartica: **64 testa, 564 provjere**, bez stvarnih vanjskih zahtjeva. Dodatne postojeće provjere prikaza dostave, naplate i kupnje bona prošle su u zasebnom skupu. Izgradnja aplikacijskih resursa, kompilacija Blade predložaka i `git diff --check` prolaze. U pregledniku provjereni su GATH pregled s nultim promjenama, Wolt pregled s navedenim konfliktom, stvarna promjena filtrirane ponude i sticky bočni filtri. Lokalni snimci zaslona nalaze se u ignoriranoj mapi `.local/`.

## SEO, AI pretraživanje i brzina — 23. listopada 2026.

Pregled obuhvaća svih **2.609 aktivnih javnih zapisa**: 2.510 proizvoda, 25 kategorija, 48 brandova, 20 blog članaka i 6 informativnih stranica. Svi imaju hrvatski naslov i valjanu, jedinstvenu URL oznaku. Prikaz je dodatno provjeren na 119 adresa, uključujući sve kategorije, brandove, objavljene članke i informativne stranice, glavne javne stranice te uzorke proizvoda i filtara. Svih 118 očekivanih javnih prikaza vraća 200, ima jedan glavni naslov, opis, kanonsku adresu i sintaktički valjan JSON-LD; namjerno nepostojeća adresa vraća 404. Naknadno je dodana provjera druge stranice recenzija. Provjereno je **1.122 različitih internih putanja**: 1.107 vodi na aktivne objekte, 15 na registrirane stranice, bez nepostojećih ciljeva. Svih 2.597 aktivnih starih preslikavanja i dalje ima valjan cilj; 4.254 povučena zapisa zadržava odgovarajuću nedostupnost.

### Primijenjene dorade

- Straničenje kataloga, bloga i recenzija ima vlastitu kanonsku adresu i broj stranice u naslovu. Parametri praćenja uklanjaju se iz kanonske adrese. Interne pretrage, filtri, sortiranje i privatne stranice označeni su za neindeksiranje; čiste javne stranice slijede glavnu postavku trgovine. Filtrirane adrese nisu blokirane pravilom za sve upite u `robots.txt`, pa tražilica može pročitati njihovu oznaku `noindex`.
- Brandovi, katalog, kategorije, blog, kontakt, recenzije, FAQ, poklon bon i vodič za poklone koriste odgovarajuće metapodatke. Prazan izvorni opis dobiva činjeničnu zamjenu iz postojećeg sadržaja ili naziva proizvoda. Skraćivanje poštuje granice riječi i hrvatske znakove. Dugi izvorni tekstovi u bazi nisu masovno prepisivani.
- Product/Offer podaci koriste stvarnu prikazanu akcijsku cijenu, raspoloživost i valjanu izvornu barkod oznaku. Ocjene obuhvaćaju samo objavljene recenzije odgovarajućeg jezika, bez odgovora i nevaljanih ocjena. Nema izmišljenih identifikatora, uvjeta povrata ili dostave. Autorski podaci bloga ne otkrivaju privatni administratorski identitet. JSON-LD sigurno kodira tekst. FAQ podaci na početnoj uklonjeni su jer ondje nema vidljivih pitanja i odgovora.
- Sitemap uključuje glavne javne ulazne stranice i poštuje uključene značajke. Skriveni treći nivo kategorija ostaje u sitemapu i dostupan preko svoje SEO adrese. Datum promjene uzima u obzir stvarnu izmjenu prijevoda; statične stranice ne dobivaju izmišljeni današnji datum pri svakom zahtjevu.
- Prvi vidljivi CLUSE naslov početne koristi H1, uz istu tipografiju i odobreni tekst. Ispravljeni su naslijeđeni Liber Media tekstovi na blogu i stranici recenzija te naziv trgovine na povučenim adresama.
- Ciljano su ispravljene tri potvrđene pogreške izvornih naziva: model `LC.SM.3.05` više nema naziv i metanaslov `LC.SM.3.04`; metanaslovi `ES5421` i `ES5435` više ne navode `ES5422` i `ES5434`. Šifre, adrese, cijene i zalihe ostaju sačuvani. Privatna sigurnosna kopija je `.local/watchline-model-titles-before-*.json`; nakon eventualnog potpunog ponovnog uvoza provjeriti te izvorne pogreške.
- Dodatne fotografije proizvoda učitavaju se pri stvarnom prelasku pokazivačem ili fokusu tipkovnice. Početna tako odgađa **34 različita izvorna zahtjeva** koji se na dodirnom zaslonu uglavnom ne koriste. Glavna fotografija ostaje vidljiva dok dodatna nije uspješno učitana. Učitavanje početnih važnih slika ostaje prioritetno.
- Watchline stilovi otkrivaju se izravno u zaglavlju umjesto preko dodatnog lanca CSS uvoza, uz sačuvan redoslijed. Stranice bez obavijesti o dostupnosti ne učitavaju njihove dodatne stilove i skripte. Newsletter CAPTCHA, kada je uključena, započinje učitavanje pri korištenju obrasca; slanje i dalje čeka provjeru.
- **53 slike u starijim člancima** dobile su pouzdan alternativni tekst iz stvarnog natpisa ili povezanog proizvoda. Slike unutar članka ispod naslovne slike učitavaju se odgođeno i dekodiraju asinkrono. Postojeći autorski opisi i prazni opisi ukrasnih slika ostaju sačuvani; za preostalih 30 slika bez pouzdanog konteksta potreban je urednički opis. Izvorni HTML sadržaja ostaje nepromijenjen u bazi.

### Izmjerena brzina

Uspoređen je lokalni Laravel prikaz istih šest javnih GET zahtjeva, prije i nakon popravka pretraživanja akcija, bez pohrane cijena između zahtjeva. Vrijednosti su ponovljeni prolazi lokalnog mjerenja; nisu Lighthouse ocjena, vrijeme mrežnog učitavanja niti terenski Core Web Vitals rezultati.

| Stranica | Prije | Nakon | Smanjenje vremena |
| --- | ---: | ---: | ---: |
| Početna | 1.577 ms | 519 ms | 67% |
| Ženski satovi | 553 ms | 350 ms | 37% |
| CLUSE brand | 438 ms | 230 ms | 47% |
| Uzorak proizvoda CW11812 | 209 ms | 145 ms | 31% |
| Blog članak | 17 ms | 17 ms | Bez promjene |
| Vodič za poklone, žene/satovi/do 100 € | 4.673 ms | 716 ms | 85% |

Uzrok je bio završni pregled svih 2.420 aktivnih akcija za svaki proizvod, iako je popis podudaranja već bio poznat. Izravni odabir podudaranja čuva redoslijed, prioritete i isključivost akcija. Neovisna provjera svih 2.510 aktivnih proizvoda daje **0 razlika** u uređenom popisu podudaranja. Izolirani pregled 1.000 proizvoda pao je s 3.960 na 50 ms uz istih 585 podudaranja. Tijekom usporedbe HTML svih šest stranica imao je isti broj bajtova; naknadne dorade metapodataka i resursa provjerene su zasebno.

### Prije produkcije i preostali sadržaj

Lokalni prikaz i dalje blokira indeksiranje. Blokada `.test`, localhost i IP adresa ostaje aktivna čak i ako se pogrešno odabere produkcijsko okruženje. Tek na stvarnoj produkcijskoj domeni treba provjeriti `APP_URL`, `APP_ENV`, glavnu postavku SEO robots i javni sitemap te prijaviti sitemap u Search Console/Bing Webmaster Tools.

Izvorne slike još uglavnom dolaze sa stare javne domene: od 7.082 različite referencirane kataloške putanje, 7.066 nema lokalni izvornik. To nije tvrdnja da je slika nedostupna; preglednik trenutačno koristi postojeći javni izvor. Pri konačnom prijenosu treba prenijeti izvorni `image/catalog` u novi `public/image/catalog`, sa sačuvanim velikim/malim slovima i razmacima. Zatim pripremiti manje izvedenice fotografija za kartice i ponovno izmjeriti mrežno učitavanje na produkcijskom hostingu. Za 376 proizvoda izvor ne sadrži tekst opisa; ondje treba urednički dopuniti provjerene podatke, umjesto izmišljanja značajki. Preostale slike bez opisa evidentirane su u privatnom popisu za urednički pregled.

Privatni detalji pregleda i mjerenja: `.local/watchline-public-catalog-inventory.json`, `.local/watchline-seo-render-audit.json`, `.local/watchline-public-link-audit.json`, `.local/watchline-article-image-repair-audit.json`, `.local/watchline-promotion-parity.json` te `storage/app/private/watchline/performance-{before,after}.jsonl`. Ne sadrže pristupne podatke niti zapise kupaca. Snimke završnog prikaza su `.local/watchline-seo-speed-home-desktop.jpg` i `.local/watchline-seo-speed-product-mobile.jpg`.

Završni kombinirani regresijski skup: **112 PHP testova, 1.010 provjera** te **8 JavaScript testova**. Uključuje SEO, sitemap, stare adrese, strukturu sadržaja, učitavanje slika, filtre, vodič za poklone, akcije, recenzije, newsletter popust i odvajanje predmemorije. Nakon dodatne provjere IPv6 zaštite lokalnog prikaza ciljano je ponovljen sitemap skup. Provjereni su desktop izgled početne na 1.440 px, mobilni proizvod na 375 px bez vodoravnog prelijevanja i učitavanje dodatne fotografije pri fokusu tipkovnice.

Dorade slijede službene smjernice za [Google AI prikaze](https://developers.google.com/search/docs/appearance/ai-features), [straničenje trgovina](https://developers.google.com/search/docs/specialty/ecommerce/pagination-and-incremental-page-loading), [podatke proizvoda](https://developers.google.com/search/docs/appearance/structured-data/merchant-listing), [sitemap](https://developers.google.com/search/docs/crawling-indexing/sitemaps/build-sitemap) i [prioritet učitavanja važnih slika](https://web.dev/articles/optimize-lcp). Time se poboljšava dostupnost i dosljednost sadržaja; ne obećava se pozicija u rezultatima niti uvrštavanje u AI odgovor.


## Dodatni pregled 25. listopada 2026.

- Ispravljeno učitavanje spremljenog SEO naslova i opisa artikala, numeracija javnih stranica i 404 za stranice izvan raspona. Zadani prikaz vodiča za poklone više ne dodaje nepotrebne zadane parametre u poveznice paginacije. Usklađena je vidljivost bloga i sitemapa te zabrana indeksiranja prijave i privatnih stranica. Lokalna zabrana indeksiranja ostaje uključena.
- Ponovljena lokalna mjerenja generiranja vodiča za poklone: medijan zadanog prikaza 1102 → 278 ms, filtriranog 673 → 509 ms, vršna memorija zadanog prikaza 106,5 → 66,5 MB. Sadržaj i redoslijed kartica očuvani. Na početnoj je uklonjeno 55 nepotrebnih upita; vrijeme generiranja ondje je ostalo približno jednako. To nisu terenska Core Web Vitals mjerenja niti rezultat produkcijske mreže.
- Nova provjera `watchline:preflight` samo čita konfiguraciju i originalne putanje aktivnog kataloga. Današnji popis sadrži 6998 jedinstvenih putanja: 15 lokalnih originala, 6983 nedostaje. To je uži opseg od ranijeg popisa svih kataloških slika; ne uključuje sve medije sadržaja i widgeta. Originale treba preseliti prije objave.
- E-mailovi su ujednačeni s Watchline shopom, uključujući raniju Liber Media obavijest o dostupnosti i poruke za lozinku. Lokalna galerija nudi 18 oglednih varijanti bez slanja ili upisa podataka.
- Pripremljeni su plan razlika i izolirana proba završnog osnovnog uvoza. **Svježi izvoz nije preuzet, stvarni finalni uvoz i objava nisu izvršeni.** Potpuni redoslijed, ograničenja dodatnih područja i povratak opisani su u [pripremi prelaska na live](watchline-live-cutover.md).
