<?php

$manual = require __DIR__.'/admin_manual_lavaggio.php';
$routes = [];
$topics = [];
foreach ($manual['sections'] as $section) {
    foreach ($section['items'] as $item) {
        $entry = [
            'title' => $item['title'],
            'summary' => $item['purpose'] ?? '',
            'sections' => [[
                'title' => 'Postupak',
                'items' => $item['steps'] ?? [],
            ]],
            'bullets' => $item['tips'] ?? [],
        ];
        foreach ($item['route_patterns'] ?? [] as $pattern) {
            $routes[$pattern] = $entry;
        }
        $topics[$item['id']] = $entry;
    }
}

return [
    'default' => [
        'title' => 'Pomoć za Lavaggio administraciju',
        'summary' => 'Pronađite zapis, uredite odgovarajuća polja, spremite i provjerite rezultat na webshopu.',
        'bullets' => ['Potpune upute otvorite gumbom Pomoć u zaglavlju administracije.'],
    ],
    'routes' => $routes,
    'topics' => $topics,
];
