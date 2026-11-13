<?php

// Read the supplied workbook and ZIP; prepare a portable, checksum-verified import.
require __DIR__.'/../vendor/autoload.php';
$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
[$script, $workbook, $archive, $destination] = $argv;
if (file_exists($destination.'/catalog.json')) {
    throw new RuntimeException('Destination already contains a snapshot.');
}
@mkdir($destination.'/images', 0700, true);
$sheet = PhpOffice\PhpSpreadsheet\IOFactory::load($workbook)->getSheet(0);
$rows = $sheet->toArray(null, true, false);
$expected = ['EAN', 'BREND', 'SKU', 'PROIZVOD', 'TEŽINA (po komadu u gramima)', 'STANJE', 'DOBAVLJAČ', 'MPC', 'KATEGORIJA'];
if (array_slice(array_shift($rows), 0, 9) !== $expected) {
    throw new RuntimeException('Unexpected spreadsheet columns.');
}
$zip = new ZipArchive;
if ($zip->open($archive) !== true) {
    throw new RuntimeException('Cannot open image ZIP.');
}
$images = [];
for ($i = 0; $i < $zip->numFiles; $i++) {
    $path = $zip->getNameIndex($i);
    if (! preg_match('~^PHOTO 11-2026/(\d+)/[^/]+\.(jpg|jpeg|png|avif|webp)$~i', $path, $matches)) {
        throw new RuntimeException('Unexpected archive entry: '.$path);
    }
    if (isset($images[$matches[1]])) {
        throw new RuntimeException('Multiple images for SKU '.$matches[1]);
    }
    $images[$matches[1]] = $path;
}
$items = [];
foreach ($rows as $index => $row) {
    if (count(array_filter($row, fn ($v) => $v !== null && $v !== '')) === 0) {
        continue;
    }
    [$ean, $brand, $sku, $name, $grams, $stock, $supplier, $price, $category] = $row;
    $sku = (string) $sku;
    if (! isset($images[$sku])) {
        throw new RuntimeException('Missing image for '.$sku);
    }
    $source = $zip->getFromName($images[$sku]);
    $webp = app(App\Services\Import\LavaggioProductImageService::class)->webpOnWhite($source);
    $imagePath = 'images/'.$sku.'.webp';
    file_put_contents($destination.'/'.$imagePath, $webp);
    $name = preg_replace('/\s+/u', ' ', trim($name));
    $quantity = null;
    $unit = null;
    if (preg_match('/(\d+(?:[.,]\d+)?)\s*(kg|gr|g|ml|l|kom)\b/iu', $name, $m)) {
        $quantity = (float) str_replace(',', '.', $m[1]);
        $unit = mb_strtolower($m[2]);
        if (in_array($unit, ['g', 'gr', 'ml'])) {
            $quantity /= 1000;
        }
        $unit = match ($unit) {
            'g', 'gr', 'kg' => 'kg', 'ml', 'l' => 'l', default => 'pcs'
        };
        if (preg_match('/\)\s*X\s*(\d+)/i', $name, $multi)) {
            $quantity *= (int) $multi[1];
        }
    }
    $items[] = [
        'source_row' => $index + 2, 'sku' => $sku, 'ean' => (string) $ean, 'brand' => trim($brand),
        'brand_code' => $brand === 'DR.BECKMANN' ? 'lavaggio-dr-beckmann' : 'lavaggio-'.Illuminate\Support\Str::slug($brand),
        'name' => $name, 'category_code' => trim($category), 'price' => round((float) $price, 2),
        'stock' => (int) $stock, 'supplier_stock' => (int) $supplier,
        'weight_kg' => $grams === null || $grams === '' ? null : (float) $grams / 1000,
        'content_quantity' => $quantity, 'content_unit' => $unit,
        'is_professional' => str_contains($name, 'PROFESSIONAL'),
        'image' => $imagePath, 'image_sha256' => hash('sha256', $webp),
        'source_image' => $images[$sku], 'source_image_sha256' => hash('sha256', $source),
    ];
    unset($images[$sku]);
}
if ($images !== [] || count($items) !== 48) {
    throw new RuntimeException('Expected exactly 48 products with 48 matched photographs.');
}
$manifest = ['source_url' => 'https://docs.google.com/spreadsheets/d/1kMF3JdPhOMD2T3ZpZnMfyzN3QUOD6V7Ko38kpgPWhSg/edit?gid=0',
    'sheet' => $sheet->getTitle(), 'workbook_sha256' => hash_file('sha256', $workbook),
    'archive_sha256' => hash_file('sha256', $archive), 'products' => $items];
file_put_contents($destination.'/catalog.json', json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
copy($workbook, $destination.'/source.xlsx');
echo json_encode(['products' => count($items), 'brands' => array_values(array_unique(array_column($items, 'brand'))),
    'stock' => array_sum(array_column($items, 'stock')), 'images' => count($items)]).PHP_EOL;
