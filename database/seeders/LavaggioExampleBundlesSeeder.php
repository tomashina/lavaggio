<?php

namespace Database\Seeders;

use App\Models\Catalog\Bundle\Bundle;
use App\Models\Catalog\Product\Product;
use App\Models\User\CustomerGroup;
use App\Services\Front\CartService;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class LavaggioExampleBundlesSeeder extends Seeder
{
    public function run(): void
    {
        $expected = realpath(database_path('database.sqlite'));
        if (! app()->environment('local') || config('storefront.home') !== 'lavaggio'
            || config('database.default') !== 'sqlite' || ! $expected
            || config('database.connections.sqlite.database') !== $expected
            || is_link(database_path('database.sqlite'))) {
            throw new RuntimeException('Example bundles are restricted to the local Lavaggio database.');
        }
        $active = collect(DB::connection()->select('PRAGMA database_list'))->firstWhere('name', 'main');
        if (! $active || $active->file !== $expected) {
            throw new RuntimeException('The active database is not the verified local destination.');
        }
        $items = json_decode(file_get_contents(database_path('data/lavaggio-example-bundles.json')), true, 512, JSON_THROW_ON_ERROR);
        DB::transaction(function () use ($items): void {
            $group = CustomerGroup::query()->where('is_active', true)->where('is_default', true)->first()
                ?? CustomerGroup::query()->firstOrCreate(['code' => 'lavaggio-retail'], [
                    'name' => 'Maloprodajni kupci', 'is_active' => true, 'is_default' => true,
                    'default_discount_percent' => 0, 'payload' => ['local_demo' => true],
                ]);
            foreach ($items as $index => $item) {
                if (count($item['products']) !== 2 || count(array_unique(array_column($item['products'], 'ean'))) !== 2) {
                    throw new RuntimeException('Each example bundle must contain two distinct products.');
                }
                $productIds = [];
                foreach ($item['products'] as $entry) {
                    $product = Product::query()->where('barcode', $entry['ean'])->where('is_active', true)->firstOrFail();
                    $productIds[$product->id] = ['quantity' => (int) $entry['quantity']];
                }
                $bundle = Bundle::query()->firstOrNew(['payload->lavaggio_example_code' => $item['code']]);
                // Examples can be adjusted in administration without a later seed overwriting them.
                if ($bundle->exists) {
                    continue;
                }
                $bundle->fill([
                    'title' => $item['title'], 'is_active' => true, 'sort_order' => ($index + 1) * 10,
                    'payload' => ['lavaggio_example_code' => $item['code'], 'local_demo' => true],
                ])->save();
                $bundle->products()->sync($productIds);
                $bundle->discounts()->create([
                    'customer_group_id' => $group->id, 'type' => 'percent', 'value' => $item['discount_percent'],
                ]);
            }
        });
        CartService::forgetRequestSnapshot();
        Cache::flush();
        $this->command?->info(count($items).' local example bundles are ready, each containing two products.');
    }
}
