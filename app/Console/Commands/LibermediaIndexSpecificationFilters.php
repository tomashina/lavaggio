<?php

namespace App\Console\Commands;

use App\Models\Catalog\Product\Product;
use App\Services\Catalog\LibermediaSpecificationFilterIndex;
use Illuminate\Console\Command;

class LibermediaIndexSpecificationFilters extends Command
{
    protected $signature = 'libermedia:index-specification-filters {--dry-run : Prikaži inventar bez promjena}';

    protected $description = 'Indeksiraj postojeće izričito označene specifikacije za filtre kataloga';

    public function handle(LibermediaSpecificationFilterIndex $index): int
    {
        if (! $index->enabled()) {
            $this->error('Indeks je namijenjen Liber Media katalogu.');

            return self::FAILURE;
        }
        $inventory = [];
        $changed = 0;
        Product::query()->with(['translations', 'technicalSpecificationRows'])->orderBy('id')->chunkById(100, function ($products) use ($index, &$inventory, &$changed): void {
            foreach ($products as $product) {
                $facts = $index->facts($product);
                if ($product->is_active) {
                    foreach ($facts as $fact) {
                        $inventory[$fact['label']][$fact['value']][$product->id] = true;
                    }
                }
                if (! $this->option('dry-run') && $index->sync($product)) {
                    $changed++;
                }
            }
        });
        $rows = [];
        foreach ($inventory as $label => $values) {
            uasort($values, fn ($a, $b) => count($b) <=> count($a));
            $rows[] = [$label, count($values), count(array_unique(array_merge(...array_map('array_keys', array_values($values))))), max(array_map('mb_strlen', array_keys($values))), implode('; ', array_slice(array_keys($values), 0, 6))];
        }
        $this->table(['Specifikacija', 'Vrijednosti', 'Aktivni artikli', 'Najdulja vrijednost', 'Najčešće vrijednosti'], $rows);
        if (! $this->option('dry-run')) {
            LibermediaSpecificationFilterIndex::invalidate();
        }
        $this->info($this->option('dry-run') ? 'Pregled je završen bez promjena.' : 'Osvježeno artikala: '.$changed);

        return self::SUCCESS;
    }
}
