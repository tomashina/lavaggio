<?php

namespace App\Services\Import;

use App\Models\Catalog\Bundle\Bundle;
use App\Models\Catalog\Product\Product;
use App\Models\Content\Blog\BlogPost;
use App\Support\LibermediaPaymentDescription;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class LibermediaSupplementImportService
{
    private array $maps = [];

    private array $stats = [];

    public function import(string $connection = 'libermedia_source', bool $dryRun = false): array
    {
        $source = DB::connection($connection);
        if (! app()->environment(['local', 'testing']) || ! in_array($source->getConfig('host'), ['127.0.0.1', 'localhost', '::1', null], true)
            || $source->getDatabaseName() === DB::connection()->getDatabaseName()) {
            throw new RuntimeException('Separate local databases are required.');
        }
        $this->stats = [];
        foreach (['review', 'blog_comment', 'bundle', 'bundle_to_product', 'bundle_to_customergroup', 'bundle_order'] as $table) {
            $this->stats['source_'.$table] = $source->table('oc_'.$table)->count();
        }
        if ($dryRun) {
            return $this->stats;
        }
        $this->maps = [];
        foreach (DB::table('herrera_import_maps')->where('source', 'herrera-opencart')->get() as $map) {
            $this->maps[$map->entity][(string) $map->source_id] = $map->target_id;
        }
        DB::transaction(function () use ($source): void {
            foreach (['review' => [Product::class, 'product', 'product_id', 'review_id'], 'blog_comment' => [BlogPost::class, 'blog', 'blog_id', 'blog_comment_id']] as $table => [$model, $entity, $foreign, $key]) {
                foreach ($source->table('oc_'.$table)->get() as $row) {
                    $row = (array) $row;
                    $target = $this->mapped($entity, $row[$foreign]);
                    $fields = ['commentable_type' => (new $model)->getMorphClass(), 'commentable_id' => $target,
                        'user_id' => $this->mapped('customer', $row['customer_id'] ?? 0),
                        'author_name' => $row['author'] ?? $row['name'], 'author_email' => $row['email'] ?? null,
                        'locale' => 'hr', 'body' => html_entity_decode($row['text'] ?? $row['comment'], ENT_QUOTES | ENT_HTML5, 'UTF-8'),
                        'rating' => isset($row['rating']) && $row['rating'] >= 1 && $row['rating'] <= 5 ? $row['rating'] : null,
                        'status' => $row['status'] ? 'approved' : 'pending',
                        'payload' => json_encode(['opencart' => $row, 'import_source' => 'libermedia']),
                        'created_at' => $this->date($row['date_added']) ?? now(), 'updated_at' => $this->date($row['date_modified'] ?? $row['date_added']) ?? now()];
                    $id = $this->record($table, $row[$key], 'content_comments', $fields);
                    $this->stats[$table.'_imported'] = ($this->stats[$table.'_imported'] ?? 0) + 1;
                    if (! $target) {
                        DB::table('libermedia_import_records')->where('entity', $table)->where('source_id', $row[$key])->update(['status' => 'missing_target']);
                        $this->stats[$table.'_missing_target'] = ($this->stats[$table.'_missing_target'] ?? 0) + 1;
                    }
                }
            }
            $titles = $source->table('oc_bundle_language')->where('language_id', 3)->pluck('title', 'bundle_id');
            foreach ($source->table('oc_bundle')->get() as $row) {
                $products = $source->table('oc_bundle_to_product')->where('bundle_id', $row->bundle_id)->get();
                $discounts = $source->table('oc_bundle_to_customergroup')->where('bundle_id', $row->bundle_id)->get();
                $existingBundle = Bundle::where('source_id', $row->bundle_id)->first();
                if (data_get($existingBundle?->payload, 'admin_edited_at')) {
                    $this->stats['admin_bundles_preserved'] = ($this->stats['admin_bundles_preserved'] ?? 0) + 1;

                    continue;
                }
                $bundle = Bundle::updateOrCreate(['source_id' => $row->bundle_id], [
                    'title' => $titles[$row->bundle_id] ?? 'Bundle '.$row->bundle_id,
                    'is_active' => (bool) $row->status, 'starts_at' => $this->date($row->date_from),
                    'ends_at' => $this->date($row->date_to, true), 'sort_order' => max(0, (int) $row->sort_order),
                    'payload' => ['opencart' => (array) $row, 'translations' => $source->table('oc_bundle_language')->where('bundle_id', $row->bundle_id)->get()->toArray(),
                        'categories' => $source->table('oc_bundle_to_category')->where('bundle_id', $row->bundle_id)->get()->toArray(),
                        'stores' => $source->table('oc_bundle_to_store')->where('bundle_id', $row->bundle_id)->get()->toArray(),
                        'source_products' => $products->toArray(), 'source_discounts' => $discounts->toArray()],
                ]);
                $links = [];
                foreach ($products as $product) {
                    if ($id = $this->mapped('product', $product->product_id)) {
                        $links[$id] = ['quantity' => max(1, $product->quantity)];
                    } else {
                        $this->stats['bundle_missing_products'] = ($this->stats['bundle_missing_products'] ?? 0) + 1;
                        $bundle->update(['is_active' => false]);
                    }
                }
                $bundle->products()->sync($links);
                $bundle->discounts()->delete();
                foreach ($discounts as $discount) {
                    if ($group = $this->mapped('customer_group', $discount->customer_group_id)) {
                        $bundle->discounts()->create(['customer_group_id' => $group, 'type' => $discount->discount_option === 'Percentage' ? 'percent' : 'fixed', 'value' => max(0, (float) $discount->add_discount)]);
                    }
                }
            }
            $this->stats['bundles_imported'] = Bundle::whereNotNull('source_id')->count();
            foreach (['bundle_order', 'bundle_category', 'bundle_category_description', 'bundle_category_path', 'bundle_category_to_store', 'bundle_language', 'bundle_to_store', 'order_option', 'order_voucher', 'order_shipment', 'customer_history', 'return', 'return_history', 'return_action', 'return_reason', 'return_status', 'question', 'testimonial', 'testimonial_description', 'testimonial_to_store', 'product_reward', 'product_mpadditional_field', 'product_mpadditional_field_value', 'product_tabs', 'product_tabs_description', 'product_tabs_to_product'] as $table) {
                if (! $source->getSchemaBuilder()->hasTable('oc_'.$table)) {
                    continue;
                }
                foreach ($source->table('oc_'.$table)->get() as $row) {
                    $row = (array) $row;
                    $key = hash('sha256', json_encode($row));
                    DB::table('libermedia_import_records')->updateOrInsert(['entity' => $table, 'source_id' => $key], ['status' => 'archived', 'payload' => json_encode($row), 'updated_at' => now(), 'created_at' => now()]);
                }
                $this->stats['archived_'.$table] = $source->table('oc_'.$table)->count();
            }
            $this->methods($source);
            foreach (['payment', 'shipping'] as $type) {
                $importedIds = DB::table('libermedia_import_records')->where('entity', $type.'_method')->pluck('target_id');
                DB::table($type.'_methods')->whereNotIn('id', $importedIds)->update(['is_active' => false]);
            }
            DB::table('payment_methods')->where('code', 'free_checkout')->update(['max_subtotal' => 0]);

            $settings = $source->table('oc_setting')->where('store_id', 0)->pluck('value', 'key');
            if ($group = $this->mapped('customer_group', (int) ($settings['config_customer_group_id'] ?? 1))) {
                DB::table('customer_groups')->update(['is_default' => false]);
                DB::table('customer_groups')->where('id', $group)->update(['is_default' => true]);
            }
            foreach ($source->table('oc_order_status')->where('language_id', 3)->get() as $row) {
                if ($id = $this->mapped('order_status', $row->order_status_id)) {
                    $complete = json_decode($settings['config_complete_status'] ?? '[]', true) ?: [];
                    DB::table('order_statuses')->where('id', $id)->update(['name' => $row->name, 'is_default' => (int) ($settings['config_order_status_id'] ?? 0) === $row->order_status_id,
                        'is_paid' => in_array($row->order_status_id, array_map('intval', $complete), true),
                        'is_cancelled' => in_array($row->order_status_id, [7, 8, 10, 14, 16], true)]);
                }
            }
            $this->stats['order_statuses_imported'] = $source->table('oc_order_status')->where('language_id', 3)->count();
        });

        return $this->stats;
    }

    private function methods($source): void
    {
        $settings = $source->table('oc_setting')->where('store_id', 0)->pluck('value', 'key');
        foreach (['payment', 'shipping'] as $type) {
            foreach ($source->table('oc_extension')->where('type', $type)->get() as $extension) {
                if ($extension->code === 'xshippingpro') {
                    continue;
                }
                $code = $extension->code;
                $prefix = $type.'_'.$code.'_';
                $name = $source->table('oc_order')->where($type.'_code', $code)->where($type.'_method', '!=', '')->orderByDesc('order_id')->value($type.'_method')
                    ?? ['cod' => 'Plaćanje pouzećem', 'bank_transfer' => 'Bankovna transakcija', 'wspay' => 'Kartično plaćanje (WSPay)', 'free_checkout' => 'Besplatna narudžba', 'free' => 'Besplatna dostava'][$code] ?? $code;
                $name = ['cod' => 'Plaćanje pouzećem', 'bank_transfer' => 'Bankovna transakcija', 'wspay' => 'Kartično plaćanje (WSPay)', 'free_checkout' => 'Besplatna narudžba', 'free' => 'Besplatna dostava'][$code] ?? $name;
                $safe = [];
                foreach (['status', 'sort_order', 'total', 'cost', 'geo_zone_id', 'tax_class_id'] as $key) {
                    $safe[$key] = $settings[$prefix.$key] ?? null;
                }
                $fields = ['code' => $code, 'name' => $name, 'is_active' => (bool) ($safe['status'] ?? false), 'sort_order' => max(0, (int) ($safe['sort_order'] ?? 0)),
                    'min_subtotal' => is_numeric($safe['total']) ? (float) $safe['total'] : null,
                    'settings' => json_encode(['opencart' => $safe, 'import_source' => 'libermedia', 'requires_integration' => $type === 'payment' && ! in_array($code, ['cod', 'bank_transfer', 'free_checkout'])])];
                if ($type === 'payment') {
                    $fields['sort_order'] = $code === 'wspay' ? 0 : max(1, $fields['sort_order']);
                }
                if ($type === 'payment' && $code === 'bank_transfer') {
                    $fields['description'] = LibermediaPaymentDescription::forConsumers(html_entity_decode((string) ($settings['payment_bank_transfer_bank3'] ?? ''), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
                }
                if ($type === 'shipping') {
                    $fields += ['price' => (float) ($safe['cost'] ?? 0), 'pricing_type' => $code === 'free' ? 'free' : 'flat'];
                } else {
                    $fields += ['provider' => $code];
                }
                $this->record($type.'_method', $code, $type.'_methods', $fields, ['code' => $code]);
                $this->stats[$type.'_methods_imported'] = ($this->stats[$type.'_methods_imported'] ?? 0) + 1;
            }
        }
        foreach ($source->table('oc_xshippingpro')->get() as $row) {
            $data = json_decode($row->method_data, true, 512, JSON_THROW_ON_ERROR);
            // Preserve the complete rules; the shipping resolver interprets these directly.
            $this->record('shipping_method', 'xshippingpro.'.$row->id, 'shipping_methods', [
                'code' => 'xshippingpro.xshippingpro'.$row->id, 'name' => $data['display'] ?? 'Dostava',
                'is_active' => (bool) ($data['status'] ?? false), 'price' => (float) ($data['cost'] ?? 0),
                'sort_order' => (int) $row->sort_order, 'pricing_type' => 'flat',
                'settings' => json_encode(['import_source' => 'libermedia', 'opencart_xshippingpro' => $data,
                    'destination_country_codes' => $source->table('oc_zone_to_geo_zone as z')->join('oc_country as c', 'c.country_id', '=', 'z.country_id')->whereIn('z.geo_zone_id', $data['geo_zone'] ?? [])->distinct()->pluck('c.iso_code_2')->all()]),
            ], ['code' => 'xshippingpro.xshippingpro'.$row->id]);
            $this->stats['shipping_methods_imported']++;
        }
    }

    private function record(string $entity, int|string $sourceId, string $table, array $fields, array $identity = []): int
    {
        $map = DB::table('libermedia_import_records')->where('entity', $entity)->where('source_id', (string) $sourceId)->first();
        if ($map?->target_id && json_decode($map->payload ?? '{}', true) === $fields && DB::table($table)->where('id', $map->target_id)->exists()) {
            return (int) $map->target_id;
        }
        $id = $map?->target_id ?? ($identity ? DB::table($table)->where($identity)->value('id') : null);
        $writeFields = $fields;
        if ($id && $entity === 'payment_method' && (string) $sourceId === 'wspay') {
            $existing = DB::table($table)->where('id', $id)->first(['settings', 'is_active']);
            if ($existing) {
                // Source snapshots do not own the merchant's configured TEST/LIVE
                // credentials or the administrator's enable/disable choice.
                $writeFields['settings'] = json_encode(array_replace(
                    json_decode($existing->settings ?? '{}', true) ?: [],
                    json_decode($fields['settings'] ?? '{}', true) ?: [],
                ));
                $writeFields['is_active'] = $existing->is_active;
            }
        }
        if ($id) {
            DB::table($table)->where('id', $id)->update($writeFields + ['updated_at' => now()]);
        } else {
            $id = DB::table($table)->insertGetId($writeFields + ['created_at' => now(), 'updated_at' => now()]);
        }
        DB::table('libermedia_import_records')->updateOrInsert(['entity' => $entity, 'source_id' => (string) $sourceId], ['target_id' => $id, 'status' => 'imported', 'payload' => json_encode($fields), 'updated_at' => now(), 'created_at' => $map?->created_at ?? now()]);

        return $id;
    }

    private function mapped(string $entity, int|string $id): ?int
    {
        return $this->maps[$entity][(string) $id] ?? null;
    }

    private function date(?string $date, bool $end = false): ?string
    {
        if (! $date || str_starts_with($date, '0000')) {
            return null;
        }

        return \Carbon\Carbon::parse($date)->{ $end ? 'endOfDay' : 'startOfSecond' }()->format('Y-m-d H:i:s');
    }
}
