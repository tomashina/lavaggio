<?php

namespace App\Services\Integrations\Watchline;

use App\Models\Catalog\Product\Product;
use App\Models\Catalog\Product\ProductPriceHistory;
use App\Models\Integrations\Stock\StockSyncRun;
use App\Services\Front\GuestStorefrontCache;
use App\Services\Pricing\PriceCatalogResolver;
use Brick\Math\BigDecimal;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Throwable;

class GathSyncService
{
    public function __construct(private readonly GathCsvReader $reader) {}

    /** A preview is read-only, including the integration audit tables. */
    public function preview(string $path): array
    {
        $this->assertWatchline();
        $feed = $this->reader->read($path);

        return $this->plan($feed, $this->products(), false)['report'];
    }

    public function apply(string $path, bool $resetMissing = false, ?int $actorId = null, ?string $expectedChecksum = null): array
    {
        $this->assertWatchline();
        $feed = $this->reader->read($path);
        if ($expectedChecksum !== null && ! hash_equals($expectedChecksum, $feed['checksum'])) {
            throw new RuntimeException('GATH datoteka promijenjena je nakon pregleda. Ponovno pregledajte promjene prije primjene.');
        }
        $locks = [];
        $run = null;
        try {
            // Coordinate with the existing spreadsheet and stock importers.
            foreach (['base_price', 'stock_qty'] as $field) {
                $lock = Cache::lock('herrera-stock-sync:'.$field, 600);
                if (! $lock->get()) {
                    throw new RuntimeException('Ažuriranje cijena ili zaliha već je u tijeku. Pokušajte ponovno nakon završetka.');
                }
                $locks[] = $lock;
            }
            $run = StockSyncRun::query()->create([
                'supplier' => 'gath', 'trigger' => 'manual', 'actor_id' => $actorId,
                'status' => 'running', 'started_at' => now(),
                'fetched_count' => $feed['fetched_count'],
                'summary' => $this->sourceSummary($feed) + ['reset_missing' => $resetMissing],
            ]);
            $report = DB::transaction(function () use ($feed, $resetMissing, $actorId, $run): array {
                $plan = $this->plan($feed, $this->products(true), $resetMissing);
                $report = $plan['report'];
                if (! $report['can_apply']) {
                    throw new RuntimeException('GATH CSV nije povezan ni s jednim artiklom. Zalihe i cijene su sačuvane.');
                }
                $draftEntries = 0;
                $items = [];
                foreach ($plan['items'] as $item) {
                    /** @var Product $product */
                    $product = $item['product'];
                    $changes = $item['changes'];
                    if ($changes !== []) {
                        // Raw updates prevent stock notifications or other outbound side effects.
                        DB::table('products')->where('id', $product->id)->update($changes + ['updated_at' => now()]);
                        if (array_key_exists('base_price', $changes)) {
                            ProductPriceHistory::query()->create([
                                'product_id' => $product->id, 'price_type' => 'base',
                                'old_price' => $this->price($product->getRawOriginal('base_price')), 'new_price' => $changes['base_price'],
                                'currency_code' => 'EUR', 'effective_at' => now(), 'source' => 'gath',
                                'changed_by' => $actorId, 'payload' => ['feed_checksum' => $feed['checksum']],
                            ]);
                        }
                    }
                    if (! $item['reset']) {
                        $draftEntries += $this->updateDraftBaseEntries($product, $item['row']['price']);
                    }
                    $items[] = [
                        'run_id' => $run->id, 'product_id' => $product->id,
                        'identifier' => $item['identifier'], 'status' => $changes === [] ? 'unchanged' : 'updated',
                        'old_quantity' => (int) $product->stock_qty,
                        'new_quantity' => $item['reset'] ? 0 : $item['row']['quantity'],
                        'message' => $item['reset'] ? 'Artikl nedostaje u CSV izvještaju; vlastita zaliha postavljena je na 0.'
                            : 'Osnovna cijena EUR: '.$this->price($product->getRawOriginal('base_price')).' → '.$item['row']['price'].'.',
                        'created_at' => now(), 'updated_at' => now(),
                    ];
                }
                foreach ($plan['unmatched'] as $row) {
                    $items[] = [
                        'run_id' => $run->id, 'product_id' => null, 'identifier' => $row['identifier'],
                        'status' => 'unmatched', 'old_quantity' => null, 'new_quantity' => $row['quantity'],
                        'message' => null, 'created_at' => now(), 'updated_at' => now(),
                    ];
                }
                foreach (array_chunk($items, 250) as $chunk) {
                    DB::table('stock_sync_items')->insert($chunk);
                }
                $report['draft_base_entry_updated_count'] = $draftEntries;
                $run->update([
                    'status' => 'completed', 'completed_at' => now(),
                    'matched_count' => $report['matched_count'], 'updated_count' => $report['updated_count'],
                    'unchanged_count' => $report['unchanged_count'], 'unmatched_count' => $report['unmatched_count'],
                    'summary' => $report,
                ]);

                return $report + ['status' => 'completed', 'run_id' => $run->id];
            });
            app(PriceCatalogResolver::class)->forgetActiveCatalog();
            app(GuestStorefrontCache::class)->invalidate();

            return $report;
        } catch (Throwable $exception) {
            if ($run !== null) {
                $run->update([
                    'status' => 'failed', 'completed_at' => now(),
                    'error_message' => 'GATH uvoz nije proveden. Zalihe i cijene su sačuvane. Provjerite CSV datoteku i povezivanje SKU oznaka.',
                ]);
            }
            // SQL exceptions and file paths can carry private data; do not forward them.
            if (get_class($exception) === RuntimeException::class) {
                throw $exception;
            }
            throw new RuntimeException('GATH uvoz nije proveden. Nijedna promjena cijene ili zalihe nije spremljena.');
        } finally {
            foreach (array_reverse($locks) as $lock) {
                $lock->release();
            }
        }
    }

    private function plan(array $feed, Collection $products, bool $resetMissing): array
    {
        $report = $this->sourceSummary($feed) + [
            'can_apply' => false, 'matched_count' => 0, 'matched_identifiers' => 0,
            'updated_count' => 0, 'unchanged_count' => 0, 'unmatched_count' => 0,
            'stock_changed_count' => 0, 'price_changed_count' => 0, 'reactivated_count' => 0,
            'duplicate_product_sku_count' => 0, 'reset_missing' => $resetMissing,
            'reset_candidate_count' => 0, 'reset_count' => 0, 'examples' => [],
        ];
        $matched = [];
        $items = [];
        foreach ($products as $product) {
            $legacy = $product->payload['opencart'] ?? [];
            // OpenCart identifiers can differ from the uniqueness-adjusted native SKU.
            $identifier = (string) (array_key_exists('sku', $legacy) ? $legacy['sku'] : $product->sku);
            $key = GathCsvReader::key($identifier);
            $row = $identifier !== '' ? ($feed['rows'][$key] ?? null) : null;
            $reset = $row === null && $this->isManaged($product) && (int) $product->stock_qty !== 0;
            if ($reset) {
                $report['reset_candidate_count']++;
            }
            if ($row === null && (! $resetMissing || ! $reset)) {
                continue;
            }
            $changes = [];
            $newQuantity = $row === null ? 0 : $row['quantity'];
            if ((int) $product->stock_qty !== $newQuantity) {
                $changes['stock_qty'] = $newQuantity;
                $report['stock_changed_count']++;
            }
            if ($row !== null) {
                $matched[$key] = ($matched[$key] ?? 0) + 1;
                $report['matched_count']++;
                if ($this->price($product->getRawOriginal('base_price')) !== $row['price']) {
                    $changes['base_price'] = $row['price'];
                    $report['price_changed_count']++;
                }
                if (! $product->is_active) {
                    $changes['is_active'] = true;
                    $report['reactivated_count']++;
                }
                if (! $this->isManaged($product)) {
                    $payload = $product->payload ?? [];
                    $payload['gath'] = array_replace(is_array($payload['gath'] ?? null) ? $payload['gath'] : [], ['managed' => true]);
                    $changes['payload'] = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
                }
            } else {
                $report['reset_count']++;
            }
            $report[$changes === [] ? 'unchanged_count' : 'updated_count']++;
            $items[] = ['product' => $product, 'identifier' => $identifier, 'row' => $row, 'reset' => $row === null, 'changes' => $changes];
            if ($changes !== [] && count($report['examples']) < 25) {
                $report['examples'][] = [
                    'product_id' => $product->id, 'sku' => $identifier,
                    'old_quantity' => (int) $product->stock_qty, 'new_quantity' => $newQuantity,
                    'old_price' => $this->price($product->getRawOriginal('base_price')), 'new_price' => $row['price'] ?? $this->price($product->getRawOriginal('base_price')),
                    'reactivate' => array_key_exists('is_active', $changes), 'reset' => $row === null,
                ];
            }
        }
        $unmatched = array_diff_key($feed['rows'], $matched);
        $report['unmatched_count'] = count($unmatched);
        $report['matched_identifiers'] = count($matched);
        $report['duplicate_product_sku_count'] = array_sum(array_map(fn (int $count): int => max(0, $count - 1), $matched));
        $report['can_apply'] = $matched !== [];

        return ['report' => $report, 'items' => $items, 'unmatched' => $unmatched];
    }

    private function products(bool $lock = false): Collection
    {
        return Product::query()->when($lock, fn ($query) => $query->lockForUpdate())
            ->orderBy('id')->get(['id', 'code', 'sku', 'payload', 'base_price', 'stock_qty', 'supplier_stock_qty', 'is_active']);
    }

    private function isManaged(Product $product): bool
    {
        return ! empty($product->payload['opencart']['product_id']) || ($product->payload['gath']['managed'] ?? false) === true;
    }

    private function updateDraftBaseEntries(Product $product, string $price): int
    {
        $sourceId = $product->payload['opencart']['product_id'] ?? null;
        if (! $sourceId) {
            return 0;
        }

        // Published catalogs are immutable. Only the imported Watchline draft base is refreshed.
        return DB::table('catalog_price_entries')
            ->where('product_id', $product->id)->where('kind', 'base')->where('source_key', 'product:'.$sourceId)
            ->whereIn('price_catalog_id', DB::table('catalog_price_catalogs')->select('id')->where('status', 'draft')
                ->where(function ($query): void {
                    $query->where('source_system', 'watchline-opencart')->orWhere('source_snapshot', 'like', 'watchline%');
                }))
            ->where('price', '!=', $price)->update(['price' => $price, 'updated_at' => now()]);
    }

    private function sourceSummary(array $feed): array
    {
        return array_intersect_key($feed, array_flip(['fetched_count', 'duplicate_count', 'checksum', 'filename', 'file_modified_at', 'bytes']));
    }

    private function price(mixed $price): string
    {
        return (string) BigDecimal::of((string) ($price ?? 0))->toScale(4);
    }

    private function assertWatchline(): void
    {
        if (config('storefront.home') !== 'watchline') {
            throw new RuntimeException('GATH uvoz dostupan je samo za Watchline trgovinu.');
        }
    }
}
