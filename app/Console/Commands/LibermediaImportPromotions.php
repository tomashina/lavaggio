<?php

namespace App\Console\Commands;

use App\Models\Catalog\Action\CatalogAction;
use App\Models\Catalog\Pricing\PriceCatalog;
use App\Models\Catalog\Pricing\PriceCatalogEntry;
use App\Models\User\CustomerGroup;
use App\Services\Front\GuestStorefrontCache;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class LibermediaImportPromotions extends Command
{
    private const SOURCE = 'libermedia-opencart-special';

    protected $signature = 'libermedia:import-promotions {--force : Replace only promotions previously created by this command}';

    protected $description = 'Convert verified Liber Media snapshot specials to public, admin-managed product promotions';

    public function handle(GuestStorefrontCache $storefrontCache): int
    {
        if (! app()->environment('local') || config('app.name') !== 'Libermedia') {
            $this->error('Restricted to the local Libermedia preview.');

            return self::FAILURE;
        }

        $catalog = PriceCatalog::query()->where('source_system', 'herrera-opencart')
            ->where('source_snapshot', config('database.connections.libermedia_source.database'))->where('status', PriceCatalog::DRAFT)
            ->where('currency_code', 'EUR')->latest('id')->first();
        $group = CustomerGroup::query()->where('is_active', true)->get()
            ->first(fn (CustomerGroup $group): bool => (int) data_get($group->payload, 'opencart.customer_group_id') === 1);
        if (! $catalog || ! $group || data_get($catalog->metadata, 'import_complete') !== true) {
            $this->error('A complete EUR Liber Media draft and its original Default customer group are required.');

            return self::FAILURE;
        }

        $bases = $catalog->entries()->where('kind', PriceCatalogEntry::BASE)
            ->where('is_active', true)->whereNull('customer_group_id')->whereNull('user_id')
            ->get()->keyBy('product_id');
        // OpenCart chooses the lowest priority, then the lowest special price.
        $specials = $catalog->entries()->where('kind', PriceCatalogEntry::SPECIAL)
            ->where('is_active', true)->where('customer_group_id', $group->id)->whereNull('user_id')
            ->where('minimum_quantity', 1)->with('product.translations')
            ->orderBy('priority')->orderBy('price')->orderBy('id')->get();
        $selected = collect();
        $skipped = 0;
        foreach ($specials as $special) {
            $base = $bases->get($special->product_id);
            $product = $special->product;
            if (! $base || ! $product || (float) $special->price <= 0
                || (float) $special->price >= (float) $base->price
                || abs((float) $product->base_price - (float) $base->price) > 0.00001
                || ($special->starts_at && $special->ends_at && $special->ends_at->lte($special->starts_at))) {
                $skipped++;

                continue;
            }
            if ($selected->has($product->id)) {
                continue;
            }
            $sourceProductId = (int) data_get($special->payload, 'opencart.product_id');
            if ($sourceProductId < 1 || $sourceProductId !== (int) data_get($base->payload, 'opencart.product_id')) {
                $this->error('Snapshot product provenance is inconsistent; no promotions were written.');

                return self::FAILURE;
            }
            $discount = round(round((float) $base->price, 2) - round((float) $special->price, 2), 2);
            if ($discount <= 0 || round((float) $base->price - $discount, 2) !== round((float) $special->price, 2)) {
                $this->error('A source special cannot be represented faithfully at cent precision; no promotions were written.');

                return self::FAILURE;
            }
            $selected->put($product->id, compact('special', 'base', 'sourceProductId', 'discount'));
        }

        $written = 0;
        $preserved = 0;
        DB::transaction(function () use ($catalog, $selected, &$written, &$preserved): void {
            foreach ($selected as $row) {
                ['special' => $special, 'base' => $base, 'sourceProductId' => $sourceProductId, 'discount' => $discount] = $row;
                $action = CatalogAction::query()->firstOrNew(['code' => 'libermedia-oc-special-'.$sourceProductId]);
                if ($action->exists && (data_get($action->payload, 'import_source') !== self::SOURCE || ! $this->option('force'))) {
                    $preserved++;

                    continue;
                }
                $action->fill([
                    'scope' => CatalogAction::SCOPE_PRODUCT, 'type' => CatalogAction::TYPE_FIXED,
                    'discount_value' => $discount, 'target_type' => CatalogAction::TARGET_PRODUCT,
                    'audience_type' => CatalogAction::AUDIENCE_ALL, 'customer_group_id' => null,
                    'role_id' => null, 'user_id' => null, 'coupon_code' => null,
                    'starts_at' => $special->starts_at, 'ends_at' => $special->ends_at,
                    'priority' => 100, 'is_exclusive' => true, 'is_active' => true,
                    'payload' => [
                        'import_source' => self::SOURCE, 'source_catalog_id' => $catalog->id,
                        'source_snapshot' => $catalog->source_snapshot, 'source_checksum' => $catalog->source_checksum,
                        'source_entry_id' => $special->id, 'source_key' => $special->source_key,
                        'source_product_id' => $sourceProductId, 'source_customer_group_id' => 1,
                        'source_base_price' => (string) $base->price, 'source_special_price' => (string) $special->price,
                        'currency_code' => 'EUR',
                    ],
                ])->save();
                $name = $special->product->translations->firstWhere('locale', 'hr')?->name ?? $special->product->sku;
                $action->translations()->updateOrCreate(['locale' => 'hr'], [
                    'title' => 'Akcija: '.$name, 'description' => 'Akcijska ponuda Liber Media.', 'badge' => 'Akcija',
                ]);
                $action->targets()->delete();
                $action->targets()->create(['target_type' => CatalogAction::TARGET_PRODUCT, 'target_id' => $special->product_id, 'sort_order' => 0]);
                $written++;
            }
        });
        if ($written > 0) {
            $storefrontCache->invalidate();
        }
        $this->info("Public promotions saved: {$written}; existing preserved: {$preserved}; invalid specials skipped: {$skipped}. Source catalog remains draft.");

        return self::SUCCESS;
    }
}
