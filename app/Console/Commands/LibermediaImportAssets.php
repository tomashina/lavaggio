<?php

namespace App\Console\Commands;

use App\Models\Catalog\Category\Category;
use App\Models\Catalog\Manufacturer\Manufacturer;
use App\Models\Catalog\Product\Product;
use App\Models\Content\Blog\BlogPost;
use App\Services\Settings\SystemSettingsService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class LibermediaImportAssets extends Command
{
    protected $signature = 'libermedia:import-assets {--images= : Local copied OpenCart image directory}';

    protected $description = 'Import mapped media, newsletter and local Libermedia branding';

    public function handle(SystemSettingsService $settings): int
    {
        if (! app()->environment('local') || config('app.name') !== 'Libermedia') {
            $this->error('Restricted to the local Libermedia project.');

            return self::FAILURE;
        }
        $root = realpath($this->option('images') ?: public_path('image'));
        if (! $root || ! is_dir($root)) {
            $this->error('Local images directory does not exist.');

            return self::FAILURE;
        }
        $db = DB::connection('libermedia_source');
        $report = ['media_imported' => 0, 'media_existing' => 0, 'missing_media' => [], 'failed_media' => [], 'newsletter_imported' => 0];
        // Keep originals for the design phase. Responsive sizes can be generated
        // after the new design establishes the required image dimensions.
        $profiles = config('media_profiles.models');
        foreach ($profiles as &$profile) {
            foreach ($profile['collections'] as &$collection) {
                $collection['conversions'] = [];
            }
            unset($collection);
        }
        unset($profile);
        config(['media_profiles.models' => $profiles, 'media-library.max_file_size' => 100 * 1024 * 1024]);
        foreach (['product' => [Product::class, 'product_main'], 'category' => [Category::class, 'category_icon'], 'manufacturer' => [Manufacturer::class, 'manufacturer_logo'], 'blog' => [BlogPost::class, 'blog_cover']] as $entity => [$class, $collection]) {
            $maps = DB::table('herrera_import_maps')->where('entity', $entity)->pluck('target_id', 'source_id');
            $rows = $db->table('oc_'.$entity)->orderBy($entity.'_id')->get();
            foreach ($rows as $row) {
                $id = $row->{$entity.'_id'};
                $model = isset($maps[$id]) ? $class::find($maps[$id]) : null;
                if (! $model) {
                    continue;
                }
                $images = [[$collection, (string) ($row->image ?? '')]];
                if ($entity === 'product') {
                    foreach ($db->table('oc_product_image')->where('product_id', $id)->orderBy('sort_order')->get() as $image) {
                        $images[] = ['product_gallery', $image->image];
                    }
                }
                foreach ($images as [$target, $path]) {
                    if (trim($path) === '') {
                        continue;
                    }
                    $file = realpath($root.'/'.ltrim($path, '/'));
                    if (! $file || ! str_starts_with($file, $root.DIRECTORY_SEPARATOR) || ! is_file($file)) {
                        $report['missing_media'][] = compact('entity', 'id', 'path');

                        continue;
                    }
                    if ($model->media()->where('collection_name', $target)->where('custom_properties->opencart_path', $path)->exists()) {
                        $report['media_existing']++;

                        continue;
                    }
                    try {
                        $model->addMedia($file)->preservingOriginal()->withCustomProperties(['opencart_path' => $path])->toMediaCollection($target, 'public');
                        $report['media_imported']++;
                    } catch (\Throwable $e) {
                        $report['failed_media'][] = ['entity' => $entity, 'id' => $id, 'path' => $path, 'error' => get_class($e)];
                    }
                }
            }
            $this->info($entity.' media complete');
        }
        foreach ($db->table('oc_newsletter')->orderBy('id')->get() as $row) {
            $email = strtolower(trim($row->email));
            if (! filter_var($email, FILTER_VALIDATE_EMAIL)) {
                continue;
            }
            $report['newsletter_imported'] += DB::table('newsletter_signups')->insertOrIgnore([
                'email' => $email, 'source' => 'opencart_import', 'locale' => 'hr',
                'provider' => 'none', 'sync_status' => 'skipped', 'consent_accepted' => false,
                'payload' => json_encode(['opencart_newsletter_id' => $row->id, 'consent_history' => 'not_recorded_in_source']),
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }
        $source = $db->table('oc_setting')->where('store_id', 0)->pluck('value', 'key');
        $settings->putMany([
            'store_brand_name' => 'Liber Media', 'store_brand_logo_path' => 'assets/brand/libermedia-logo.svg',
            'store_brand_logo_dark_path' => 'assets/brand/libermedia-logo-dark.svg',
            'store_brand_logo_optimized_path' => '', 'store_footer_contact_title' => 'Liber Media',
            'store_footer_email_sales' => $source->get('config_email', ''), 'store_footer_email_support' => $source->get('config_email', ''),
            'store_footer_phone' => $source->get('config_telephone', ''), 'store_footer_address' => $source->get('config_address', ''),
            'store_footer_bottom_copyright_text' => 'Liber Media · Sva prava pridržana.',
            'store_seo_default_title' => $source->get('config_meta_title', 'Liber Media'),
            'store_seo_default_description' => $source->get('config_meta_description', ''), 'store_seo_robots' => 'noindex,nofollow',
            'store_schema_org_name' => 'Liber Media', 'store_email_enabled' => false, 'store_analytics_enabled' => false,
            'msan_enabled' => false, 'msan_price_stock_sync_enabled' => false,
            'store_newsletter_provider' => 'database', 'store_newsletter_coupon_enabled' => false,
            'catalog_use_blog' => true, 'catalog_use_attributes' => true, 'catalog_use_options' => true,
            'catalog_use_manufacturers' => true, 'catalog_hide_out_of_stock_products' => false,
            'store_footer_col_1_title' => 'Proizvodi',
            'store_footer_col_1_category_ids' => Category::where('scope', 'catalog')->whereNull('parent_id')->pluck('id')->all(),
            'store_footer_col_2_title' => 'Informacije',
            'store_footer_col_2_page_ids' => DB::table('content_info_pages')->where('is_active', true)->pluck('id')->all(),
            'store_footer_bottom_link_page_ids' => DB::table('content_info_pages')->where('is_active', true)->pluck('id')->all(),
            'front_navigation_main' => [
                ['type' => 'catalog', 'label' => 'Proizvodi', 'url' => '/shop', 'is_active' => true, 'show_dropdown' => true, 'sort_order' => 0],
                ['type' => 'custom', 'label' => 'Blog', 'url' => '/blog', 'is_active' => true, 'sort_order' => 1],
                ['type' => 'custom', 'label' => 'Kontakt', 'url' => '/contact', 'is_active' => true, 'sort_order' => 2],
            ],
        ]);
        Storage::disk('local')->put('libermedia-assets-report.json', json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        $this->table(['Check', 'Count'], collect($report)->map(fn ($v, $k) => [$k, is_array($v) ? count($v) : $v])->all());

        return count($report['failed_media']) ? self::FAILURE : self::SUCCESS;
    }
}
