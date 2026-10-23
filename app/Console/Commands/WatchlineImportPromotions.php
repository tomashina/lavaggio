<?php

namespace App\Console\Commands;

use App\Models\Catalog\Action\CatalogAction;
use App\Models\Catalog\Pricing\PriceCatalog;
use App\Models\Catalog\Pricing\PriceCatalogEntry;
use App\Models\User\CustomerGroup;
use App\Services\Front\GuestStorefrontCache;
use App\Services\Import\WatchlineSpecialSchedule;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class WatchlineImportPromotions extends Command
{
    private const SOURCE = 'watchline-opencart-special';

    protected $signature = 'watchline:import-promotions {--force : Replace only promotions previously created by this command}';

    protected $description = 'Publish faithful B2C Watchline special-price schedules without activating a B2B price catalog';

    public function handle(WatchlineSpecialSchedule $schedule, GuestStorefrontCache $storefrontCache): int
    {
        if (! app()->environment('local') || strcasecmp((string) config('app.name'), 'Watchline') !== 0) {
            $this->error('Restricted to the local Watchline preview.');

            return self::FAILURE;
        }
        $source = DB::connection('watchline_source');
        if (! in_array($source->getConfig('host'), ['127.0.0.1', 'localhost', '::1'], true)) {
            $this->error('An isolated local Watchline snapshot is required.');

            return self::FAILURE;
        }
        $publicGroupId = (int) $source->table('oc_setting')->where('store_id', 0)->where('key', 'config_customer_group_id')->value('value');
        $group = CustomerGroup::where('is_active', true)->get()
            ->first(fn (CustomerGroup $group): bool => (int) data_get($group->payload, 'opencart.customer_group_id') === $publicGroupId);
        $catalog = PriceCatalog::where('source_system', 'herrera-opencart')->where('source_snapshot', $source->getDatabaseName())
            ->where('status', PriceCatalog::DRAFT)->where('currency_code', 'EUR')->latest('id')->first();
        if ($publicGroupId < 1 || ! $group || ! $catalog || data_get($catalog->metadata, 'import_complete') !== true) {
            $this->error('A complete Watchline EUR source catalog and its configured public customer group are required.');

            return self::FAILURE;
        }
        $bases = $catalog->entries()->where('kind', PriceCatalogEntry::BASE)->where('is_active', true)
            ->whereNull('customer_group_id')->whereNull('user_id')->get()->keyBy('product_id');
        $specials = $catalog->entries()->where('kind', PriceCatalogEntry::SPECIAL)->where('is_active', true)
            ->where('customer_group_id', $group->id)->whereNull('user_id')->where('minimum_quantity', 1)
            ->with('product.translations')->orderBy('product_id')->orderBy('priority')->orderBy('price')->orderBy('id')->get();
        $references = $source->getSchemaBuilder()->hasTable('oc_anchor_price')
            ? $source->table('oc_anchor_price')->get(['product_id', 'price', 'gross_price', 'currency_code', 'reference_date', 'rule_code', 'source', 'verification_status'])->keyBy('product_id')
            : collect();
        $plan = [];
        $skipped = 0;
        foreach ($specials->groupBy('product_id') as $productId => $productSpecials) {
            $base = $bases->get($productId);
            if (! $base) {
                $skipped += $productSpecials->count();

                continue;
            }
            foreach ($schedule->segments($productSpecials) as $segment) {
                $special = $segment['special'];
                $product = $special->product;
                if (! $product || (float) $special->price <= 0 || (float) $special->price >= (float) $base->price
                    || abs((float) $product->base_price - (float) $base->price) > 0.00001) {
                    $skipped++;

                    continue;
                }
                $sourceProductId = (int) data_get($special->payload, 'opencart.product_id');
                $sourceSpecialId = (int) data_get($special->payload, 'opencart.product_special_id');
                if ($sourceProductId < 1 || $sourceSpecialId < 1 || $sourceProductId !== (int) data_get($base->payload, 'opencart.product_id')) {
                    $this->error('Inconsistent source provenance; no promotions were saved.');

                    return self::FAILURE;
                }
                $discount = round((float) $base->price - (float) $special->price, 2);
                if ($discount <= 0 || round((float) $base->price - $discount, 2) !== round((float) $special->price, 2)) {
                    $skipped++;

                    continue;
                }
                $code = 'watchline-oc-special-'.$sourceProductId.'-'.$sourceSpecialId.'-'.substr(hash('sha256', $segment['starts_at'].'|'.$segment['ends_at']), 0, 12);
                $plan[$code] = compact('special', 'base', 'sourceProductId', 'sourceSpecialId', 'discount', 'segment');
            }
        }
        $written = $preserved = 0;
        DB::transaction(function () use ($plan, $catalog, $references, $publicGroupId, &$written, &$preserved): void {
            foreach ($plan as $code => $row) {
                ['special' => $special, 'base' => $base, 'sourceProductId' => $sourceProductId, 'sourceSpecialId' => $sourceSpecialId, 'discount' => $discount, 'segment' => $segment] = $row;
                $action = CatalogAction::firstOrNew(['code' => $code]);
                if ($action->exists && (data_get($action->payload, 'import_source') !== self::SOURCE || ! $this->option('force'))) {
                    $preserved++;

                    continue;
                }
                $action->fill([
                    'scope' => CatalogAction::SCOPE_PRODUCT, 'type' => CatalogAction::TYPE_FIXED,
                    'discount_value' => $discount, 'target_type' => CatalogAction::TARGET_PRODUCT,
                    'audience_type' => CatalogAction::AUDIENCE_ALL, 'customer_group_id' => null,
                    'role_id' => null, 'user_id' => null, 'coupon_code' => null,
                    'starts_at' => $segment['starts_at'], 'ends_at' => $segment['ends_at'],
                    'priority' => 100, 'is_exclusive' => true, 'is_active' => true,
                    'payload' => [
                        'import_source' => self::SOURCE, 'source_catalog_id' => $catalog->id,
                        'source_snapshot' => $catalog->source_snapshot, 'source_checksum' => $catalog->source_checksum,
                        'source_entry_id' => $special->id, 'source_key' => $special->source_key,
                        'source_product_id' => $sourceProductId, 'source_special_id' => $sourceSpecialId,
                        'source_customer_group_id' => $publicGroupId, 'source_priority' => (int) $special->priority,
                        'source_base_price' => (string) $base->price, 'source_special_price' => (string) $special->price,
                        'source_reference_price' => $references->has($sourceProductId) ? (array) $references->get($sourceProductId) : null,
                        'currency_code' => 'EUR',
                    ],
                ])->save();
                $name = $special->product->translations->firstWhere('locale', 'hr')?->name ?? $special->product->sku;
                $action->translations()->updateOrCreate(['locale' => 'hr'], [
                    'title' => 'Akcija: '.$name, 'description' => 'Akcijska ponuda Watchline.', 'badge' => 'Akcija',
                ]);
                $action->targets()->delete();
                $action->targets()->create(['target_type' => CatalogAction::TARGET_PRODUCT, 'target_id' => $special->product_id, 'sort_order' => 0]);
                $written++;
            }
            if ($this->option('force')) {
                CatalogAction::where('payload->import_source', self::SOURCE)->whereNotIn('code', array_keys($plan))->update(['is_active' => false]);
            }
        });
        Storage::disk('local')->put('watchline-migration/promotions-report.json', json_encode([
            'source_special_rows' => $specials->count(), 'scheduled_periods' => count($plan),
            'written' => $written, 'preserved' => $preserved, 'invalid_periods_skipped' => $skipped,
            'source_customer_group_id' => $publicGroupId,
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        if ($written > 0) {
            $storefrontCache->invalidate();
        }
        $this->info("Watchline promotion periods saved: {$written}; preserved: {$preserved}; invalid skipped: {$skipped}. The source price catalog remains draft.");

        return self::SUCCESS;
    }
}
