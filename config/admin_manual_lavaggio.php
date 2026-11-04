<?php

$manual = require __DIR__.'/admin_manual.php';
$manual['branding'] = 'Lavaggio administracija';
$manual['intro'] = 'Upute prate Lavaggio administraciju. Odaberite područje ili pretražite zadatak; prikazuju se teme za uključene funkcije kojima imate pristup.';
$manual['search_hint'] = 'Pretražite naziv, polje ili postupak, primjerice „zaliha”, „banner” ili „dostava”.';
$manual['quick_start'][3]['text'] = 'Nakon spremanja otvorite odgovarajuću stranicu webshopa i provjerite sadržaj, fotografije, poveznice i prikaz na mobitelu.';

$overrides = [
    'artikli' => [
        'purpose' => 'Uređivanje artikala za njegu rublja: naziva, cijene, zalihe, opisa, fotografija i pripadnosti katalogu.',
        'steps' => [
            'U Katalog / Artikli pronađite artikl pretragom ili otvorite novi zapis.',
            'Unesite naziv, šifru, cijenu, zalihu i opis za odabrani jezik pa spremite osnovne podatke.',
            'U kartici Kategorije odaberite kategorije i brend, a u kartici Media glavnu fotografiju i dodatne fotografije.',
            'Ako artikl ima varijante, unutar artikla otvorite karticu Opcije i dodajte potrebne grupe i vrijednosti.',
            'Provjerite status artikla, spremite promjene i otvorite ga na webshopu.',
        ],
        'fields' => [
            ['name' => 'Šifra i SKU', 'description' => 'Jedinstvene oznake za pronalazak i povezivanje artikla.'],
            ['name' => 'Cijena, zaliha i porez', 'description' => 'Podaci koji određuju prodajnu cijenu i dostupnost.'],
            ['name' => 'Naziv, slug i opis', 'description' => 'Tekst i javna adresa artikla za odabrani jezik.'],
            ['name' => 'Kategorije i brend', 'description' => 'Grupiranje artikla u katalogu i filtrima.'],
            ['name' => 'Slike', 'description' => 'Glavna fotografija i galerija artikla; opis slike treba opisivati prikazani proizvod.'],
            ['name' => 'Opcije', 'description' => 'Vrijednosti i prodajne kombinacije uređuju se u kartici Opcije unutar artikla.'],
        ],
        'warning' => '',
        'tips' => [
            'Za artikl bez varijanti nije potrebno dodavati opcije.',
            'Prazna cijena kombinacije opcija koristi osnovnu cijenu artikla.',
            'Slike i opcije dodajte nakon prvog spremanja novog artikla.',
        ],
    ],
    'atributi' => [
        'purpose' => 'Opisna svojstva i filtri, primjerice vrsta sredstva, namjena, miris ili količina pakiranja.',
    ],
    'opcije' => [
        'steps' => [
            'U Katalog / Opcije izradite grupu, odaberite tip prikaza i dodajte vrijednosti.',
            'Otvorite odgovarajući artikl i njegovu karticu Opcije.',
            'Dodijelite grupu artiklu i dodajte vrijednosti ili prodajne kombinacije koje želite ponuditi kupcu.',
            'Za kombinacije unesite SKU, zalihu i po potrebi posebnu cijenu pa spremite.',
            'Na stranici artikla provjerite odabir kupca i izračun cijene.',
        ],
    ],
    'narudzbe' => [
        'steps' => [
            'U Prodaja / Narudžbe pronađite narudžbu prema broju, kupcu, datumu ili statusu.',
            'Otvorite narudžbu i provjerite stavke, iznose, adresu, plaćanje i dostavu.',
            'Dodajte internu bilješku ako je potrebna dodatna obrada.',
            'Odaberite odgovarajući status na hrvatskom i spremite promjenu s napomenom.',
            'Prema potrebi otvorite dokumente i pripremite otpremu dostupnim načinom dostave.',
        ],
        'warning' => 'Promjena korisničkog profila ne mijenja adresu već spremljenu na narudžbi. Prije promjene statusa provjerite što se stvarno dogodilo s plaćanjem i isporukom.',
    ],
    'stranice' => [
        'purpose' => 'Uređivanje informativnih stranica i sadržaja praonice, pregledno grupiranih prema nadređenim stranicama.',
        'steps' => [
            'U Sadržaj / Stranice odaberite jezik i pronađite željenu stranicu.',
            'Podstranice su uvučene ispod roditelja; putanja nadređenih stranica ostaje vidljiva i u pretrazi te na sljedećoj stranici popisa.',
            'Odaberite Uredi i promijenite naslov, tekst ili SEO podatke.',
            'Spremite pa otvorite javnu stranicu i provjerite sadržaj i poveznice.',
        ],
        'fields' => [
            ['name' => 'Naslov i sadržaj', 'description' => 'Tekst koji posjetitelj vidi na stranici.'],
            ['name' => 'Slug', 'description' => 'Dio javne adrese stranice. Promjena zahtijeva provjeru postojećih poveznica.'],
            ['name' => 'Status i redoslijed', 'description' => 'Aktivnost zapisa i redoslijed među stranicama iste razine.'],
            ['name' => 'Nadređene stranice', 'description' => 'Putanja u popisu prikazuje postojeću strukturu prenesenih stranica. Poveznice i podizbornike uređujete u Navigaciji.'],
        ],
    ],
    'blokovi' => [
        'purpose' => 'Uređivanje sadržaja naslovnice: glavnog bannera, izbora artikala, brendova, bundle ponuda i drugih prikazanih blokova.',
        'steps' => [
            'U Sadržaj / Blokovi otvorite blok koji želite promijeniti.',
            'Uredite naslov, opis i poveznicu prema poljima koja ta vrsta bloka prikazuje.',
            'Glavni banner koristi jednu zajedničku sliku i jedan opis slike. Na toj slici mogu biti prikazana sva tri proizvoda.',
            'Za Novo u ponudi odaberite automatski prikaz najnovijih artikala ili ručni izbor te broj prikazanih artikala. Fotografije dolaze iz odabranih artikala.',
            'Spremite i provjerite promjenu na naslovnici na računalu i mobitelu.',
        ],
        'fields' => [
            ['name' => 'Naslov, podnaslov i gumb', 'description' => 'Tekstovi i odredište poveznice prikazani u bloku.'],
            ['name' => 'Slika bannera', 'description' => 'Jedna slika cijele kompozicije; opis slike sažeto opisuje njezin sadržaj.'],
            ['name' => 'Izbor artikala', 'description' => 'Automatski ili ručni izbor, kategorije, brendovi i broj stavki, ovisno o vrsti bloka.'],
            ['name' => 'Aktivnost i redoslijed', 'description' => 'Određuju prikazuje li se blok i gdje se nalazi među ostalim blokovima.'],
        ],
        'warning' => '',
        'tips' => ['Za artikle u bloku prvo uredite njihove fotografije i podatke u katalogu.'],
    ],
    'navigacija' => [
        'purpose' => 'Sažet pregled glavnog izbornika i uređivanje detalja po stavkama, u odvojenim karticama.',
        'steps' => [
            'U Sadržaj / Navigacija odaberite jezik koji uređujete.',
            'U kartici Stavke izbornika pronađite naziv stavke i otvorite njezine detalje. Podstavke se uređuju istim postupkom unutar roditelja.',
            'Dodajte potrebnu stranicu ili poveznicu, a strelicama gore i dolje promijenite redoslijed.',
            'U kartici Slike kategorija otvorite željenu kategoriju za promjenu fotografije i njezina opisa.',
            'Kartice Izgled i Gornja traka koristite za izgled zaglavlja, dodatne poveznice i društvene mreže.',
            'Kliknite Spremi navigaciju i provjerite glavni i mobilni izbornik na webshopu.',
        ],
        'fields' => [
            ['name' => 'Stavke izbornika', 'description' => 'Nazivi stavki su sažeti; otvaranjem detalja uređujete odredište, status i podstavke.'],
            ['name' => 'Slike kategorija', 'description' => 'Fotografije glavnih kategorija u Web shop izborniku. Kategorije se preuzimaju iz kataloga.'],
            ['name' => 'Izgled', 'description' => 'Veličine, razmaci i boje koje podržava prikaz navigacije.'],
            ['name' => 'Gornja traka', 'description' => 'Dodatne poveznice i društvene mreže iznad glavne navigacije.'],
        ],
        'warning' => 'Promjena kartice ne sprema podatke. Spremite navigaciju prije napuštanja stranice.',
    ],
    'statusi-narudzbi' => [
        'steps' => [
            'U Postavke / Lokalno / Statusi narudžbi pronađite status prema hrvatskom nazivu ili šifri.',
            'Otvorite Uredi i po potrebi promijenite naziv, boju ili redoslijed.',
            'Provjerite zastavice Plaćeno i Otkazano prije spremanja.',
            'Spremite i provjerite prikaz u narudžbama i korisničkom računu.',
        ],
        'warning' => 'Šifre i zastavice postojećih statusa utječu na obradu narudžbi. Naziv mijenjajte odvojeno od njih.',
    ],
    'opcije-kataloga' => [
        'purpose' => 'Uključivanje opcijskih modula kataloga: atributa, opcija, brendova, akcija i bloga.',
    ],
    'lista-korisnika' => [
        'purpose' => 'Pregled korisničkih računa i dodavanje kupca ili administratorskog korisnika s odabranom ulogom.',
        'steps' => [
            'U Korisnici pronađite račun pretragom ili odaberite Novi korisnik.',
            'Za novi račun unesite ime, e-poštu i ulogu, primjerice Administrator ili Kupac.',
            'Postavite početnu lozinku od najmanje osam znakova i ponovite je u potvrdi.',
            'Spremite račun. Uređivanjem postojećeg računa možete promijeniti profil i dopuštene uloge.',
            'Za pregled kupnji otvorite Statistike kupaca i artikala ili narudžbe odabranog korisnika.',
        ],
        'fields' => [
            ['name' => 'Ime i e-pošta', 'description' => 'Identitet računa i adresa za prijavu. E-pošta mora biti jedinstvena.'],
            ['name' => 'Uloga', 'description' => 'Određuje je li riječ o kupcu ili djelatniku te kojim dijelovima administracije ima pristup.'],
            ['name' => 'Lozinka i potvrda', 'description' => 'Obavezne za novi račun; prazna lozinka pri uređivanju zadržava postojeću.'],
            ['name' => 'Profil i adrese', 'description' => 'Kontaktni podaci i adrese računa.'],
        ],
        'warning' => 'Administratorsku ulogu dodijelite samo osobi kojoj želite omogućiti upravljanje trgovinom. Lozinka se ne šalje automatski e-poštom.',
    ],
    'grupe-korisnika' => [
        'purpose' => 'Grupe kupaca za ciljane akcije i pravila trgovine.',
        'steps' => [
            'Izradite grupu s jasnim nazivom i stabilnom šifrom.',
            'Postavite aktivnost, redoslijed i po potrebi zadanu grupu.',
            'Dodijelite korisnika grupi u njegovu profilu.',
            'Provjerite akcije i ostala pravila koja se primjenjuju na grupu.',
        ],
        'warning' => 'Prije deaktivacije ili brisanja provjerite povezana pravila i korisnike grupe.',
    ],
];

$excluded = ['b2b-cjenici', 'eprel-katalog', 'eprel-postavke', 'wholesale-api', 'b2b-zahtjevi'];
$sections = [];
foreach ($manual['sections'] as $section) {
    $items = [];
    foreach ($section['items'] as $item) {
        if (! in_array($item['id'], $excluded, true)) {
            $items[] = array_replace($item, $overrides[$item['id']] ?? []);
        }
    }
    if ($section['id'] === 'katalog') {
        $section['description'] = 'Artikli, kategorije, svojstva, brendovi, akcije i bundle ponude.';
        $items[] = [
            'id' => 'bundle-ponude',
            'title' => 'Bundle ponude',
            'route' => 'admin.bundles',
            'route_patterns' => ['admin.bundles'],
            'ability' => 'catalog.actions.view',
            'feature' => 'actions',
            'purpose' => 'Paketi više artikala s količinama, zajedničkim popustom i razdobljem prikaza.',
            'steps' => [
                'U Katalog / Bundle ponude pronađite postojeći paket ili odaberite Nova bundle ponuda.',
                'Unesite naziv te dodajte artikle i količinu svakog artikla.',
                'Postavite ponuđeni popust, aktivnost i razdoblje važenja.',
                'Spremite ponudu i provjerite njezine artikle, cijenu i prikaz na webshopu.',
            ],
            'tips' => ['Pretraga artikala u ponudi koristi njihov naziv ili šifru. Prije aktivacije provjerite dostupnost svih artikala u paketu.'],
        ];
    }
    if ($section['id'] === 'korisnici') {
        $section['description'] = 'Korisnički računi, statistike kupnje, grupe, ovlasti i aktivnosti.';
        array_splice($items, 1, 0, [[
            'id' => 'statistike-kupaca',
            'title' => 'Statistike kupaca i artikala',
            'route' => 'admin.users.statistics',
            'route_patterns' => ['admin.users.statistics'],
            'ability' => 'users.list.view',
            'abilities' => ['sales.orders.view'],
            'purpose' => 'Pregled kupnji prema kupcu, artiklu i odabranom razdoblju.',
            'steps' => [
                'U Korisnici otvorite Statistike kupaca i artikala.',
                'Odaberite razdoblje i ostale filtre koje želite analizirati.',
                'Pregledajte kupce, artikle i vrijednosti narudžbi u odabranoj valuti.',
                'Za pojedinačnu provjeru otvorite povezani račun ili narudžbu.',
            ],
        ]]);
    }
    if ($section['id'] === 'postavke') {
        $section['description'] = 'Plaćanje, dostava, statusi narudžbi, jezici i postavke trgovine.';
    }
    if ($items !== []) {
        $section['items'] = $items;
        $sections[] = $section;
    }
}
$manual['sections'] = $sections;

return $manual;
