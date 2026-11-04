# Lavaggio početna kroz widgete

Početna se sastoji isključivo od uređenih `ContentBlock` zapisa u poziciji `home.content`. U administraciji **Sadržaj → Blokovi** dostupni su raspored, uključivanje/isključivanje i uređivanje svakog widgeta. Uredničke promjene ne zahtijevaju izmjenu predloška. Novi Lavaggio blok može biti samo jedan od sedam tipova naslovnice: uvodni banneri, odabir prema problemu, kategorije i kompleti, kolekcija proizvoda, brandovi, komentari kupaca ili blog i TV studio. Novi obrazac započinje kolekcijom proizvoda; postojeći stariji blokovi zadržavaju mogućnost uređivanja svog spremljenog tipa.

Na desktopu od 1024 px aktivni promo blok i odmah sljedeći aktivni blok odabira prema problemu dijele red u omjeru 50/50. Obje vanjske kartice imaju jednaku ukupnu visinu koja se prilagođava sadržaju; desna obuhvaća naslov, opis i šest kartica u dva stupca. Na manjim ekranima blokovi se prikazuju jedan ispod drugog s automatskom visinom. Ako se između njih postavi drugi blok, promijeni njihov redoslijed ili se jedan isključi, preostali sadržaj zadržava zadani redoslijed i koristi puni red. Isto vrijedi kada nema vidljivih promo slajdova ili problemskih kartica.

| Widget | Sadržaj koji se uređuje |
| --- | --- |
| Promo slajdovi | Do 12 promocija s fotografijom, opisom slike, naslovom, kratkim tekstom, gumbom i poveznicom ili prikazom samo fotografije; redoslijed i vidljivost svakog slajda |
| Odabir prema problemu | Šest kartica: naziv, ikona, namjena, neobavezna vlastita poveznica, redoslijed i vidljivost |
| Kategorije i kompleti | Pet cijelih fotografija proizvoda s ikonama, nazivima i poveznicama, bez opisa; šesti blok prikazuje komplete s ikonom, naslovom u jednom redu i poveznicom |
| Kolekcije proizvoda, primjerice Novo u ponudi | Više zasebnih blokova s vlastitim naslovom; automatski ili ručni odabir, kategorije i brandovi, broj artikala i broj vidljivih stupaca po veličini ekrana |
| Brandovi | Aktivni brandovi s logotipima ili ručni odabir i redoslijed; broj vidljivih logotipa |
| Artikli na akciji | Aktualne akcijske cijene iz kataloga, isti izbor i raspored kao za ostale proizvode |
| Komentari kupaca | Odobreni komentari stvarnih kupaca; broj, filtriranje izdvojenih komentara i poruka prije prve recenzije |
| Blog i TV studio | Najnoviji objavljeni članci/video objave ili odabrani članci; naslov, poveznica i broj objava |

Komentari se nalaze neposredno iznad bloga. Kao i brandovi, prikazuju se u traci pune širine ekrana sa svijetlom Lavaggio pozadinom i službenim uzorkom; kartice komentara ostaju poravnate s ostalim sadržajem početne.

Prikaz komentara ne stvara ogledne ocjene. Dok nema odobrenih komentara, prikazuje se uredljiva informativna poruka. Proizvodi na akciji koriste postojeća pravila promocija; widget ne stvara dodatne popuste.

Kolekcije koriste postojeći tip **Lavaggio — kolekcija proizvoda**. Svaki takav blok ima vlastiti naslov, izbor, redoslijed i vidljivost. Podržani su ručni izbor, najnoviji artikli, automatski izbor iz kategorija i brendova, artikli na akciji te artikli s oznakom Novo. Naziv poput „Najtraženije u posljednjih 7 dana” sam po sebi ne računa prodaju ili pretrage; sedmodnevni rang nije dodan.

`php artisan lavaggio:configure-home` stvara sedam lokalnih widgeta. Ponovno pokretanje čuva postojeće tekstove, redoslijed i vidljivost, kao i druge blokove. Opcija `--force` izričito vraća početne postavke ovih sedam widgeta. Naredba je ograničena na lokalnu Lavaggio temu.

Za novi blok odabira prema problemu postoji zasebna naredba koja ne resetira ostale widgete:

```bash
php artisan lavaggio:configure-problem-widget
php artisan lavaggio:configure-problem-widget --apply
```

Bez `--apply` prikazuje samo plan. Primjena dopušta isključivo provjerenu lokalnu Lavaggio bazu `database/database.sqlite`, provjerava stvarnu SQLite vezu i odbija zamjenske ili simboličke putanje. Prije unosa radi privatnu sigurnosnu kopiju baze. Dodaje samo novi blok `lavaggio-home-problems` u `home.content` na položaj 15. Ako taj blok već postoji, čuva njegov sadržaj, redoslijed i vidljivost. Testovi koriste zasebnu bazu u memoriji.

Promo blok bez spremljenog popisa slajdova i dalje prikazuje postojeći glavni banner. **Dodaj slajd** čuva taj banner kao prvi slajd i dodaje prostor za novu promociju. Svaki slajd uređuje se zasebno, pomiče gumbima **Gore/Dolje** i uključuje opcijom **Prikaži slajd**. Fotografije se mogu učitati kao JPG, PNG ili WebP do 5 MB ili zadati postojećom poveznicom. Podržani su fotografija s odvojenim tekstom i gumbom te prikaz samo fotografije s neobaveznom poveznicom. Prvi način bolje prilagođava tekst mobitelu. Kod više vidljivih slajdova dostupni su strelice, pokazivači i povlačenje; slajdovi se ne izmjenjuju automatski. Isključivanje svih slajdova skriva promocije bez vraćanja izvornog bannera. Kartice uslužne i samoposlužne praonice više se ne prikazuju na naslovnici.

`php artisan lavaggio:configure-promo-examples` prikazuje plan za tri ogledna slajda; `--apply` čuva postojeći banner kao prvi, dodaje primjer uklanjanja mrlja s postojećom Vanish fotografijom te primjer njege osjetljivog rublja s postojećom Persil Sensitive fotografijom. Koriste se postojeće kategorije, filtri i lokalne kataloške fotografije, bez novih cijena, popusta ili kopiranja fotografija. Naredba provjerava istu lokalnu SQLite destinaciju i prije unosa radi privatnu sigurnosnu kopiju. Ako postoji bilo kakav neprazan popis slajdova, uključujući isključene ili naknadno uređene primjere, čuva ga i ne dodaje primjere ponovno.

Šest početnih problemskih kartica pokriva neugodne mirise, sivo i požutjelo rublje, tvrdokorne mrlje, mrlje od krema i masnoća, osjetljive tkanine te održavanje perilice. Namjena koristi postojeći filtar problema ili tkanine, odnosno odgovarajuću kategoriju proizvoda. Ako se unese vlastita poveznica, ona ima prednost. Prazno polje poveznice vraća odredište odabrane namjene; za skrivanje kartice koristi se njezina vidljivost. Pri prikazu se unutarnje poveznice pretvaraju u hrvatske javne URLove, uz očuvanje upita i sidara.

Početne fotografije kategorija prikazuju cijele stvarne proizvode iz lokalnog kataloga, bez rezanja ambalaže. Slike na početnoj uređuju se unutar widgeta i ne mijenjaju navigaciju. Početna slika `laundry-care-group.svg` sastoji se od tri izvorne kataloške fotografije Ariela, Lenora i Vanisha ugrađene u jednu datoteku, bez precrtavanja ambalaže. Stari početni kolaž i početna Ariel fotografija koriste tu sliku, uz očuvanje prilagođenih opisa. Naknadno odabrane vlastite fotografije ostaju nepromijenjene, a namjerno prazna fotografija ostaje uklonjena. Ikone koriste postojeću licenciranu zbirku Font Awesome Pro i mali zasebni sprite.

Lavaggio widgeti nemaju opći odjeljak za dodatne medije koji se na naslovnici ne koristi. Proizvodi preuzimaju fotografije iz kataloga, brandovi svoje logotipe, a blog fotografije članaka. Obrazac kategorija nema neiskorišteni podnaslov, a uvodni banneri nemaju neiskorištene ikone praonica. Kod ručnog odabira članaka automatski izvor bloga skriven je dok se odabir ne isprazni. Prazni blok recenzija prikazuje i spremljeni podnaslov i poveznicu. Namjerno ispražnjene tekstualne postavke i poveznice ne vraćaju početne vrijednosti pri ponovnom otvaranju.

`php artisan lavaggio:seed-demo-content` dodaje 30 jasno označenih demo recenzija i osam dodatnih akcija isključivo u lokalnoj Lavaggio SQLite bazi. Prije unosa radi privatnu sigurnosnu kopiju. Ponovno pokretanje čuva postojeće zapise i naknadne izmjene.

Fotografije u `public/assets/lavaggio/home/products/` i `categories/` umanjene su kopije izvornih kataloških fotografija (Ariel 8700216691550, Lenor 8700216724074, Vanish 5999109563200 i 5997321744674, Persil 9000101566789, Dr. Beckmann 4008455047614, WENKO 4008838401095). Ambalaža i sadržaj fotografija nisu generirani ili precrtani.

Tipografija koristi zajedničke Lavaggio varijable: osnovni tekst i poveznice 16 px, uvodni tekst 18 px, mali natpisi 12 px. Kartice kategorija imaju jednake dimenzije i naslov kompleta ostaje u jednom redu. Brojčane zalihe se ne prikazuju na storefrontu; zadržani su status dostupnosti, veličina pakiranja i količina u narudžbi.
