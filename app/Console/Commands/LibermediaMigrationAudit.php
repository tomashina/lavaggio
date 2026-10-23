<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class LibermediaMigrationAudit extends Command
{
    protected $signature = 'libermedia:migration-audit';

    protected $description = 'Reconcile imported local records and historical order amounts with the OpenCart snapshot';

    public function handle(): int
    {
        if (! app()->environment('local')) {
            return self::FAILURE;
        }
        $source = DB::connection('libermedia_source');
        $report = ['source_snapshot' => $source->getDatabaseName(), 'checked_at' => now()->toIso8601String(), 'records' => [], 'problems' => []];
        foreach (['category' => 'categories', 'manufacturer' => 'catalog_manufacturers', 'product' => 'products', 'customer' => 'users', 'address' => 'user_addresses', 'order' => 'orders', 'order_product' => 'order_items', 'order_total' => 'order_totals', 'order_history' => 'order_history', 'blog' => 'content_blog_posts', 'information' => 'content_info_pages'] as $entity => $table) {
            $expected = $source->table('oc_'.$entity)->count();
            $ids = DB::table('herrera_import_maps')->where('source', 'herrera-opencart')->where('entity', $entity)->pluck('target_id');
            $actual = DB::table($table)->whereIn('id', $ids)->count();
            $report['records'][$entity] = ['source' => $expected, 'imported' => $actual];
            if ($actual !== $expected) {
                $report['problems'][] = $entity.': count mismatch';
            }
        }
        foreach (['review' => ['content_comments', 'review'], 'bundle' => ['catalog_bundles', null]] as $entity => [$table, $mapEntity]) {
            $expected = $source->table('oc_'.$entity)->count();
            $actual = $mapEntity ? DB::table($table)->whereIn('id', DB::table('libermedia_import_records')->where('entity', $mapEntity)->pluck('target_id'))->count() : DB::table($table)->whereNotNull('source_id')->count();
            $report['records'][$entity] = ['source' => $expected, 'imported' => $actual];
            if ($expected !== $actual) {
                $report['problems'][] = $entity.': count mismatch';
            }
        }
        $orderIds = DB::table('herrera_import_maps')->where('entity', 'order')->where('source', 'herrera-opencart')->pluck('target_id');
        $sourceTotals = $source->table('oc_order')->select('currency_code')->selectRaw('COUNT(*) AS orders, SUM(total) AS total')->groupBy('currency_code')->get()->keyBy('currency_code');
        $targetTotals = DB::table('orders')->whereIn('id', $orderIds)->select('currency_code')->selectRaw('COUNT(*) AS orders, SUM(grand_total) AS total')->groupBy('currency_code')->get()->keyBy('currency_code');
        foreach ($sourceTotals as $currency => $row) {
            $target = $targetTotals[$currency] ?? null;
            $difference = $target ? round((float) $target->total - (float) $row->total, 4) : null;
            $report['order_amounts'][$currency] = ['source_orders' => $row->orders, 'imported_orders' => $target?->orders, 'source_total' => round((float) $row->total, 4), 'imported_total' => $target ? round((float) $target->total, 4) : null, 'difference' => $difference];
            if ($difference !== 0.0 || $target?->orders !== $row->orders) {
                $report['problems'][] = 'Order amount mismatch: '.$currency;
            }
        }
        $report['source_content_gaps'] = [
            'blogs_without_description' => $source->table('oc_blog as b')->whereNotExists(fn ($q) => $q->selectRaw('1')->from('oc_blog_description as d')->whereColumn('d.blog_id', 'b.blog_id')->where('d.language_id', 3))->count(),
            'empty_blog_descriptions' => $source->table('oc_blog_description')->where('language_id', 3)->where('description', '')->count(),
        ];
        $report['bundles'] = ['product_links' => DB::table('catalog_bundle_products')->count(), 'discount_rules' => DB::table('catalog_bundle_discounts')->count()];
        $report['b2c_only'] = (bool) config('commerce.b2c_only');
        $report['passed'] = $report['problems'] === [];
        Storage::disk('local')->put('libermedia-migration/reconciliation.json', json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        $this->table(['Records', 'Source', 'Imported'], collect($report['records'])->map(fn ($r, $k) => [$k, $r['source'], $r['imported']])->all());
        $this->info($report['passed'] ? 'Counts and historical order totals match the snapshot.' : 'Reconciliation found differences; inspect the private report.');

        return $report['passed'] ? self::SUCCESS : self::FAILURE;
    }
}
