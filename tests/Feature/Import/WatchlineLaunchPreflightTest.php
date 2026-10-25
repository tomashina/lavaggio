<?php

namespace Tests\Feature\Import;

use App\Models\Catalog\Product\Product;
use App\Models\Settings\Local\PaymentMethod;
use App\Services\Import\WatchlineLaunchPreflightService;
use App\Services\Settings\SystemSettingsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

class WatchlineLaunchPreflightTest extends TestCase
{
    use RefreshDatabase;

    private string $mediaRoot;

    protected function setUp(): void
    {
        parent::setUp();
        $this->mediaRoot = storage_path('framework/testing/watchline-preflight-'.Str::random(12));
        File::ensureDirectoryExists($this->mediaRoot.'/catalog');
        file_put_contents($this->mediaRoot.'/catalog/original.png', base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+aP1sAAAAASUVORK5CYII='));
        config(['storefront.home' => 'watchline', 'app.locale' => 'hr', 'app.url' => 'http://watchline-new.test',
            'commerce.local_safe_mode' => true, 'gift_vouchers.emails_enabled' => false, 'stock_notifications.emails_enabled' => false,
            'legacy_media.local_root' => $this->mediaRoot, 'legacy_media.enabled' => true,
            'legacy_media.origin' => 'https://www.watchline.hr', 'legacy_media.remote_only' => false]);
        app(SystemSettingsService::class)->putMany(['store_email_enabled' => false, 'store_seo_robots' => 'index,follow',
            'provider_secret' => 'SYNTHETIC-PREFLIGHT-SECRET']);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->mediaRoot);
        parent::tearDown();
    }

    public function test_local_preflight_is_read_only_and_reports_missing_deduplicated_safe_original_paths_without_secrets(): void
    {
        $this->product(['image' => 'catalog/original.png', 'gallery_images' => [
            ['image' => 'catalog/original.png'], ['image' => 'catalog/missing.png'],
            ['image' => 'catalog/Original.png'], ['image' => '../SYNTHETIC-PREFLIGHT-SECRET.png'],
        ]]);
        $before = DB::table('system_settings')->orderBy('id')->get()->toJson();
        $writes = [];
        DB::listen(function ($query) use (&$writes): void {
            if (preg_match('/^\s*(insert|update|delete|replace|create|alter|drop)\b/i', $query->sql)) {
                $writes[] = $query->sql;
            }
        });
        $report = app(WatchlineLaunchPreflightService::class)->inspect(includeMissingPaths: true);

        $this->assertTrue($report['ready']);
        $this->assertTrue($report['indexing_blocked']);
        $this->assertSame(3, $report['media']['unique_original_paths']);
        $this->assertSame(1, $report['media']['local_originals']);
        $this->assertSame(['catalog/Original.png', 'catalog/missing.png'], $report['media']['missing_paths']);
        $this->assertSame(1, $report['media']['invalid_references']);
        $this->assertFalse($report['media']['remote_availability_probed']);
        $this->assertStringNotContainsString('SYNTHETIC-PREFLIGHT-SECRET', json_encode($report));
        $this->assertSame([], $writes);
        $this->assertSame($before, DB::table('system_settings')->orderBy('id')->get()->toJson());
    }

    public function test_live_profile_blocks_preview_flags_and_missing_media_without_enabling_anything(): void
    {
        $this->product(['image' => 'catalog/missing.png']);
        $report = app(WatchlineLaunchPreflightService::class)->inspect('live');
        $blocked = collect($report['checks'])->where('status', 'blocked')->pluck('code')->all();
        foreach (['environment', 'safe_mode', 'https_origin', 'indexing', 'media_originals'] as $code) {
            $this->assertContains($code, $blocked);
        }
        $this->assertFalse($report['ready']);
        $this->assertTrue(config('commerce.local_safe_mode'));
        $this->assertFalse(app(SystemSettingsService::class)->get('store_email_enabled'));
        $this->assertFalse(config('gift_vouchers.emails_enabled'));
        $this->assertArrayNotHasKey('missing_paths', $report['media']);
    }

    public function test_live_checks_use_exact_local_files_and_production_settings_without_disclosing_values(): void
    {
        $this->product(['image' => 'catalog/original.png']);
        $this->app['env'] = 'production';
        config(['app.url' => 'https://www.watchline.hr', 'app.debug' => false, 'commerce.local_safe_mode' => false]);
        $report = app(WatchlineLaunchPreflightService::class)->inspect('live');
        $this->assertTrue($report['ready']);
        $this->assertFalse($report['indexing_blocked']);
        $this->assertSame(0, $report['media']['missing_originals']);
        $this->assertContains('email_disabled', collect($report['checks'])->where('status', 'warning')->pluck('code')->all());
    }

    public function test_command_defaults_to_local_and_writes_only_an_explicit_private_media_report(): void
    {
        Storage::fake('local');
        $this->product(['image' => 'catalog/missing.png']);
        $this->artisan('watchline:preflight', ['--json' => true])->assertSuccessful();
        $this->assertSame([], Storage::disk('local')->allFiles());
        $this->artisan('watchline:preflight', ['--json' => true, '--media-report' => true])->assertSuccessful();
        $files = Storage::disk('local')->allFiles('watchline/preflight');
        $this->assertCount(1, $files);
        $path = Storage::disk('local')->path($files[0]);
        $this->assertSame(0600, fileperms($path) & 0777);
        $this->assertSame(['catalog/missing.png'], json_decode(file_get_contents($path), true)['media']['missing_paths']);
        $this->artisan('watchline:preflight', ['--profile' => 'live', '--json' => true])->assertFailed();
    }

    public function test_active_bank_barcode_requires_receiver_identity_only_on_live_without_disclosing_or_changing_settings(): void
    {
        Http::preventStrayRequests();
        $this->product(['image' => 'catalog/original.png']);
        $method = PaymentMethod::create(['code' => 'bank_transfer', 'name' => 'Bank transfer', 'provider' => 'bank', 'is_active' => false,
            'settings' => ['upi_receiver_name' => 'Synthetic receiver', 'upi_receiver_street' => '   ']]);
        $service = app(WatchlineLaunchPreflightService::class);
        $inactive = $service->inspect();
        $this->assertFalse($inactive['bank_barcode']['active']);
        $this->assertNotContains('bank_barcode_receiver', array_column($inactive['checks'], 'code'));

        $method->update(['is_active' => true]);
        $stored = $method->fresh()->settings;
        $local = $service->inspect();
        $this->assertTrue($local['ready']);
        $this->assertFalse($local['bank_barcode']['complete']);
        $this->assertSame(['upi_receiver_name' => true, 'upi_receiver_street' => false, 'upi_receiver_place' => false, 'upi_receiver_iban' => false], $local['bank_barcode']['required_fields']);
        $this->assertSame('warning', collect($local['checks'])->firstWhere('code', 'bank_barcode_receiver')['status']);
        $this->assertSame($stored, $method->fresh()->settings);

        $this->app['env'] = 'production';
        config(['app.url' => 'https://www.watchline.hr', 'app.debug' => false, 'commerce.local_safe_mode' => false]);
        $live = $service->inspect('live');
        $this->assertFalse($live['ready']);
        $this->assertSame('blocked', collect($live['checks'])->firstWhere('code', 'bank_barcode_receiver')['status']);
        $this->assertSame($stored, $method->fresh()->settings);

        $method->update(['settings' => ['upi_receiver_name' => 'Synthetic receiver', 'upi_receiver_street' => 'Synthetic street',
            'upi_receiver_place' => 'Synthetic place', 'upi_receiver_iban' => 'SYNTHETIC-IBAN-MARKER']]);
        $complete = $service->inspect('live');
        $this->assertTrue($complete['ready']);
        $this->assertTrue($complete['bank_barcode']['complete']);
        $this->assertNotContains(false, $complete['bank_barcode']['required_fields']);
        $this->assertSame('ok', collect($complete['checks'])->firstWhere('code', 'bank_barcode_receiver')['status']);
        foreach (array_values($method->settings) as $value) {
            $this->assertStringNotContainsString($value, json_encode($complete));
        }
        Http::assertNothingSent();
    }

    private function product(array $opencart): Product
    {
        return Product::create(['code' => 'preflight-watch', 'sku' => 'PREFLIGHT-WATCH', 'is_active' => true,
            'payload' => ['opencart' => $opencart]]);
    }
}
