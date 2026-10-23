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

Provjereno je i arhiviranje 490 pregledanih proizvoda kupaca, 143 košarice, 37 napuštenih košarica s 283 pripadajuća sadržaja, 96 obavijesti, 9 dodatnih zapisa narudžbi te povijesti slanja zahtjeva za ocjene (38 + 377 redaka). Izvorni OpenCart moduli nemaju automatski izvršiv Laravel ekvivalent: stari rasporedi i podaci ostaju sačuvani, a lokalni prikaz koristi kopirani Laravel predložak. Lozinke administratora, aktivne API sesije, produkcijski integracijski ključevi i tehnički logovi ne aktiviraju se u novoj aplikaciji. Modulima se u aplikacijskoj arhivi čuvaju identiteti; potpune postavke ostaju u privatnom SQL-u.

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

Poklon bon je aktivan u navigaciji i na `/poklon-bon`; slanje stvarnih poruka ostaje isključeno. Početna zadržava Liber Media slajder recenzija i prikazuje 37 odobrenih komentara proizvoda, s ocjenama i poveznicama na artikle. Četiri neodobrena komentara ostaju skrivena. Isto vrijedi za stranicu svih iskustava kupaca. Završne provjere tog prikaza: 17 testova, 168 provjera. Lokalna početna, katalog, proizvod, blog, info stranice, prijave, poklon bon i iskustva kupaca vraćaju uspješan odgovor.
