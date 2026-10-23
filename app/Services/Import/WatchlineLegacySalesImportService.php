<?php

namespace App\Services\Import;

use App\Models\Sales\GiftVoucher;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/** Imports historical sales records without replaying payments, mail or refunds. */
class WatchlineLegacySalesImportService
{
    private ConnectionInterface $source;

    private array $maps = [];

    private array $stats = [];

    public function import(string $connection = 'watchline_source', bool $dryRun = false): array
    {
        $this->source = DB::connection($connection);
        $target = DB::connection();
        if (! app()->environment(['local', 'testing']) || $this->source === $target
            || $this->source->getDatabaseName() === $target->getDatabaseName()) {
            throw new RuntimeException('Separate local source and target databases are required.');
        }
        foreach ([$this->source, $target] as $database) {
            if (in_array($database->getDriverName(), ['mysql', 'mariadb', 'pgsql', 'sqlsrv'], true)
                && ! in_array($database->getConfig('host'), ['127.0.0.1', 'localhost', '::1'], true)) {
                throw new RuntimeException('Only isolated local databases are permitted.');
            }
        }
        $this->stats = [];
        foreach (['coupon', 'coupon_product', 'coupon_category', 'coupon_history', 'voucher', 'voucher_history', 'order_voucher', 'return', 'return_history', 'return_action', 'return_reason', 'return_status'] as $table) {
            $this->stats['source_'.$table] = $this->has($table) ? $this->source->table('oc_'.$table)->count() : 0;
        }
        if ($dryRun) {
            return $this->stats;
        }
        $this->maps = [];
        foreach (DB::table('herrera_import_maps')->where('source', 'herrera-opencart')->get() as $map) {
            $this->maps[$map->entity][(string) $map->source_id] = (int) $map->target_id;
        }

        DB::transaction(function (): void {
            $this->coupons();
            $this->vouchers();
            $this->returns();
        });

        return $this->stats;
    }

    private function coupons(): void
    {
        $products = $this->rows('coupon_product')->groupBy('coupon_id');
        $categories = $this->rows('coupon_category')->groupBy('coupon_id');
        $histories = $this->rows('coupon_history')->groupBy('coupon_id');
        foreach ($this->rows('coupon') as $record) {
            $row = (array) $record;
            $id = $row['coupon_id'];
            $history = $histories->get($id, collect())->map(fn ($item) => (array) $item)->all();
            $targets = [];
            $unmappedTargets = false;
            foreach (['product' => $products, 'category' => $categories] as $entity => $groups) {
                foreach ($groups->get($id, collect()) as $edge) {
                    if ($target = $this->mapped($entity, $edge->{$entity.'_id'})) {
                        $key = $entity.':'.$target;
                        $targets[$key] ??= ['target_type' => $entity, 'target_id' => $target, 'sort_order' => count($targets)];
                    } else {
                        $unmappedTargets = true;
                    }
                }
            }
            // Native checkout does not yet enforce per-customer usage or record
            // new coupon usage. Keep these historical rules visible but disabled.
            $review = ['coupon_usage_limits_not_enforced'];
            if ($unmappedTargets) {
                $review[] = 'unmapped_coupon_targets';
            }
            if ($row['logged'] || $row['shipping'] || ($products->has($id) && $categories->has($id))) {
                $review[] = 'unsupported_coupon_condition';
            }
            $targetType = $products->has($id) ? 'product' : ($categories->has($id) ? 'category' : 'all');
            $action = $this->create('watchline_coupon', $id, 'catalog_actions', [
                'code' => 'watchline-oc-coupon-'.$id, 'scope' => 'cart',
                'type' => $row['type'] === 'P' ? 'percentage' : 'fixed_amount',
                'discount_value' => $row['discount'], 'target_type' => $targetType, 'audience_type' => 'all',
                'coupon_code' => $row['code'], 'min_subtotal' => $row['total'],
                'starts_at' => $this->date($row['date_start']), 'ends_at' => $this->date($row['date_end'], true),
                'is_active' => false, 'usage_limit' => (int) $row['uses_total'] > 0 ? (int) $row['uses_total'] : null,
                'usage_limit_per_user' => (int) $row['uses_customer'] > 0 ? (int) $row['uses_customer'] : null,
                'used_count' => count($history), 'payload' => $this->json(['opencart' => $row, 'history' => $history,
                    'import_source' => 'watchline', 'import_requires_review' => $review]),
            ], $row, function (int $target) use ($row, $targets): void {
                DB::table('catalog_action_translations')->insert(['action_id' => $target, 'locale' => 'hr',
                    'title' => $row['name'], 'created_at' => now(), 'updated_at' => now()]);
                foreach ($targets as $edge) {
                    DB::table('catalog_action_targets')->insert($edge + ['action_id' => $target, 'created_at' => now(), 'updated_at' => now()]);
                }
            });
            foreach ($history as $entry) {
                $entry['catalog_action_id'] = $action;
                $this->attach('orders', $this->mapped('order', $entry['order_id']), 'coupons', $entry['coupon_history_id'], $entry);
                $this->attachProfile($entry['customer_id'], 'coupons', $entry['coupon_history_id'], $entry);
            }
        }
        foreach (['coupon', 'coupon_product', 'coupon_category', 'coupon_history'] as $table) {
            foreach ($this->rows($table) as $row) {
                $this->archive($table, (array) $row);
            }
        }
    }

    private function vouchers(): void
    {
        $histories = $this->rows('voucher_history')->groupBy('voucher_id');
        foreach ($this->rows('voucher') as $record) {
            $row = (array) $record;
            $history = $histories->get($row['voucher_id'], collect());
            $initial = (int) round((float) $row['amount'] * 100);
            $balance = $initial + (int) round((float) $history->sum('amount') * 100);
            $purchaseOrder = $this->mapped('order', $row['order_id']);
            $currency = $purchaseOrder ? DB::table('orders')->where('id', $purchaseOrder)->value('currency_code') : null;
            $safe = $this->safeVoucherRow($row);
            $this->archive('voucher', $safe);
            if ($initial <= 0 || $balance < 0 || $balance > $initial || ! $currency || ! filter_var($row['to_email'], FILTER_VALIDATE_EMAIL)) {
                $this->stats['voucher_archive_only'] = ($this->stats['voucher_archive_only'] ?? 0) + 1;

                continue;
            }
            $voucher = new GiftVoucher;
            $voucher->storeCode($row['code']);
            $target = $this->create('watchline_voucher', $row['voucher_id'], 'gift_vouchers', [
                'purchase_order_id' => $purchaseOrder, 'cart_item_key' => 'watchline-oc-voucher-'.$row['voucher_id'],
                ...$voucher->getAttributes(), 'initial_amount' => $initial / 100, 'balance' => $balance / 100,
                'currency' => $currency, 'buyer_name' => $row['from_name'], 'buyer_email' => $row['from_email'],
                'sender_name' => $row['from_name'], 'recipient_name' => $row['to_name'], 'recipient_email' => $row['to_email'],
                'message' => $row['message'], 'locale' => 'hr',
                // An unused historical balance requires review before activation;
                // importing it must not enqueue an old gift email automatically.
                'status' => $row['status'] && $balance === 0 ? 'exhausted' : 'disabled',
                'issued_at' => $this->date($row['date_added']),
            ], $safe);
            $this->attach('orders', $purchaseOrder, 'vouchers_purchased', $row['voucher_id'], $safe + ['gift_voucher_id' => $target]);
            foreach ($history as $entry) {
                $entry = (array) $entry;
                $order = $this->mapped('order', $entry['order_id']);
                $this->archive('voucher_history', $entry);
                $this->attach('orders', $order, 'vouchers_redeemed', $entry['voucher_history_id'], $entry + ['gift_voucher_id' => $target]);
                if ($target && $order && (float) $entry['amount'] < 0
                    && ! DB::table('gift_voucher_redemptions')->where('order_id', $order)->exists()) {
                    $this->create('watchline_voucher_history', $entry['voucher_history_id'], 'gift_voucher_redemptions', [
                        'gift_voucher_id' => $target, 'order_id' => $order, 'amount' => abs((float) $entry['amount']),
                        'status' => 'redeemed', 'redeemed_at' => $this->date($entry['date_added']),
                    ], $entry);
                }
            }
        }
        foreach ($this->rows('voucher_history') as $row) {
            $this->archive('voucher_history', (array) $row);
        }
        foreach ($this->rows('order_voucher') as $record) {
            $row = $this->safeVoucherRow((array) $record);
            $this->archive('order_voucher', $row);
            $this->attach('orders', $this->mapped('order', $row['order_id']), 'order_vouchers', $row['order_voucher_id'], $row);
        }
    }

    private function returns(): void
    {
        $history = $this->rows('return_history')->groupBy('return_id');
        $labels = [];
        foreach (['return_action', 'return_reason', 'return_status'] as $table) {
            foreach ($this->rows($table) as $record) {
                $row = (array) $record;
                $labels[$table][$row[$table.'_id']][$row['language_id']] = $row['name'];
                $this->archive($table, $row);
            }
        }
        foreach ($this->rows('return') as $record) {
            $row = (array) $record;
            $this->archive('return', $row);
            $snapshot = ['opencart' => $row, 'history' => $history->get($row['return_id'], collect())->map(fn ($item) => (array) $item)->all()];
            foreach (['return_action', 'return_reason', 'return_status'] as $table) {
                $snapshot[$table] = $labels[$table][$row[$table.'_id']] ?? [];
            }
            $this->attach('orders', $this->mapped('order', $row['order_id']), 'returns', $row['return_id'], $snapshot);
            $this->attachProfile($row['customer_id'], 'returns', $row['return_id'], $snapshot);
            $this->stats['returns_preserved'] = ($this->stats['returns_preserved'] ?? 0) + 1;
        }
        foreach ($this->rows('return_history') as $row) {
            $this->archive('return_history', (array) $row);
        }
    }

    private function create(string $entity, int|string $sourceId, string $table, array $fields, array $row, ?callable $afterCreate = null): ?int
    {
        if ($target = $this->mapped($entity, $sourceId)) {
            // Mapped records belong to the administrator after the first import.
            $this->stats[$entity.'_preserved'] = ($this->stats[$entity.'_preserved'] ?? 0) + 1;

            return DB::table($table)->where('id', $target)->exists() ? $target : null;
        }
        if (($table === 'catalog_actions' && DB::table($table)->where('code', $fields['code'])->exists())
            || ($table === 'gift_vouchers' && DB::table($table)->where('code_hash', $fields['code_hash'])->exists())) {
            $this->stats[$entity.'_conflicts'] = ($this->stats[$entity.'_conflicts'] ?? 0) + 1;

            return null;
        }
        $target = DB::table($table)->insertGetId($fields + ['created_at' => $this->date($row['date_added'] ?? null) ?? now(), 'updated_at' => now()]);
        DB::table('herrera_import_maps')->insert(['source' => 'herrera-opencart', 'entity' => $entity, 'source_id' => (string) $sourceId,
            'target_id' => $target, 'checksum' => hash('sha256', $this->json($row)), 'created_at' => now(), 'updated_at' => now()]);
        $this->maps[$entity][(string) $sourceId] = $target;
        $afterCreate?->__invoke($target);
        $this->stats[$entity.'_created'] = ($this->stats[$entity.'_created'] ?? 0) + 1;

        return $target;
    }

    private function attachProfile(int|string $customer, string $domain, int|string $sourceId, array $value): void
    {
        $user = $this->mapped('customer', $customer);
        $profile = $user ? DB::table('user_profiles')->where('user_id', $user)->value('id') : null;
        $this->attach('user_profiles', $profile, $domain, $sourceId, $value);
    }

    private function attach(string $table, ?int $id, string $domain, int|string $sourceId, array $value): void
    {
        if (! $id || ! $row = DB::table($table)->where('id', $id)->lockForUpdate()->first(['payload'])) {
            return;
        }
        $payload = json_decode($row->payload ?: '{}', true, 512, JSON_THROW_ON_ERROR);
        if (array_key_exists((string) $sourceId, $payload['watchline_legacy_sales'][$domain] ?? [])) {
            return;
        }
        $payload['watchline_legacy_sales'][$domain][(string) $sourceId] = $value;
        DB::table($table)->where('id', $id)->update(['payload' => $this->json($payload), 'updated_at' => now()]);
    }

    private function archive(string $table, array $row): void
    {
        $json = $this->json($row);
        $checksum = hash('sha256', $json);
        DB::table('herrera_source_records')->insertOrIgnore(['source_table' => $table, 'source_key' => $checksum,
            'checksum' => $checksum, 'payload' => $json, 'created_at' => now(), 'updated_at' => now()]);
    }

    private function safeVoucherRow(array $row): array
    {
        if (isset($row['code'])) {
            $row['code_sha256'] = hash('sha256', strtoupper(trim($row['code'])));
            $previous = DB::table('herrera_source_records')->where('source_table', 'voucher')
                ->where('payload->code_sha256', $row['code_sha256'])->value('payload');
            $previous = $previous ? json_decode($previous, true, 512, JSON_THROW_ON_ERROR) : [];
            $row['code_ciphertext'] = $previous['code_ciphertext'] ?? Crypt::encryptString($row['code']);
            unset($row['code']);
        }

        return $row;
    }

    private function mapped(string $entity, int|string $id): ?int
    {
        return $this->maps[$entity][(string) $id] ?? null;
    }

    private function has(string $table): bool
    {
        return $this->source->getSchemaBuilder()->hasTable('oc_'.$table);
    }

    private function rows(string $table): \Illuminate\Support\Collection
    {
        return $this->has($table) ? $this->source->table('oc_'.$table)->get() : collect();
    }

    private function date(?string $value, bool $end = false): ?string
    {
        if (! $value || str_starts_with($value, '0000')) {
            return null;
        }

        return \Carbon\Carbon::parse($value)->{ $end ? 'endOfDay' : 'startOfSecond' }()->format('Y-m-d H:i:s');
    }

    private function json(array $value): string
    {
        return json_encode($value, JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE | JSON_UNESCAPED_UNICODE);
    }
}
