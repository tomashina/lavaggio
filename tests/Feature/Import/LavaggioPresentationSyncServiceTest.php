<?php

namespace Tests\Feature\Import;

use App\Models\Catalog\Attribute\Attribute;
use App\Models\Catalog\Product\Product;
use App\Models\Content\ContentBlock;
use App\Services\Import\LavaggioPresentationSyncService;
use App\Services\Settings\SystemSettingsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Tests\TestCase;

class LavaggioPresentationSyncServiceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['storefront.home' => 'lavaggio', 'app.locale' => 'hr', 'storefront_cache.store' => 'array',
            'media_profiles.models.'.Product::class.'.collections.product_main.conversions' => []]);
        Storage::fake('public');
    }

    public function test_the_plan_is_read_only_and_apply_resolves_target_ids_preserves_other_data_and_becomes_a_noop(): void
    {
        [$attribute, $images] = $this->catalogue();
        app(SystemSettingsService::class)->put('unapproved_setting', 'preserve');
        $other = ContentBlock::create(['code' => 'unmanaged-block', 'name' => 'Sačuvaj ovaj blok', 'type' => 'banner', 'is_active' => false]);
        $other->slots()->create(['placement' => 'page.top', 'frontend_variant' => 'all', 'sort_order' => 84, 'is_active' => false]);
        $campaign = ContentBlock::create(['code' => 'lavaggio-home-campaigns', 'name' => 'Stari naslov', 'type' => 'lavaggio_campaigns',
            'is_active' => false, 'payload' => ['unapproved_payload' => 'preserve']]);
        $campaign->translations()->create(['locale' => 'hr', 'title' => 'Stari tekst', 'payload' => ['unapproved_translation' => 'preserve']]);
        $campaign->slots()->create(['placement' => 'home.content', 'frontend_variant' => 'desktop', 'sort_order' => 99, 'is_active' => false]);
        $before = $this->state();
        $products = DB::table('products')->orderBy('id')->get()->toJson();
        $service = app(LavaggioPresentationSyncService::class);
        $plan = $service->plan();
        $this->assertSame(8, $plan['counts']['blocks']);
        $this->assertSame($before, $this->state());
        $service->apply($plan);
        $settings = app(SystemSettingsService::class);
        $this->assertSame('Pon-Pet: 08h - 16h', $settings->get('store_footer_hours'));
        $this->assertSame('097 799 44 11', $settings->get('store_footer_phone'));
        $this->assertCount(4, $settings->get('lavaggio_contact_cards'));
        $this->assertCount(3, $settings->get('lavaggio_faq_groups'));
        $this->assertSame('preserve', $settings->get('unapproved_setting'));
        $this->assertSame('preserve', $campaign->fresh()->payload['unapproved_payload']);
        $translation = $campaign->translations()->sole();
        $this->assertSame('preserve', $translation->payload['unapproved_translation']);
        $slides = $translation->payload['watchline']['slides'];
        $this->assertCount(3, $slides);
        $this->assertSame('/trgovina?attr_lavaggio_fabric='.$attribute->id, $slides[2]['url']);
        $this->assertStringContainsString('/storage/'.$images[0].'/', $slides[1]['image']);
        $this->assertStringContainsString('/storage/'.$images[1].'/', $slides[2]['image']);
        $this->assertSame([true, true, true], array_column($slides, 'shipping_notice'));
        $this->assertSame('all', $campaign->slots()->sole()->frontend_variant);
        $this->assertSame(10, $campaign->slots()->sole()->sort_order);
        $this->assertSame(84, $other->slots()->sole()->sort_order);
        $this->assertSame($products, DB::table('products')->orderBy('id')->get()->toJson());
        $this->assertSame([], $service->plan()['settings']);
        $this->assertSame([], $service->plan()['blocks']);
        $after = $this->state();
        $service->apply();
        $this->assertSame($after, $this->state());
    }

    public function test_a_missing_target_photograph_stops_before_any_setting_or_block_write(): void
    {
        $this->catalogue(false);
        app(SystemSettingsService::class)->put('store_footer_hours', 'Sačuvaj do potpune pripreme');
        $before = $this->state();
        try {
            app(LavaggioPresentationSyncService::class)->apply();
            $this->fail('Missing photography must prevent the whole presentation update.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('lavaggio-9000101566789', $exception->getMessage());
        }
        $this->assertSame($before, $this->state());
    }

    public function test_a_wrong_storefront_or_changed_manifest_plan_is_refused_without_writes(): void
    {
        $before = $this->state();
        config(['storefront.home' => 'watchline']);
        try {
            app(LavaggioPresentationSyncService::class)->plan();
            $this->fail('Another storefront must be rejected.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('Lavaggio storefront', $exception->getMessage());
        }
        config(['storefront.home' => 'lavaggio']);
        try {
            app(LavaggioPresentationSyncService::class)->apply(['manifest_sha256' => 'changed']);
            $this->fail('A stale manifest plan must be rejected.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('manifest changed', $exception->getMessage());
        }
        $this->assertSame($before, $this->state());
    }

    private function catalogue(bool $includeSensitiveImage = true): array
    {
        $attribute = Attribute::create(['code' => 'lavaggio-fabric-osjetljive-tkanine', 'group_code' => 'lavaggio-fabric', 'type' => 'multi', 'is_active' => true]);
        $images = [];
        foreach (['5999109563200', '9000101566789'] as $code) {
            $product = Product::create(['code' => 'lavaggio-'.$code, 'sku' => $code, 'is_active' => true, 'stock_qty' => 7, 'base_price' => 19]);
            if ($includeSensitiveImage || $code !== '9000101566789') {
                $images[] = $product->addMedia(UploadedFile::fake()->image($code.'.jpg', 80, 120))->toMediaCollection('product_main', 'public')->id;
            }
        }

        return [$attribute, $images];
    }

    private function state(): array
    {
        $state = [];
        foreach (['system_settings', 'content_blocks', 'content_block_translations', 'content_block_slots', 'content_block_items'] as $table) {
            $state[$table] = DB::table($table)->orderBy('id')->get()->toJson();
        }

        return $state;
    }
}
