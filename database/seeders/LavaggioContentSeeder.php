<?php

namespace Database\Seeders;

use App\Services\Import\LavaggioContentImportService;
use Illuminate\Database\Seeder;
use RuntimeException;

/** Initial public content from Lavaggio's published MODX pages. */
class LavaggioContentSeeder extends Seeder
{
    public function run(): void
    {
        if (config('storefront.home') !== 'lavaggio') {
            throw new RuntimeException('Lavaggio content requires the Lavaggio storefront configuration.');
        }

        $snapshot = json_decode(file_get_contents(database_path('data/lavaggio-content-20261028.json')), true, 512, JSON_THROW_ON_ERROR);
        $result = app(LavaggioContentImportService::class)->import($snapshot);
        $this->command?->info(sprintf('Uvezeno: %d stranice, %d TV studio objave i %d pitanja.', $result['pages_imported'], $result['posts_imported'], $result['faqs_imported']));
        $this->call(LavaggioContentNavigationSeeder::class);
    }
}
