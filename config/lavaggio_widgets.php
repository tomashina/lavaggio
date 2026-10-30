<?php

$field = static fn (string $label, mixed $default = '', string $type = 'text', ?string $group = null): array => array_filter(compact('label', 'default', 'type', 'group'), static fn ($value) => $value !== null);

$categoryFields = ['eyebrow' => $field('Mali naslov', 'LAVAGGIO PONUDA')];
$categoryIcons = ['shirt', 'spray-can-sparkles', 'wind', 'washing-machine', 'basket-shopping'];
foreach (range(1, 5) as $index) {
    $categoryFields += [
        "card_{$index}_title" => $field('Naslov', '', 'text', "Blok {$index}"),
        "card_{$index}_icon" => $field('Font Awesome Pro ikona', $categoryIcons[$index - 1], 'icon'),
        "card_{$index}_url" => $field('Poveznica', '', 'url'),
        "card_{$index}_image" => $field('Fotografija artikala', '', 'image'),
        "card_{$index}_image_alt" => $field('Opis fotografije'),
    ];
}

return [
    'lavaggio_campaigns' => [
        'label' => 'Lavaggio — uvodni banneri',
        'content_defaults' => ['title' => 'Njega rublja s potpisom Lavaggio.', 'subtitle' => 'Iskustvo profesionalne praonice u vašem domu. Pažljivo odabrana njega za rublje koje volite.', 'cta_label' => 'Istražite ponudu', 'cta_url' => '/shop'],
        'fields' => [
            'eyebrow' => $field('Mali naslov', 'LAVAGGIO WEBSHOP', 'text', 'Webshop — glavni blok'),
            'main_image' => $field('Glavna fotografija artikla', '/assets/lavaggio/home/products/ariel-sensitive.webp', 'image'),
            'main_image_alt' => $field('Opis glavne fotografije', 'Ariel Sensitive tekući deterdžent za rublje'),
            'main_secondary_image' => $field('Druga fotografija artikla', '/assets/lavaggio/home/products/lenor-spring-awakening.webp', 'image'),
            'main_secondary_image_alt' => $field('Opis druge fotografije', 'Lenor Spring Awakening omekšivač za rublje'),
            'main_tertiary_image' => $field('Treća fotografija artikla', '/assets/lavaggio/home/products/vanish-oxi-action.webp', 'image'),
            'main_tertiary_image_alt' => $field('Opis treće fotografije', 'Vanish Oxi Action Pink gel za uklanjanje mrlja'),
            'shipping_notice' => $field('Prikaži obavijest o besplatnoj dostavi', true, 'checkbox'),
            'service_eyebrow' => $field('Mali naslov', 'PREPUSTITE RUBLJE NAMA', 'text', 'Uslužna praonica'),
            'service_title' => $field('Naslov', 'Uslužna praonica rublja'),
            'service_description' => $field('Opis', 'Vaše rublje peremo, sušimo i pažljivo pripremamo za preuzimanje.', 'textarea'),
            'service_url' => $field('Poveznica', '/page/usluzna-praonica-rublja', 'url'),
            'service_cta_label' => $field('Tekst gumba', 'Upoznajte uslugu'),
            'service_icon' => $field('Font Awesome Pro ikona', 'shirt', 'icon'),
            'service_image' => $field('Fotografija praonice', '/assets/lavaggio/navigation/usluzna-praonica.webp', 'image'),
            'service_image_alt' => $field('Opis fotografije', 'Oprano i pripremljeno rublje u Lavaggio praonici'),
            'self_eyebrow' => $field('Mali naslov', 'OPERITE KAD VAMA ODGOVARA', 'text', 'Samoposlužna praonica'),
            'self_title' => $field('Naslov', 'Samoposlužna praonica rublja'),
            'self_description' => $field('Opis', 'Profesionalni strojevi za jednostavno pranje i sušenje vašeg rublja.', 'textarea'),
            'self_url' => $field('Poveznica', '/page/samoposluzna-praonica-rublja', 'url'),
            'self_cta_label' => $field('Tekst gumba', 'Kako funkcionira'),
            'self_icon' => $field('Font Awesome Pro ikona', 'washing-machine', 'icon'),
            'self_image' => $field('Fotografija praonice', '/assets/lavaggio/navigation/samoposluzna-praonica.webp', 'image'),
            'self_image_alt' => $field('Opis fotografije', 'Interijer Lavaggio samoposlužne praonice s profesionalnim perilicama'),
        ],
    ],
    'lavaggio_categories' => [
        'label' => 'Lavaggio — kategorije i kompleti',
        'content_defaults' => ['title' => 'Sve za njegu rublja.', 'cta_label' => 'Cijela ponuda', 'cta_url' => '/shop'],
        'fields' => $categoryFields + [
            'card_6_title' => $field('Naslov', 'Kupi u kompletu i uštedi', 'text', 'Blok 6 — kompleti'),
            'card_6_url' => $field('Poveznica', '/bundle-artikli', 'url'),
            'card_6_cta_label' => $field('Tekst gumba', 'Pogledajte ponudu'),
            'card_6_icon' => $field('Ikona', 'gift', 'icon'),
        ],
    ],
    'lavaggio_products' => [
        'label' => 'Lavaggio — proizvodi u nizu',
        'fields' => [
            'eyebrow' => $field('Mali naslov', 'LAVAGGIO WEBSHOP'),
            'source' => $field('Odabir proizvoda', 'latest', 'select') + ['options' => ['manual' => 'Ručni odabir i redoslijed', 'latest' => 'Najnoviji artikli', 'filtered' => 'Automatski iz kategorija i brandova', 'promotions' => 'Artikli na akciji', 'new_products' => 'Artikli s oznakom Novo']],
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
