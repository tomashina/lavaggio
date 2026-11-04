# Lavaggio početna kroz widgete

Početna se sastoji isključivo od uređenih `ContentBlock` zapisa u poziciji `home.content`. U administraciji **Sadržaj → Blokovi** dostupni su raspored, uključivanje/isključivanje i uređivanje svakog widgeta. Uredničke promjene ne zahtijevaju izmjenu predloška.

| Widget | Sadržaj koji se uređuje |
| --- | --- |
| Webshop i praonica | Glavni naslov, opis i gumb; jedna fotografija bannera s opisom; tekst, poveznica i stvarne fotografije obje praonice |
| Kategorije i kompleti | Pet cijelih fotografija proizvoda s ikonama, nazivima i poveznicama, bez opisa; šesti blok prikazuje komplete s ikonom, naslovom u jednom redu i poveznicom |
| Novo u ponudi | Automatski najnoviji artikli ili ručni odabir, kategorije i brandovi, broj artikala i broj vidljivih stupaca po veličini ekrana |
| Brandovi | Aktivni brandovi s logotipima ili ručni odabir i redoslijed; broj vidljivih logotipa |
| Artikli na akciji | Aktualne akcijske cijene iz kataloga, isti izbor i raspored kao za ostale proizvode |
| Komentari kupaca | Odobreni komentari stvarnih kupaca; broj, filtriranje izdvojenih komentara i poruka prije prve recenzije |
| Blog i TV studio | Najnoviji objavljeni članci/video objave ili odabrani članci; naslov, poveznica i broj objava |

Komentari se nalaze neposredno iznad bloga. Kao i brandovi, prikazuju se u traci pune širine ekrana sa svijetlom Lavaggio pozadinom i službenim uzorkom; kartice komentara ostaju poravnate s ostalim sadržajem početne.

Prikaz komentara ne stvara ogledne ocjene. Dok nema odobrenih komentara, prikazuje se uredljiva informativna poruka. Proizvodi na akciji koriste postojeća pravila promocija; widget ne stvara dodatne popuste.

`php artisan lavaggio:configure-home` stvara sedam lokalnih widgeta. Ponovno pokretanje čuva postojeće tekstove, redoslijed i vidljivost, kao i druge blokove. Opcija `--force` izričito vraća početne postavke ovih sedam widgeta. Naredba je ograničena na lokalnu Lavaggio temu.

Početne fotografije kategorija prikazuju cijele stvarne proizvode iz lokalnog kataloga, bez rezanja ambalaže. Slike na početnoj uređuju se unutar widgeta i ne mijenjaju navigaciju. Glavni uvodni banner uređuje se kao jedna fotografija i jedan pripadajući opis, jednako u adminu i na naslovnici. Početna slika `laundry-care-group.svg` sastoji se od tri izvorne kataloške fotografije Ariela, Lenora i Vanisha ugrađene u jednu datoteku, bez precrtavanja ambalaže. Stari početni kolaž i početna Ariel fotografija koriste tu sliku, uz očuvanje prilagođenih opisa. Naknadno odabrane vlastite fotografije ostaju nepromijenjene, a namjerno prazna fotografija ostaje uklonjena. Fotografije obje praonice uređuju se zasebno uz svoje opise. Ikone kategorija koriste postojeću licenciranu zbirku Font Awesome Pro i mali zasebni sprite.

Lavaggio widgeti nemaju opći odjeljak za dodatne medije koji se na naslovnici ne koristi. Proizvodi preuzimaju fotografije iz kataloga, brandovi svoje logotipe, a blog fotografije članaka. Obrazac kategorija nema neiskorišteni podnaslov, a uvodni banneri nemaju neiskorištene ikone praonica. Kod ručnog odabira članaka automatski izvor bloga skriven je dok se odabir ne isprazni. Prazni blok recenzija prikazuje i spremljeni podnaslov i poveznicu. Namjerno ispražnjene tekstualne postavke i poveznice ne vraćaju početne vrijednosti pri ponovnom otvaranju.

`php artisan lavaggio:seed-demo-content` dodaje 30 jasno označenih demo recenzija i osam dodatnih akcija isključivo u lokalnoj Lavaggio SQLite bazi. Prije unosa radi privatnu sigurnosnu kopiju. Ponovno pokretanje čuva postojeće zapise i naknadne izmjene.

Fotografije u `public/assets/lavaggio/home/products/` i `categories/` umanjene su kopije izvornih kataloških fotografija (Ariel 8700216691550, Lenor 8700216724074, Vanish 5999109563200 i 5997321744674, Persil 9000101566789, Dr. Beckmann 4008455047614, WENKO 4008838401095). Ambalaža i sadržaj fotografija nisu generirani ili precrtani. Fotografije praonica dolaze iz postojećeg lokalnog uvoza s lavaggio.hr, evidentiranog u `database/data/lavaggio-laundry-menu-images.json`.

Tipografija koristi zajedničke Lavaggio varijable: osnovni tekst i poveznice 16 px, uvodni tekst 18 px, mali natpisi 12 px. Kartice kategorija imaju jednake dimenzije i naslov kompleta ostaje u jednom redu. Brojčane zalihe se ne prikazuju na storefrontu; zadržani su status dostupnosti, veličina pakiranja i količina u narudžbi.
