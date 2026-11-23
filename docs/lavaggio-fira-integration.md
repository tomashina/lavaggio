# Lavaggio FIRA ponude i računi

Početna vrsta dokumenta je **PONUDA**, a slanje ostaje isključeno dok administrator ne unese ključ i uključi izradu dokumenata. Automatska izrada kartičnih računa zasebna je postavka i početno je isključena. Spremanje postavki, stvaranje narudžbe, ručna promjena statusa i pregled dokumenta ne šalju dokument u FIRA-u. Omogućena automatika reagira samo na novu potvrđenu uspješnu kartičnu uplatu. Nema periodičkog izdavanja niti naknadnog slanja ranijih narudžbi pri spremanju postavki.

Administrator unosi tajni ključ u **Postavke → FIRA ponude i računi** (`/admin/settings/api/fira`). Ključ je šifriran postojećim aplikacijskim ključem i ne vraća se u stanje administratorskog obrasca. Prazno polje čuva postojeći ključ. Web domena je `lavaggio.hr`.

## Provjera ponudom

U postavkama ostavite **Ponuda za provjeru — bez fiskalizacije**, unesite ključ Custom integracije `lavaggioshop` i uključite izradu odabrane vrste dokumenta. Ponude se uvijek izrađuju ručno. Za njih nisu potrebne potvrde prijave prodajnog mjesta Poreznoj ni fiskalnog slijeda. Lokalno okruženje zabranjuje vanjsko slanje i u ovom načinu.

U detalju podržane narudžbe administrator s pravom izmjene narudžbi odabire stvarni način plaćanja, otvara **Pregledaj ponudu bez slanja**, provjerava podatke i izričito potvrđuje **Izradi ponudu u FIRA-i**. Šalje se službeni enum `invoiceType=PONUDA`; ta vrsta ne pokreće fiskalizaciju. Ponuda se sprema u stvarni FIRA račun, a FIRA je može poslati kupcu na `billingAddress.email` ako je ondje uključen `sendMailOffer`. Za probu u FIRA postavkama treba isključiti slanje ponuda e-poštom te provjeriti CC/BCC postavke. Ovo nije zaseban testni poslužitelj.

Ponudu po potrebi pretvorite u račun **izravno u FIRA-i**, odabirom „Napravi račun iz ponude” ili „Napravi fiskalni račun iz ponude”, prema [službenim FIRA uputama za webshop](https://fira.finance/hr/novosti/fiskalizacija-2-0-i-webshopovi/). Fiskalni račun izrađujte tek nakon prijave prodajnog mjesta i dovršene fiskalne konfiguracije. Trgovina ne pretvara ponudu automatski, ne dohvaća stanje te pretvorbe i ne šalje istu narudžbu ponovno. Ako ponudu obrišete u FIRA-i, lokalni zaštitni zapis i dalje ostaje; za novu provjeru koristite novu narudžbu.

## Fiskalni računi

Fiskalni način dodatno zahtijeva potvrđenu prijavu prodajnog mjesta Poreznoj, dovršene FIRA fiskalne postavke i potvrdu brojevnog slijeda `WS-1`, te uključenu izradu dokumenata. Ova izmjena ne postavlja te potvrde ni stvarne postavke trgovine. Slijed nije polje dokumentiranog API zahtjeva; mora se odabrati u FIRA integraciji `lavaggioshop` za odgovarajuće načine plaćanja. Slanje maila kupcu određuju FIRA postavke.

U fiskalnom načinu dokument je `FISKALNI_RAČUN`: njegovo izdavanje u FIRA-i pokreće fiskalizaciju. Poslužitelj prije slanja ponovno sastavlja pregledane podatke i uspoređuje sažetak cijelog zahtjeva, uključujući vrstu dokumenta. Promjena načina s ponude na fiskalni račun zahtijeva novi pregled i ne može iskoristiti prethodnu potvrdu ponude.

### Automatski račun nakon kartične uplate

Opcija **Automatski izradi račun nakon potvrđene kartične uplate** (`auto_card_invoices_enabled`) početno je isključena i na novim i na postojećim instalacijama. Može se spremiti kao uključena samo uz odabrani `FISKALNI_RAČUN`, uključenu izradu dokumenata te obje potvrde fiskalne konfiguracije i prijave prodajnog mjesta. Izrada dodatno provjerava postojeće zabrane slanja, uključujući ključ i lokalno okruženje.

Kada je opcija uključena, nova uspješna kartična potvrda može izraditi fiskalni račun čim je uplata potvrđena. Bankovne transakcije i ponude ostaju ručne. Samo označavanje narudžbe plaćenom u administraciji ne pokreće ovu kartičnu automatiku. Spremanje ili uključivanje postavke ne pretražuje i ne šalje stare narudžbe. Ako automatska izrada nije moguća, potrebno je provjeriti narudžbu i FIRA zapis; ne mijenja se potvrđeni ishod kartične naplate i nema slijepog ponavljanja slanja.

CorvusPay i WSPay pokreću izradu tek nakon potvrđene potpisane uplate i dovršenog spremanja transakcije (`afterCommit`). Provjerava se da izvorni zahtjev kartičnom procesoru ima način `live`, istu narudžbu i isti iznos; CorvusPay zahtijeva i istu valutu. Testne uplate i potvrde bez tih podataka ne pokreću fiskalizaciju. Automatski pokušaj koristi istu jedinstvenu evidenciju kao ručni, s praznim `requested_by`; pogreška ostaje u evidenciji odnosno sažetom zapisu za administratora, bez tajnog ključa ili podataka kupca.

Postavka je dio postojećeg zapisa `lavaggio_fira`; nije potrebna nova migracija. Poziv spremanja koji izostavi novu opciju sprema je isključenom, pa stariji obrazac ne može prešutno zadržati automatsko fiskaliziranje. Promjena u način `PONUDA` uz uključenu automatiku odbija se dok administrator ne isključi opciju.

## Iznosi i zaštita

### Dostava i predautorizacija (17. studenoga 2026.)

**Postavke → Lokalno → Dostava** sadrže uređive neto cijene, prikaz MPC-a s PDV-om, stopu PDV-a, FIRA SKU, KPD, granice težine, osnovicu i prag besplatne dostave te opciju **Uvijek naplati dostavu**. Početni unos iz odobrene tablice postavlja 100001 / GLS do 40 kg / 5,90 €; 100002 / GLS paketomat do 20 kg / 4,90 €; 100003 / BOX NOW do 20 kg / 3,90 €; 100004 / GLS preko 40 do 80 kg / 24,90 €; 100005 / paleta preko 80 kg / 99,90 €. Svi iznosi u ovom popisu uključuju 25% PDV-a, KPD je 53.20.09. Prve tri usluge besplatne su tek iznad 70 € vrijednosti robe s PDV-om nakon popusta. Točno 70 € još se naplaćuje. Teške i paletne pošiljke izuzete su iz općeg besplatnog praga i oznaka besplatne dostave na artiklima.

Narudžba sprema cijene i šifre dostave iz administratorskih postavki. FIRA za dostavu dobiva cijenu s PDV-om, poreznu stopu iz narudžbe, `lineItemId` za prikaz šifre, `productCode` za povezivanje sa šifrarnikom i `kpdCode`. Za starije narudžbe koje nemaju spremljene šifre koriste se identifikatori iz postavki dostave. Stavka dostave iskazuje se kada je dostava naplaćena.

CorvusPay **Predautorizacija — ručna potvrda naplate** šalje `require_complete=true`. Potpisani uspjeh tada evidentira rezervaciju bez `paid_at` i bez automatskog FIRA računa. Trgovac potvrđuje completion u CorvusPay portalu, zatim označi narudžbu plaćenom i ručno izda račun kroz postojeći FIRA panel. Webshop zasad ne sinkronizira completion iz portala.

Za svaku stavku FIRA prima spremljenu cijenu nakon popusta s uključenim PDV-om (`taxesIncluded=true`), količinu i poreznu stopu u decimalnom obliku. Dostava se iskazuje zasebno, također s PDV-om. Ako se popust u centima ne može ravnomjerno podijeliti na sve komade jedne šifre, šalju se dvije jedinične cijene s razlikom od jednog centa. Zbroj poslanih stavki mora odgovarati ukupnom iznosu narudžbe do centa. Fiskalni račun plaćen bankovnom uplatom šalje i `prepaidAmount`.

Podržane su B2C i B2B narudžbe nastale naplatom trgovine, u eurima, s pozitivnim iznosom i usklađenim poreznim zbrojevima. Poslovni kupac prepoznaje se po spremljenoj oznaci B2B ili nazivu tvrtke u adresi naplate, uključujući kupnju na tvrtku s B2C korisničkog računa. Naziv tvrtke i OIB obavezni su; hrvatski OIB mora imati 11 znamenki. Podaci dolaze iz narudžbe, ne iz naknadno promijenjenog korisničkog profila. Pregled prikazuje naziv tvrtke i OIB, a zahtjev šalje `billingAddress.company`, `billingAddress.oib` i `b2bClient=true`. Privatni kupac ostaje `b2bClient=false` i ako je unio osobni OIB. Promjena poslovnih podataka nakon pregleda zahtijeva novi pregled.

Ponude podržavaju sve postojeće načine plaćanja i za poslovne kupce. B2B fiskalni računi podržani su za karticu i gotovinu. B2B bankovno plaćanje u fiskalnom načinu zaustavlja se s uputom za ponudu i daljnju obradu eRačuna u FIRA-i; webshop ne izdaje eRačune. Živa OpenAPI specifikacija provjerena je 17.11.2026.: `b2bClient` određuje prijavu OIB-a poslovnog kupca kod fiskalnih računa, a za ostale vrste dokumenta FIRA ga zanemaruje. Poslovni identitet ponude prenosi se adresnim poljima tvrtke i OIB-a.

Popusti na cijelu košaricu, poklon bonovi, naknade plaćanja i ručno unesene narudžbe zahtijevaju ručnu obradu u FIRA-i. Za starije narudžbe s PDV-om zaokruženim po komadu integracija po potrebi razdvaja količinu iste šifre tako da i neto iznos i PDV na računu odgovaraju spremljenoj narudžbi. Nepodudaranje koje takav izračun ne objašnjava zaustavlja slanje.

Jedinstven zapis po narudžbi u `lavaggio_fira_documents` nastaje prije HTTP zahtjeva i sadrži odabranu vrstu dokumenta. Ponovljeni klik, paralelan zahtjev ili kasnija promjena vrste ne može ponovno poslati istu narudžbu. Izrađena ponuda time blokira i kasniju zasebnu izradu fiskalnog računa iz trgovine: ponuda se pretvara u račun ručno u FIRA-i.

Nema automatskog ponavljanja ni praćenja preusmjeravanja. Prekid veze, nepoznat odgovor i greška zadržavaju zapis neizvjesnog ishoda: administrator mora provjeriti narudžbu u FIRA-i. Ne postoji automatsko brisanje tog zapisa niti gumb za ponovno izdavanje. Fiskalizacija računa prikazuje se kao potvrđena samo uz zaprimljeni JIR; fiskalni dokument bez JIR-a može postojati i FIRA ga može naknadno fiskalizirati. Ponude se prikazuju kao ponude, bez statusa JIR-a.

Koristi se isključivo fiksni HTTPS endpoint i zaglavlje `FIRA-Api-Key`. Ne bilježe se proizvoljna tijela odgovora, pristupni ključ ni puna tijela zahtjeva. Čuvaju se identitet narudžbe/operatera, vrsta dokumenta, sažetak podataka, status pokušaja i ograničeni podaci o potvrđenom dokumentu. Nema dohvata drugih FIRA dokumenata ili izmjene FIRA kataloga. Dohvat cijena opisan je u nastavku.

Ugovor je provjeren 9. studenoga 2026. prema službenoj živoj specifikaciji v1.5.1:

- [Službene FIRA upute](https://app.fira.finance/webshop-docs/quickstart)
- [Živa OpenAPI specifikacija](https://app.fira.finance/api/v1/webshop/openapi.yaml)
- [Opis webshop integracije](https://fira.finance/hr/funkcionalnosti/webshop/)
- [Službena preporuka testiranja ponudom](https://fira.finance/hr/funkcionalnosti/api/)

Stariji primjer na marketinškoj stranici ima starije podatke o vremenskom formatu i skromniji opis; za implementaciju se koristi živa specifikacija. Provjere ove izmjene koriste isključivo lažne HTTP odgovore. Stvarna provjera ponudom moguća je nakon korisnikova unosa ključa i uključenja izrade ponuda; fiskalizacija i dalje čeka prijavu prodajnog mjesta i zasebnu aktivaciju.

## Cijene iz FIRA-e (16. studenoga 2026.)

`GET /api/v1/webshop/erp/products` dohvaća aktivni šifrarnik uz postojeći šifrirani ključ. Na FIRA integraciji mora biti uključeno **Dozvoli dohvat podataka**. Za ovaj smjer sinkronizacije nije potrebno uključivati izmjenu šifrarnika.

**Postavke → FIRA ponude i računi → Preuzmi cijene sada** ažurira postojeće artikle. Zasebna opcija omogućuje dohvat dvaput dnevno (08:00 i 20:00 po vremenu poslužitelja) kroz Laravel scheduler ili zasebni cron. CLI pregled bez izmjena: `php artisan lavaggio:sync-fira-prices`; primjena: ista naredba uz `--apply`. Lokalni raspored ne pokreće automatski vanjske pozive. Spremanje postavki ne pokreće dohvat. Na cPanelu se koristi zasebni cron s `--apply --scheduled`, koji poštuje spremljenu postavku i ne uključuje druge Laravel zadatke.

Povezivanje je po točnom SKU-u (`productNumber` u FIRA-i), odnosno šifri artikla samo ako nema SKU. Varijante koriste vlastiti SKU. Duplicirane šifre, nepoznati artikli, neusklađeni PDV, neispravne cijene i druge valute se preskaču i broje u rezultatu. Cijena je `grossPrice` kada trgovina sprema cijene s PDV-om, odnosno `netPrice` kada sprema neto cijene. Live odgovor od 16.11.2026. u `taxPercentage` za dio artikala vraća iznos PDV-a umjesto postotka. Taj se oblik prihvaća samo ako iznos odgovara razlici bruto/neto cijene i obje cijene odgovaraju poreznoj stopi trgovine (uz toleranciju jednog centa zbog zaokruživanja). Dohvaćaju se sve stranice prije izmjena; nepotpun dohvat ništa ne mijenja. Najviše 100 stranica po 100 artikala. Promjene ulaze u postojeću povijest cijena. Opisi, slike, atributi, akcije, količine i cijena na dan ulistanja ostaju sačuvani. Nema stvaranja ni brisanja artikala i nema slanja računa.

**Zalihe još nisu spojene.** U javnoj specifikaciji v1.5.1 i stvarnom odgovoru od 16.11.2026. u 10:13 Europe/Zagreb ni popis ni provjereni detalj artikla ne sadrže polje količine. Dohvat popisa vratio je HTTP 200 i 73 artikla; svih 48 SKU-ova webshopa ima podudaranje. Potreban je dokumentiran izvor skladišnog stanja, s definicijom skladišta i raspoložive količine. Izostalo stanje ne pretvara se u nulu.

## Bazne cijene i brojevni slijedovi

Stranica `/stranica/bazne-cijene` povezuje važeće cjenike webshop robe i objavljenih usluga praonice na stalnim adresama `/cjenici/roba.csv`, `/cjenici/roba.xml`, `/cjenici/usluge.csv` i `/cjenici/usluge.xml`. Ranije objave trgovca dostupne su i u FIRA arhivi. Maloprodajna cijena i oznaka akcije dolaze iz javnog B2C obračuna. Količina sadržaja određuje cijenu po litri, kilogramu ili komadu.

Naredba `php artisan lavaggio:publish-price-lists` provjerava promjene i objavljuje novu verziju odmah, uključujući više izmjena istog dana. Na cPanelu se pokreće svake minute. Povijest u `storage/app/private/lavaggio-price-lists` treba uključiti u redovnu sigurnosnu kopiju. Rok čuvanja je najmanje 15 dana od trenutka zamjene.

Potvrđene povijesne cijene od 16. studenoga uspoređene su po šifri s objavama Bareda d.o.o. u FIRA-i. Dokaz za novi artikl ili ispravak može se evidentirati u `products.payload.retail_price_evidence` s izvorom (`source`), skupinom (`household` ili `other`), danom prve ponude (`first_offered_on`), datumom povijesne cijene (`price_on`, datumi ISO), oznakom akcije (`on_promotion`, boolean) i iznosom s PDV-om. Za redovnu cijenu polje je `retail_price`. Za akciju u skupini sredstava za čišćenje, kućanstvo i drugih skupina iz čl. 3. st. 1. podst. 1. i 2. unosi se provjereni minimum u 30 dana prije početka akcije (`lowest_30_before_promotion`). Za ostale skupine na akciji unosi se prethodno primijenjena cijena (`previous_retail_price`). Nepotvrđena cijena ostaje prazna u izvozu, a naredba navodi šifre za provjeru.

Migracija `2026_11_16_120000_update_lavaggio_listing_date` samo na Lavaggiu mijenja datum ulistanja 13.11.2026. u 16.11.2026. Cijene i drugi datumi ostaju sačuvani. Postupak deploya prije izmjene snima pogođene datume i bazne cijene.

Oznake su **WS-1 za fiskalne račune F1** i **WS-2 za eRačune F2**. Stvarni slijed određuje FIRA postavka integracije, a ne polje zahtjeva webshopa. Ova promjena usklađuje oznake u administraciji i ne uključuje automatsko izdavanje dokumenata. Integracija izdaje ponude za B2C i B2B kupce, B2C fiskalne račune te B2B fiskalne račune za karticu i gotovinu; eRačune obrađuje administrator izravno u FIRA-i.
