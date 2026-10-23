<?php

namespace Tests\Feature\Integrations;

use App\Livewire\Admin\Integrations\Watchline\Dashboard;
use App\Models\Catalog\Product\Product;
use App\Models\Integrations\WatchlineIntegrationRun;
use App\Models\User;
use App\Services\Integrations\Watchline\GathSyncService;
use App\Services\Integrations\Watchline\WatchlineIntegrationService;
use App\Services\Integrations\Watchline\WatchlineIntegrationSettings;
use App\Services\Integrations\Watchline\WatchlineMessageAutomationService;
use App\Services\Integrations\Wolt\WoltSyncService;
use App\Services\Settings\SystemSettingsService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Livewire\Livewire;
use RuntimeException;
use Silber\Bouncer\BouncerFacade as Bouncer;
use Tests\TestCase;

class WatchlineIntegrationOrchestrationTest extends TestCase
{
    use RefreshDatabase;

    private array $files = [];

    protected function setUp(): void
    {
        parent::setUp();
        config(['storefront.home' => 'watchline', 'commerce.local_safe_mode' => true]);
        Cache::flush();
        Http::preventStrayRequests();
        Mail::fake();
    }

    protected function tearDown(): void
    {
        foreach ($this->files as $path) {
            if (is_file($path)) {
                unlink($path);
            }
        }
        parent::tearDown();
    }

    public function test_all_five_schedules_and_master_start_disabled(): void
    {
        $settings = app(WatchlineIntegrationSettings::class);
        $options = $settings->options();

        $this->assertFalse($options['live_enabled']);
        $this->assertFalse($options['gath_reset_missing']);
        $this->assertCount(5, $options['schedules']);
        $this->assertSame(array_keys(WatchlineIntegrationSettings::JOBS), array_keys($options['schedules']));
        foreach ($options['schedules'] as $schedule) {
            $this->assertFalse($schedule['enabled']);
        }
        $this->assertFalse($settings->liveReady());
        $this->assertSame([], app(WatchlineIntegrationService::class)->runDue());
        $this->assertDatabaseCount('watchline_integration_runs', 0);
    }

    public function test_forced_external_runs_and_cron_are_blocked_by_local_environment_safe_mode_or_master_switch(): void
    {
        $this->mock(WoltSyncService::class)->shouldNotReceive('run');
        $this->mock(WatchlineMessageAutomationService::class)->shouldNotReceive('run');
        $this->mock(GathSyncService::class)->shouldNotReceive('apply');
        $settings = app(WatchlineIntegrationSettings::class);
        $options = $settings->options();
        $options['live_enabled'] = true;
        foreach ($options['schedules'] as &$schedule) {
            $schedule['enabled'] = true;
        }
        unset($schedule);
        $settings->save($options);
        $integrations = app(WatchlineIntegrationService::class);

        $this->app['env'] = 'local';
        config(['commerce.local_safe_mode' => false]);
        foreach (['wolt_catalog', 'wolt_inventory', 'reviews', 'abandoned'] as $job) {
            $this->assertSame('blocked', $integrations->run($job, false)['status']);
        }
        $this->assertSame('blocked', $integrations->run('gath', false, 'cron')['status']);
        $this->assertSame([], $integrations->runDue());

        $this->app['env'] = 'production';
        config(['commerce.local_safe_mode' => true]);
        $this->assertSame('blocked', $integrations->run('wolt_catalog', false)['status']);
        $this->assertSame('blocked', $integrations->run('gath', false, 'cron')['status']);
        $this->assertSame([], $integrations->runDue());

        config(['commerce.local_safe_mode' => false]);
        $options['live_enabled'] = false;
        $settings->save($options);
        $this->assertSame('blocked', $integrations->run('reviews', false)['status']);
        $this->assertSame([], $integrations->runDue());
        $this->assertDatabaseMissing('watchline_integration_runs', ['status' => 'completed']);
        Http::assertNothingSent();
        Mail::assertNothingSent();
    }

    public function test_production_scheduler_uses_zagreb_time_and_claims_each_due_job_once_per_minute(): void
    {
        $this->app['env'] = 'production';
        config(['commerce.local_safe_mode' => false]);
        $settings = app(WatchlineIntegrationSettings::class);
        $options = $settings->options();
        $options['live_enabled'] = true;
        $options['schedules']['reviews'] = ['enabled' => true, 'expression' => '22 11 * * *'];
        $settings->save($options);
        $this->mock(WatchlineMessageAutomationService::class)->shouldReceive('run')->once()
            ->with('reviews', false)->andReturn(['status' => 'completed', 'eligible' => 2, 'sent' => 2, 'skipped' => 0]);
        $this->mock(GathSyncService::class)->shouldNotReceive('apply');
        $this->mock(WoltSyncService::class)->shouldNotReceive('run');
        $integrations = app(WatchlineIntegrationService::class);
        $dueUtc = CarbonImmutable::parse('2026-10-23 09:22:00', 'UTC');

        $this->assertSame([], $integrations->runDue($dueUtc->subMinute()));
        $first = $integrations->runDue($dueUtc);
        $this->assertSame(['reviews'], array_keys($first));
        $this->assertSame('completed', $first['reviews']['status']);
        $this->assertSame([], $integrations->runDue($dueUtc->addSeconds(35)));
        $this->assertSame([], $integrations->runDue($dueUtc->addMinute()));
        $this->assertDatabaseCount('watchline_integration_runs', 1);
        $this->assertDatabaseHas('watchline_integration_runs', ['job' => 'reviews', 'trigger' => 'cron', 'dry_run' => false, 'status' => 'completed']);
        Http::assertNothingSent();
        Mail::assertNothingSent();
    }

    public function test_cli_defaults_to_preview_and_due_requires_explicit_apply(): void
    {
        $this->mock(GathSyncService::class)->shouldReceive('preview')->once()->andReturn([
            'can_apply' => true, 'fetched_count' => 1, 'matched_count' => 1, 'updated_count' => 1,
            'checksum' => str_repeat('a', 64),
        ])->getMock()->shouldNotReceive('apply');

        $this->artisan('watchline:integrations-run', ['job' => 'gath'])->assertSuccessful();

        $run = WatchlineIntegrationRun::query()->sole();
        $this->assertTrue($run->dry_run);
        $this->assertSame('cli', $run->trigger);
        $this->assertSame('preview', $run->status);
        $this->artisan('watchline:integrations-run', ['--due' => true])->assertFailed();
        $this->assertDatabaseCount('watchline_integration_runs', 1);
    }

    public function test_hub_and_mutating_actions_recheck_permissions(): void
    {
        $this->actingAs($this->admin(false))->get(route('admin.integrations.watchline.index'))->assertForbidden();
        Livewire::test(Dashboard::class)->assertForbidden();

        $this->actingAs($this->admin());
        $component = Livewire::test(Dashboard::class);
        $this->actingAs($this->admin(false));
        $component->call('previewJob', 'gath')->assertForbidden();
        $this->assertDatabaseCount('watchline_integration_runs', 0);
    }

    public function test_dashboard_requires_a_fresh_gath_preview_and_applies_the_reviewed_checksum_only(): void
    {
        $admin = $this->admin();
        $this->actingAs($admin);
        $product = Product::query()->create(['code' => 'reviewed', 'sku' => 'REVIEWED', 'base_price' => 50, 'stock_qty' => 0]);
        $path = $this->csv("REVIEWED;2;10,00\n");
        $settings = app(WatchlineIntegrationSettings::class);
        $options = $settings->options();
        $options['gath_csv_path'] = $path;
        $settings->save($options);
        $component = Livewire::test(Dashboard::class);

        $component->call('applyJob', 'gath')->assertHasErrors('gath');
        $this->assertSame(0, $product->fresh()->stock_qty);
        $component->call('previewJob', 'gath')->assertHasNoErrors('gath')
            ->assertSet('gathPreviewChecksum', hash_file('sha256', $path))->assertDispatched('watchline-integration-report');
        file_put_contents($path, "REVIEWED;3;20,00\n");
        $component->call('applyJob', 'gath')->assertHasErrors('gath')->assertSet('gathPreviewChecksum', null);
        $this->assertSame(0, $product->fresh()->stock_qty);
        $this->assertDatabaseCount('stock_sync_runs', 0);

        $component->call('previewJob', 'gath')->assertHasNoErrors('gath')
            ->call('applyJob', 'gath')->assertHasNoErrors('gath')
            ->assertSet('report.status', 'completed')->assertSet('gathPreviewChecksum', null);

        $this->assertSame(3, $product->fresh()->stock_qty);
        $this->assertSame(20.0, (float) $product->fresh()->getRawOriginal('base_price'));
        $this->assertDatabaseCount('stock_sync_runs', 1);
        $this->assertDatabaseHas('watchline_integration_runs', ['job' => 'gath', 'actor_id' => $admin->id, 'dry_run' => false, 'status' => 'completed']);
        Http::assertNothingSent();
        Mail::assertNothingSent();
    }

    public function test_changed_csv_is_rejected_against_the_same_feed_bytes_read_by_the_backend(): void
    {
        $product = Product::query()->create(['code' => 'race', 'sku' => 'RACE', 'base_price' => 50, 'stock_qty' => 0]);
        $path = $this->csv("RACE;2;10,00\n");
        $reviewedChecksum = hash_file('sha256', $path);
        $settings = app(WatchlineIntegrationSettings::class);
        $options = $settings->options();
        $options['gath_csv_path'] = $path;
        $settings->save($options);
        file_put_contents($path, "RACE;8;99,00\n");

        $report = app(WatchlineIntegrationService::class)->run('gath', false, 'manual', null, $reviewedChecksum);

        $this->assertSame('failed', $report['status']);
        $this->assertSame(0, $product->fresh()->stock_qty);
        $this->assertSame(50.0, (float) $product->fresh()->getRawOriginal('base_price'));
        $this->assertDatabaseCount('stock_sync_runs', 0);
    }

    public function test_saved_path_or_reset_missing_changes_invalidate_the_dashboard_preview_even_for_identical_csv_bytes(): void
    {
        $this->actingAs($this->admin());
        Product::query()->create(['code' => 'config-found', 'sku' => 'FOUND', 'base_price' => 10, 'stock_qty' => 1]);
        $missing = Product::query()->create(['code' => 'config-missing', 'stock_qty' => 6, 'payload' => ['opencart' => ['product_id' => 12, 'sku' => 'MISSING']]]);
        $settings = app(WatchlineIntegrationSettings::class);
        $options = $settings->options();
        $options['gath_csv_path'] = $this->csv("FOUND;1;10,00\n");
        $settings->save($options);
        $component = Livewire::test(Dashboard::class)->call('previewJob', 'gath');
        $options['gath_reset_missing'] = true;
        $settings->save($options);

        $component->call('applyJob', 'gath')->assertHasErrors('gath')->assertSet('gathPreviewChecksum', null);
        $this->assertSame(6, $missing->fresh()->stock_qty);
        $this->assertDatabaseCount('stock_sync_runs', 0);

        $component->call('previewJob', 'gath')->assertHasNoErrors('gath');
        $options['gath_csv_path'] = $this->csv("FOUND;1;10,00\n");
        $settings->save($options);
        $component->call('applyJob', 'gath')->assertHasErrors('gath');
        $this->assertSame(6, $missing->fresh()->stock_qty);
        $this->assertDatabaseCount('stock_sync_runs', 0);
    }

    public function test_invalid_global_settings_roll_back_message_changes_and_disabled_reviews_allow_empty_statuses(): void
    {
        $this->actingAs($this->admin());
        $component = Livewire::test(Dashboard::class)
            ->set('messages.reviews.enabled', false)->set('messages.reviews.status_ids', [])
            ->set('messages.reviews.delay_days', 9)
            ->set('form.schedules.gath.expression', 'invalid cron')
            ->call('save')->assertHasErrors('form.schedules.gath.expression');
        $system = app(SystemSettingsService::class);
        $system->clearRuntimeCache();
        $this->assertNull($system->get('watchline_messages_reviews'));
        $this->assertNull($system->get('watchline_messages_abandoned'));
        $this->assertNull($system->get('watchline_integrations'));

        $component->set('form.schedules.gath.expression', '7,37 * * * *')->call('save')->assertHasNoErrors();
        $system->clearRuntimeCache();
        $this->assertSame(9, $system->get('watchline_messages_reviews')['delay_days']);
        $this->assertFalse($system->get('watchline_messages_reviews')['enabled']);
        $this->assertSame([], $system->get('watchline_messages_reviews')['status_ids']);
        $component->set('messages.reviews.enabled', true)->call('save')->assertHasErrors('messages.reviews.status_ids');
        $system->clearRuntimeCache();
        $this->assertFalse($system->get('watchline_messages_reviews')['enabled']);
        Mail::assertNothingSent();
    }

    public function test_run_logs_drop_provider_payloads_credentials_and_transport_exceptions(): void
    {
        $secret = 'fixture-provider-secret-never-public';
        $this->mock(WoltSyncService::class)->shouldReceive('run')->once()->with('wolt_catalog', true)->andReturn([
            'status' => 'preview', 'blocked' => false, 'reasons' => [],
            'counts' => ['selected' => 1, 'valid' => 1, 'changed' => 1, 'password' => $secret],
            'metadata' => ['operation' => 'catalog', 'environment' => 'test', 'authorization' => $secret],
            'access_token' => $secret, 'payload' => ['private' => $secret], 'preview' => ['data' => [['private' => $secret]]],
            'examples' => array_fill(0, 30, ['sku' => 'PUBLIC-SKU', 'old_price' => '10.0000', 'new_price' => '12.0000', 'password' => $secret]),
        ])->getMock()->shouldReceive('run')->once()->with('wolt_inventory', true)
            ->andThrow(new RuntimeException('https://provider.example/?token='.$secret));
        $integrations = app(WatchlineIntegrationService::class);

        $preview = $integrations->run('wolt_catalog');
        $failure = $integrations->run('wolt_inventory');

        $this->assertSame('preview', $preview['status']);
        $this->assertSame(['selected' => 1, 'valid' => 1, 'changed' => 1], $preview['counts']);
        $this->assertSame(['operation' => 'catalog', 'environment' => 'test'], $preview['metadata']);
        $this->assertCount(25, $preview['examples']);
        $this->assertSame(['sku' => 'PUBLIC-SKU', 'old_price' => '10.0000', 'new_price' => '12.0000'], $preview['examples'][0]);
        $this->assertSame('failed', $failure['status']);
        $this->assertStringNotContainsString($secret, json_encode([$preview, $failure, WatchlineIntegrationRun::all()->toArray()], JSON_THROW_ON_ERROR));
        $this->assertDatabaseCount('watchline_integration_runs', 2);
        Http::assertNothingSent();
        Mail::assertNothingSent();
    }

    private function admin(bool $manage = true): User
    {
        $user = User::factory()->create();
        Bouncer::role()->firstOrCreate(['name' => 'watchline-integration-fixture-admin']);
        Bouncer::assign('watchline-integration-fixture-admin')->to($user);
        Bouncer::allow($user)->to('admin.access');
        if ($manage) {
            Bouncer::allow($user)->to('integrations.stock.manage');
        }

        return $user;
    }

    private function csv(string $bytes): string
    {
        $path = tempnam(sys_get_temp_dir(), 'watchline-orchestration-');
        file_put_contents($path, $bytes);
        $this->files[] = $path;

        return $path;
    }
}
