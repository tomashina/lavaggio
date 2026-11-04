<?php

$field = static fn (string $label, mixed $default = '', string $type = 'text', ?string $group = null): array => array_filter(compact('label', 'default', 'type', 'group'), static fn ($value) => $value !== null);

$categoryFields = ['eyebrow' => $field('Mali naslov', 'LAVAGGIO PONUDA')];
$categoryIcons = ['shirt', 'spray-can-sparkles', 'wind', 'washing-machine', 'basket-shopping'];
foreach (range(1, 5) as $index) {
    $categoryFields += [
        "card_{$index}_title" => $field('Naslov', '', 'text', "Blok {$index}"),
        "card_{$index}_icon" => $field('Font Awesome Pro ikona', $categoryIcons[$index - 1], 'icon'),
        "card_{$index}_url" => $field('Poveznica', '', 'url'),
        "card_{$index}_image" => $field('Fotografija artikala', '', 'image') + ['alt_field' => "card_{$index}_image_alt"],
        "card_{$index}_image_alt" => $field('Opis fotografije'),
    ];
}

$problemFields = ['eyebrow' => $field('Mali naslov', 'PRONAĐITE RJEŠENJE')];
$problems = [
    ['Neugodni mirisi', 'wind', 'odor'],
    ['Sivo i požutjelo rublje', 'shirt', 'grey'],
    ['Tvrdokorne mrlje', 'spray-can-sparkles', 'stains'],
    ['Mrlje od krema i masnoća', 'droplet', 'grease'],
    ['Osjetljive tkanine', 'feather', 'delicate'],
    ['Održavanje perilice rublja', 'washing-machine', 'machine'],
];
foreach ($problems as $offset => [$title, $icon, $target]) {
    $index = $offset + 1;
    $problemFields += [
        "card_{$index}_title" => $field('Naziv', $title, 'text', "Kartica {$index}"),
        "card_{$index}_icon" => $field('Ikona', $icon, 'icon'),
        "card_{$index}_target" => $field('Prikaži proizvode za', $target, 'select') + ['options' => [
            'odor' => 'Neugodne mirise', 'grey' => 'Sivo i požutjelo rublje', 'stains' => 'Tvrdokorne mrlje',
            'grease' => 'Kreme i masnoće', 'delicate' => 'Osjetljive tkanine', 'machine' => 'Održavanje perilice',
        ]],
        "card_{$index}_url" => $field('Vlastita poveznica (neobavezno)', '', 'url') + ['help' => 'Prazno polje koristi odabranu namjenu i postojeće filtre/kategorije proizvoda.'],
        "card_{$index}_order" => $field('Redoslijed', $index * 10, 'number') + ['min' => 0, 'max' => 100],
        "card_{$index}_enabled" => $field('Prikaži karticu', true, 'checkbox'),
    ];
}

return [
    'lavaggio_campaigns' => [
        'label' => 'Lavaggio — uvodni banneri',
        'content_defaults' => ['title' => 'Njega rublja s potpisom Lavaggio.', 'subtitle' => 'Iskustvo profesionalne praonice u vašem domu. Pažljivo odabrana njega za rublje koje volite.', 'cta_label' => 'Istražite ponudu', 'cta_url' => '/shop'],
        'fields' => [
            'eyebrow' => $field('Mali naslov', 'LAVAGGIO WEBSHOP', 'text', 'Webshop — glavni blok'),
            'main_image' => $field('Fotografija bannera', '/assets/lavaggio/home/products/laundry-care-group.svg', 'image') + ['alt_field' => 'main_image_alt'],
            'main_image_alt' => $field('Opis fotografije bannera', 'Ariel, Lenor i Vanish proizvodi za njegu rublja'),
            'shipping_notice' => $field('Prikaži obavijest o besplatnoj dostavi', true, 'checkbox'),
            'slide_display' => $field('Prikaz bannera', 'text_image', 'select') + ['options' => ['text_image' => 'Fotografija, tekst i gumb', 'image' => 'Samo fotografija']],
            'slides' => $field('Promo slajdovi', [], 'slides'),
            'service_eyebrow' => $field('Mali naslov', 'PREPUSTITE RUBLJE NAMA', 'text', 'Uslužna praonica'),
            'service_title' => $field('Naslov', 'Uslužna praonica rublja'),
            'service_description' => $field('Opis', 'Vaše rublje peremo, sušimo i pažljivo pripremamo za preuzimanje.', 'textarea'),
            'service_url' => $field('Poveznica', '/page/usluzna-praonica-rublja', 'url'),
            'service_cta_label' => $field('Tekst gumba', 'Upoznajte uslugu'),
            'service_image' => $field('Fotografija praonice', '/assets/lavaggio/navigation/usluzna-praonica.webp', 'image') + ['alt_field' => 'service_image_alt'],
            'service_image_alt' => $field('Opis fotografije', 'Oprano i pripremljeno rublje u Lavaggio praonici'),
            'self_eyebrow' => $field('Mali naslov', 'OPERITE KAD VAMA ODGOVARA', 'text', 'Samoposlužna praonica'),
            'self_title' => $field('Naslov', 'Samoposlužna praonica rublja'),
            'self_description' => $field('Opis', 'Profesionalni strojevi za jednostavno pranje i sušenje vašeg rublja.', 'textarea'),
            'self_url' => $field('Poveznica', '/page/samoposluzna-praonica-rublja', 'url'),
            'self_cta_label' => $field('Tekst gumba', 'Kako funkcionira'),
            'self_image' => $field('Fotografija praonice', '/assets/lavaggio/navigation/samoposluzna-praonica.webp', 'image') + ['alt_field' => 'self_image_alt'],
            'self_image_alt' => $field('Opis fotografije', 'Interijer Lavaggio samoposlužne praonice s profesionalnim perilicama'),
        ],
    ],
    'lavaggio_problems' => [
        'label' => 'Lavaggio — odabir prema problemu',
        'content_fields' => ['title', 'subtitle'],
        'content_defaults' => ['title' => 'Odaberi deterdžent za problem', 'subtitle' => 'Krenite od onoga što želite riješiti i pronađite odgovarajuću njegu.'],
        'fields' => $problemFields,
    ],
    'lavaggio_categories' => [
        'label' => 'Lavaggio — kategorije i kompleti',
        'content_fields' => ['title', 'cta_label', 'cta_url'],
        'content_defaults' => ['title' => 'Sve za njegu rublja.', 'cta_label' => 'Cijela ponuda', 'cta_url' => '/shop'],
        'fields' => $categoryFields + [
            'card_6_title' => $field('Naslov', 'Kupi u kompletu i uštedi', 'text', 'Blok 6 — kompleti'),
            'card_6_url' => $field('Poveznica', '/bundle-artikli', 'url'),
            'card_6_cta_label' => $field('Tekst gumba', 'Pogledajte ponudu'),
            'card_6_icon' => $field('Ikona', 'gift', 'icon'),
        ],
    ],
    'lavaggio_products' => [
        'label' => 'Lavaggio — kolekcija proizvoda',
        'fields' => [
            'eyebrow' => $field('Mali naslov', 'LAVAGGIO WEBSHOP'),
            'source' => $field('Odabir proizvoda', 'latest', 'select') + ['help' => 'Svaki blok je zasebna kolekcija s vlastitim naslovom i odabirom proizvoda. Naziv kolekcije ne uključuje automatsku statistiku prodaje ili pretraga.', 'options' => ['manual' => 'Ručni odabir i redoslijed', 'latest' => 'Najnoviji artikli', 'filtered' => 'Automatski iz kategorija i brandova', 'promotions' => 'Artikli na akciji', 'new_products' => 'Artikli s oznakom Novo']],
            'category_ids' => $field('Kategorije', [], 'categories', 'Ponuda u widgetu'),
            'manufacturer_ids' => $field('Brandovi', [], 'manufacturers'),
            'include_children' => $field('Uključi potkategorije', true, 'checkbox'),
            'in_stock_only' => $field('Samo artikli na zalihi', false, 'checkbox'),
            'promotions_only' => $field('Samo artikli na akciji', false, 'checkbox'),
            'product_sort' => $field('Redoslijed', 'source', 'select') + ['options' => ['source' => 'Prema odabranoj ponudi', 'largest_discount' => 'Najveći popusti prvo', 'newest' => 'Najnoviji artikli prvo', 'price_asc' => 'Cijena od niže prema višoj', 'price_desc' => 'Cijena od više prema nižoj', 'category_order' => 'Redoslijed kategorija']],
            'desktop_columns' => $field('Vidljivo na velikim ekranima', 6, 'number', 'Raspored') + ['min' => 2, 'max' => 6],
            'tablet_columns' => $field('Vidljivo na tabletima', 3, 'number') + ['min' => 2, 'max' => 4],
            'mobile_columns' => $field('Vidljivo na mobitelima', 2, 'number') + ['min' => 1, 'max' => 2],
        ],
    ],
    'lavaggio_brands' => [
        'label' => 'Lavaggio — brandovi s logotipima',
        'content_defaults' => ['title' => 'Brandovi kojima vjerujete', 'cta_label' => 'Svi brandovi', 'cta_url' => '/brendovi'],
        'fields' => [
            'eyebrow' => $field('Mali naslov', 'ODABRANI BRANDOVI'),
            'visible_count' => $field('Broj vidljivih brandova', 6, 'number') + ['min' => 1, 'max' => 12],
            'source' => $field('Odabir brandova', 'all', 'select') + ['options' => ['all' => 'Svi aktivni brandovi', 'manual' => 'Ručni odabir i redoslijed']],
        ],
    ],
    'lavaggio_blog' => [
        'label' => 'Lavaggio — blog i TV studio',
        'content_defaults' => ['title' => 'Zadnje s bloga i iz TV studija', 'cta_label' => 'Svi članci', 'cta_url' => '/blog'],
        'fields' => ['eyebrow' => $field('Mali naslov', 'SAVJETI IZ PRAONICE')],
    ],
    'lavaggio_reviews' => [
        'label' => 'Lavaggio — komentari kupaca',
        'content_defaults' => ['title' => 'Što kažu naši kupci', 'cta_label' => 'Sva iskustva kupaca', 'cta_url' => '/recenzije'],
        'fields' => [
            'eyebrow' => $field('Mali naslov', 'ISKUSTVA KUPACA'),
            'empty_message' => $field('Poruka prije prvih komentara', 'Prvi komentari kupaca stižu uskoro. Nakon kupnje podijelite svoje iskustvo na stranici proizvoda.', 'textarea'),
        ],
    ],
];
