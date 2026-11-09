# Lavaggio FIRA ponude i računi

Početna vrsta dokumenta je **PONUDA**, a slanje ostaje isključeno dok administrator ne unese ključ i uključi izradu dokumenata. Automatska izrada kartičnih računa zasebna je postavka i početno je isključena. Spremanje postavki, stvaranje narudžbe, ručna promjena statusa i pregled dokumenta ne šalju dokument u FIRA-u. Omogućena automatika reagira samo na novu potvrđenu uspješnu kartičnu uplatu. Nema periodičkog izdavanja niti naknadnog slanja ranijih narudžbi pri spremanju postavki.

Administrator unosi tajni ključ u **Postavke → FIRA ponude i računi** (`/admin/settings/api/fira`). Ključ je šifriran postojećim aplikacijskim ključem i ne vraća se u stanje administratorskog obrasca. Prazno polje čuva postojeći ključ. Web domena je `lavaggio.hr`.

## Provjera ponudom

U postavkama ostavite **Ponuda za provjeru — bez fiskalizacije**, unesite ključ Custom integracije `lavaggioshop` i uključite izradu odabrane vrste dokumenta. Ponude se uvijek izrađuju ručno. Za njih nisu potrebne potvrde prijave prodajnog mjesta Poreznoj ni fiskalnog slijeda. Lokalno okruženje zabranjuje vanjsko slanje i u ovom načinu.

U detalju podržane narudžbe administrator s pravom izmjene narudžbi odabire stvarni način plaćanja, otvara **Pregledaj ponudu bez slanja**, provjerava podatke i izričito potvrđuje **Izradi ponudu u FIRA-i**. Šalje se službeni enum `invoiceType=PONUDA`; ta vrsta ne pokreće fiskalizaciju. Ponuda se sprema u stvarni FIRA račun, a FIRA je može poslati kupcu na `billingAddress.email` ako je ondje uključen `sendMailOffer`. Za probu u FIRA postavkama treba isključiti slanje ponuda e-poštom te provjeriti CC/BCC postavke. Ovo nije zaseban testni poslužitelj.

Ponudu po potrebi pretvorite u račun **izravno u FIRA-i**, odabirom „Napravi račun iz ponude” ili „Napravi fiskalni račun iz ponude”, prema [službenim FIRA uputama za webshop](https://fira.finance/hr/novosti/fiskalizacija-2-0-i-webshopovi/). Fiskalni račun izrađujte tek nakon prijave prodajnog mjesta i dovršene fiskalne konfiguracije. Trgovina ne pretvara ponudu automatski, ne dohvaća stanje te pretvorbe i ne šalje istu narudžbu ponovno. Ako ponudu obrišete u FIRA-i, lokalni zaštitni zapis i dalje ostaje; za novu provjeru koristite novu narudžbu.

## Fiskalni računi

Fiskalni način dodatno zahtijeva potvrđenu prijavu prodajnog mjesta Poreznoj, dovršene FIRA fiskalne postavke i potvrdu brojevnog slijeda `1-web-1`, te uključenu izradu dokumenata. Ova izmjena ne postavlja te potvrde ni stvarne postavke trgovine. Slijed nije polje dokumentiranog API zahtjeva; mora se odabrati u FIRA integraciji `lavaggioshop` za odgovarajuće načine plaćanja. Slanje maila kupcu određuju FIRA postavke.

U fiskalnom načinu dokument je `FISKALNI_RAČUN`: njegovo izdavanje u FIRA-i pokreće fiskalizaciju. Poslužitelj prije slanja ponovno sastavlja pregledane podatke i uspoređuje sažetak cijelog zahtjeva, uključujući vrstu dokumenta. Promjena načina s ponude na fiskalni račun zahtijeva novi pregled i ne može iskoristiti prethodnu potvrdu ponude.

### Automatski račun nakon kartične uplate

Opcija **Automatski izradi račun nakon potvrđene kartične uplate** (`auto_card_invoices_enabled`) početno je isključena i na novim i na postojećim instalacijama. Može se spremiti kao uključena samo uz odabrani `FISKALNI_RAČUN`, uključenu izradu dokumenata te obje potvrde fiskalne konfiguracije i prijave prodajnog mjesta. Izrada dodatno provjerava postojeće zabrane slanja, uključujući ključ i lokalno okruženje.

Kada je opcija uključena, nova uspješna kartična potvrda može izraditi fiskalni račun čim je uplata potvrđena. Bankovne transakcije i ponude ostaju ručne. Samo označavanje narudžbe plaćenom u administraciji ne pokreće ovu kartičnu automatiku. Spremanje ili uključivanje postavke ne pretražuje i ne šalje stare narudžbe. Ako automatska izrada nije moguća, potrebno je provjeriti narudžbu i FIRA zapis; ne mijenja se potvrđeni ishod kartične naplate i nema slijepog ponavljanja slanja.

CorvusPay i WSPay pokreću izradu tek nakon potvrđene potpisane uplate i dovršenog spremanja transakcije (`afterCommit`). Provjerava se da izvorni zahtjev kartičnom procesoru ima način `live`, istu narudžbu i isti iznos; CorvusPay zahtijeva i istu valutu. Testne uplate i potvrde bez tih podataka ne pokreću fiskalizaciju. Automatski pokušaj koristi istu jedinstvenu evidenciju kao ručni, s praznim `requested_by`; pogreška ostaje u evidenciji odnosno sažetom zapisu za administratora, bez tajnog ključa ili podataka kupca.

Postavka je dio postojećeg zapisa `lavaggio_fira`; nije potrebna nova migracija. Poziv spremanja koji izostavi novu opciju sprema je isključenom, pa stariji obrazac ne može prešutno zadržati automatsko fiskaliziranje. Promjena u način `PONUDA` uz uključenu automatiku odbija se dok administrator ne isključi opciju.

## Iznosi i zaštita

Iznosi dolaze iz spremljene narudžbe, bez ponovnog računanja aktualnih cijena proizvoda. Šalju se cijene nakon popusta na stavku, bez PDV-a (`taxesIncluded=false`), porezne stope u decimalnom obliku i zasebna stavka dostave. Neto, PDV i bruto moraju se podudarati sa snimkom narudžbe. Fiskalna narudžba označena plaćenom s odabranom bankovnom transakcijom šalje dokumentirani `prepaidAmount`; ponude ga ne šalju.

Trenutno su podržane B2C narudžbe nastale naplatom trgovine, u eurima, s pozitivnim iznosom i usklađenim poreznim zbrojevima. Popusti na cijelu košaricu, poklon bonovi, naknade plaćanja bez potvrđenog poreznog tretmana, B2B, stare/ručno unesene narudžbe i narudžbe s nepodudarnim zaokruživanjem PDV-a zahtijevaju ručnu obradu u FIRA-i. Ne stvaraju se pretpostavljene porezne korekcije. Košarica zaokružuje porez po jedinici, pa se na nekim višestrukim količinama izvorni snimak može razlikovati od FIRA izračuna po stavci; pregled to prijavljuje prije slanja.

Jedinstven zapis po narudžbi u `lavaggio_fira_documents` nastaje prije HTTP zahtjeva i sadrži odabranu vrstu dokumenta. Ponovljeni klik, paralelan zahtjev ili kasnija promjena vrste ne može ponovno poslati istu narudžbu. Izrađena ponuda time blokira i kasniju zasebnu izradu fiskalnog računa iz trgovine: ponuda se pretvara u račun ručno u FIRA-i.

Nema automatskog ponavljanja ni praćenja preusmjeravanja. Prekid veze, nepoznat odgovor i greška zadržavaju zapis neizvjesnog ishoda: administrator mora provjeriti narudžbu u FIRA-i. Ne postoji automatsko brisanje tog zapisa niti gumb za ponovno izdavanje. Fiskalizacija računa prikazuje se kao potvrđena samo uz zaprimljeni JIR; fiskalni dokument bez JIR-a može postojati i FIRA ga može naknadno fiskalizirati. Ponude se prikazuju kao ponude, bez statusa JIR-a.

Koristi se isključivo fiksni HTTPS endpoint i zaglavlje `FIRA-Api-Key`. Ne bilježe se proizvoljna tijela odgovora, pristupni ključ ni puna tijela zahtjeva. Čuvaju se identitet narudžbe/operatera, vrsta dokumenta, sažetak podataka, status pokušaja i ograničeni podaci o potvrđenom dokumentu. Nema dohvata drugih FIRA dokumenata ili izmjene FIRA kataloga.

Ugovor je provjeren 9. studenoga 2026. prema službenoj živoj specifikaciji v1.5.1:

- [Službene FIRA upute](https://app.fira.finance/webshop-docs/quickstart)
- [Živa OpenAPI specifikacija](https://app.fira.finance/api/v1/webshop/openapi.yaml)
- [Opis webshop integracije](https://fira.finance/hr/funkcionalnosti/webshop/)
- [Službena preporuka testiranja ponudom](https://fira.finance/hr/funkcionalnosti/api/)

Stariji primjer na marketinškoj stranici ima starije podatke o vremenskom formatu i skromniji opis; za implementaciju se koristi živa specifikacija. Provjere ove izmjene koriste isključivo lažne HTTP odgovore. Stvarna provjera ponudom moguća je nakon korisnikova unosa ključa i uključenja izrade ponuda; fiskalizacija i dalje čeka prijavu prodajnog mjesta i zasebnu aktivaciju.
