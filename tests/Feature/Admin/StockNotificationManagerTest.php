<?php

namespace Tests\Feature\Admin;

use App\Livewire\Admin\Catalog\StockNotification\Manager;
use App\Models\Catalog\Bundle\Bundle;
use App\Models\Catalog\Product\Product;
use App\Models\Catalog\StockNotification;
use App\Models\User;
use App\Models\User\CustomerGroup;
use App\Services\Catalog\StockNotificationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use Silber\Bouncer\BouncerFacade as Bouncer;
use Tests\TestCase;

class StockNotificationManagerTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['stock_notifications.emails_enabled' => true]);
        Mail::fake();
    }

    public function test_admin_route_requires_login_and_catalog_access(): void
    {
        $this->get(route('admin.stock-notifications'))->assertRedirect(route('login'));
        $this->actingAs(User::factory()->create())->get(route('admin.stock-notifications'))->assertForbidden();
        $this->actingAs($this->viewer())->get(route('admin.stock-notifications'))
            ->assertOk()->assertSeeLivewire(Manager::class)->assertSee('Obavijesti o dostupnosti');
        Mail::assertNothingSent();
    }

    public function test_viewer_can_read_requests_and_statistics_but_cannot_see_send_actions(): void
    {
        $viewer = $this->viewer();
        $ready = $this->notification($this->product('ready', 3));
        $waiting = $this->notification($this->product('waiting', 0));
        $this->notification($this->product('sent', 3), ['status' => 'sent', 'sent_at' => now()]);
        $this->notification($this->product('failed', 0), ['status' => 'failed', 'attempts' => 1]);
        $this->notification($this->product('cancelled', 0), ['status' => 'cancelled']);

        Livewire::actingAs($viewer)->test(Manager::class)
            ->assertSee('Obavijesti o dostupnosti')
            ->assertSee($ready->email)->assertSee($waiting->email)
            ->assertDontSee('Pošalji odabrano')
            ->assertViewHas('stats', fn (array $stats) => $stats === [
                'total' => 5, 'waiting' => 1, 'ready' => 1, 'sent' => 1, 'failed' => 1, 'cancelled' => 1,
            ]);
        Mail::assertNothingSent();
    }

    #[DataProvider('mutations')]
    public function test_every_mutation_rechecks_update_permission(string $method): void
    {
        $notification = $this->notification($this->product('protected', 3));

        $component = Livewire::actingAs($this->viewer())->test(Manager::class)
            ->set('selected', [$notification->id]);

        in_array($method, ['send', 'cancel', 'markInterrupted'], true)
            ? $component->call($method, $notification->id)->assertForbidden()
            : $component->call($method)->assertForbidden();

        $this->assertSame('waiting', $notification->fresh()->status);
        Mail::assertNothingSent();
    }

    public static function mutations(): array
    {
        return [['send'], ['sendSelected'], ['cancel'], ['selectReady'], ['markInterrupted']];
    }

    public function test_users_without_catalog_permission_cannot_load_the_component(): void
    {
        Livewire::actingAs(User::factory()->create())->test(Manager::class)->assertForbidden();
    }

    public function test_permission_revoked_after_mount_blocks_further_changes(): void
    {
        $notification = $this->notification($this->product('revoked', 3));
        $user = $this->viewer();
        Bouncer::allow($user)->to('catalog.products.update');
        Bouncer::refreshFor($user);
        $component = Livewire::actingAs($user)->test(Manager::class)->assertViewHas('canUpdate', true);

        Bouncer::disallow($user)->to('catalog.products.update');
        Bouncer::refreshFor($user);
        $component->call('cancel', $notification->id)->assertForbidden();

        $this->assertSame('waiting', $notification->fresh()->status);
        Mail::assertNothingSent();
    }

    public function test_filters_follow_current_stock_and_search_product_and_bundle_names(): void
    {
        $ready = $this->notification($this->product('tockice', 5));
        $this->notification($this->product('kockice', 0));
        $bundle = Bundle::query()->create(['title' => 'Poklon istraživač', 'is_active' => true]);
        StockNotification::query()->create([
            'target_key' => 'bundle:'.$bundle->id, 'bundle_id' => $bundle->id,
            'email' => 'bundle@example.test', 'locale' => 'hr', 'status' => 'cancelled',
            'token' => Str::random(64), 'consented_at' => now(),
        ]);

        Livewire::actingAs($this->admin())->test(Manager::class)
            ->set('status', 'ready')->assertViewHas('rows', fn ($rows) => $rows->pluck('id')->all() === [$ready->id])
            ->set('status', 'waiting')->assertDontSee($ready->email)
            ->set('status', '')->set('search', 'POKLON')->assertSee('bundle@example.test')->assertDontSee($ready->email)
            ->set('search', 'SKU-TOCKICE')->assertSee($ready->email)->assertDontSee('bundle@example.test')
            ->set('selected', [$ready->id])->set('search', 'no-result')->assertSet('selected', [])
            ->assertSee('Nema prijava za odabrane filtre.');
    }

    public function test_bulk_send_sends_ready_once_and_skips_unavailable_sent_and_cancelled(): void
    {
        $ready = $this->notification($this->product('ready', 2));
        $waiting = $this->notification($this->product('empty', 0));
        $sent = $this->notification($this->product('already-sent', 2), ['status' => 'sent', 'sent_at' => now()]);
        $cancelled = $this->notification($this->product('cancelled', 2), ['status' => 'cancelled']);

        Livewire::actingAs($this->admin())->test(Manager::class)
            ->set('selected', [$ready->id, $waiting->id, $sent->id, $cancelled->id])
            ->call('sendSelected')->assertHasNoErrors()->assertSet('selected', [])
            ->assertDispatched('notify', fn ($event, $params) => str_contains($params['message'], 'Poslano: 1. Preskočeno: 3.'))
            ->call('send', $ready->id);

        $this->assertSame('sent', $ready->fresh()->status);
        $this->assertNotNull($ready->fresh()->sent_at);
        $this->assertSame('waiting', $waiting->fresh()->status);
        $this->assertSame('cancelled', $cancelled->fresh()->status);
        Mail::assertSentCount(1);
    }

    public function test_failed_request_can_be_retried_when_available(): void
    {
        $notification = $this->notification($this->product('retry', 2), [
            'status' => 'failed', 'attempts' => 1, 'last_error' => 'TransportException: Slanje nije uspjelo.',
        ]);

        Livewire::actingAs($this->admin())->test(Manager::class)
            ->set('status', 'failed')->assertSee('Pokušaj ponovno')
            ->call('send', $notification->id)->assertHasNoErrors();

        $this->assertSame('sent', $notification->fresh()->status);
        Mail::assertSentCount(1);
    }

    public function test_unconfirmed_delivery_is_reported_as_failed_in_the_bulk_summary(): void
    {
        $notification = $this->notification($this->product('unconfirmed', 5));
        $service = \Mockery::mock(StockNotificationService::class)->makePartial();
        $service->shouldReceive('canSend')->andReturn(true);
        $service->shouldReceive('sendNow')->once()->with($notification->id)->andReturn([
            'sent' => false, 'reason' => 'unconfirmed', 'message' => 'Potrebna je ručna provjera slanja.',
        ]);
        $this->app->instance(StockNotificationService::class, $service);

        Livewire::actingAs($this->admin())->test(Manager::class)
            ->set('selected', [$notification->id])->call('sendSelected')
            ->assertDispatched('notify', type: 'warning', message: 'Poslano: 0. Preskočeno: 0. Neuspjelo: 1.');
        Mail::assertNothingSent();
    }

    public function test_cancel_preserves_the_request_and_prevents_delivery(): void
    {
        $notification = $this->notification($this->product('cancel', 2));

        Livewire::actingAs($this->admin())->test(Manager::class)
            ->call('cancel', $notification->id)->call('send', $notification->id);

        $this->assertDatabaseHas('stock_notifications', ['id' => $notification->id, 'status' => 'cancelled']);
        $this->assertNull($notification->fresh()->sent_at);
        Mail::assertNothingSent();
    }

    public function test_select_ready_only_selects_deliverable_requests_and_sending_is_locally_gated(): void
    {
        $ready = $this->notification($this->product('ready', 2));
        $this->notification($this->product('empty', 0));
        $this->notification($this->product('cancelled', 2), ['status' => 'cancelled']);

        $component = Livewire::actingAs($this->admin())->test(Manager::class)
            ->call('selectReady')->assertSet('selected', [$ready->id]);

        config(['stock_notifications.emails_enabled' => false]);
        $component->call('sendSelected');

        $this->assertSame('waiting', $ready->fresh()->status);
        Mail::assertNothingSent();
    }

    public function test_bulk_send_limits_selection_to_one_hundred_entries(): void
    {
        Livewire::actingAs($this->admin())->test(Manager::class)
            ->set('selected', range(1, 101))->call('sendSelected')->assertHasErrors(['selected' => 'max']);
        Mail::assertNothingSent();
    }

    public function test_select_ready_does_not_select_requests_outside_the_visible_page(): void
    {
        $ready = $this->notification($this->product('old-ready', 2));
        $empty = $this->product('new-empty', 0);
        foreach (range(1, 20) as $index) {
            $this->notification($empty, ['email' => 'waiting-'.$index.'@example.test']);
        }

        Livewire::actingAs($this->admin())->test(Manager::class)
            ->call('selectReady')->assertSet('selected', [])
            ->call('gotoPage', 2, 'stockNotificationsPage')
            ->call('selectReady')->assertSet('selected', [$ready->id]);
        Mail::assertNothingSent();
    }

    public function test_readiness_and_selection_use_the_recipient_group_for_the_same_bundle(): void
    {
        CustomerGroup::query()->create(['code' => 'public', 'name' => 'Kupci', 'is_active' => true, 'is_default' => true]);
        $privateGroup = CustomerGroup::query()->create(['code' => 'private', 'name' => 'Posebna grupa', 'is_active' => true]);
        $buyer = User::factory()->create();
        $buyer->customerGroups()->attach($privateGroup);
        $bundle = Bundle::query()->create(['title' => 'Poseban komplet', 'is_active' => true]);
        $bundle->products()->attach([
            $this->product('bundle-one', 5)->id => ['quantity' => 1],
            $this->product('bundle-two', 5)->id => ['quantity' => 1],
        ]);
        $bundle->discounts()->create(['customer_group_id' => $privateGroup->id, 'type' => 'percent', 'value' => 10]);
        $guest = StockNotification::query()->create([
            'target_key' => 'bundle:'.$bundle->id, 'bundle_id' => $bundle->id,
            'email' => 'guest-bundle@example.test', 'locale' => 'hr', 'consented_at' => now(), 'status' => 'waiting',
        ]);
        $eligible = StockNotification::query()->create([
            'target_key' => 'bundle:'.$bundle->id, 'bundle_id' => $bundle->id, 'user_id' => $buyer->id,
            'email' => 'buyer-bundle@example.test', 'locale' => 'hr', 'consented_at' => now(), 'status' => 'waiting',
        ]);

        Livewire::actingAs($this->admin())->test(Manager::class)
            ->assertViewHas('stats', fn ($stats) => $stats['ready'] === 1 && $stats['waiting'] === 1)
            ->set('status', 'ready')->assertViewHas('rows', fn ($rows) => $rows->pluck('id')->all() === [$eligible->id])
            ->call('selectReady')->assertSet('selected', [$eligible->id])
            ->call('sendSelected')->assertHasNoErrors();

        $this->assertSame('sent', $eligible->fresh()->status);
        $this->assertSame('waiting', $guest->fresh()->status);
        Mail::assertSentCount(1);
    }

    public function test_readiness_uses_the_notification_language_for_the_same_product(): void
    {
        $product = $this->product('english', 5);
        $product->translations()->delete();
        $product->translations()->create(['locale' => 'en', 'name' => 'English product', 'slug' => 'english-product']);
        $this->notification($product, ['locale' => 'hr']);
        $english = $this->notification($product, ['locale' => 'en', 'email' => 'english-ready@example.test']);

        Livewire::actingAs($this->admin())->test(Manager::class)
            ->assertViewHas('stats', fn ($stats) => $stats['ready'] === 1 && $stats['waiting'] === 1)
            ->set('status', 'ready')->assertViewHas('rows', fn ($rows) => $rows->pluck('id')->all() === [$english->id])
            ->call('selectReady')->assertSet('selected', [$english->id]);
        Mail::assertNothingSent();
    }

    public function test_fresh_processing_cannot_be_marked_interrupted(): void
    {
        $notification = $this->notification($this->product('fresh-processing', 5), [
            'status' => 'processing', 'last_attempt_at' => now()->subMinutes(15), 'attempts' => 1, 'claim_token' => Str::random(64),
        ]);

        Livewire::actingAs($this->admin())->test(Manager::class)
            ->assertDontSee('Označi kao prekinuto')
            ->call('markInterrupted', $notification->id)
            ->assertDispatched('notify', type: 'warning', message: 'Možete označiti samo slanje koje je započelo prije najmanje 30 minuta.');

        $this->assertSame('processing', $notification->fresh()->status);
        $this->assertNotNull($notification->fresh()->claim_token);
        $this->assertSame(1, $notification->fresh()->attempts);
        Mail::assertNothingSent();
    }

    public function test_stale_processing_can_be_recovered_for_manual_retry_without_automatic_resend(): void
    {
        config(['stock_notifications.max_attempts' => 3]);
        $product = $this->product('stale-processing', 5);
        $notification = $this->notification($product, [
            'status' => 'processing', 'last_attempt_at' => now()->subMinutes(31), 'attempts' => 1, 'claim_token' => Str::random(64),
        ]);
        $older = $this->notification($product, [
            'email' => 'many-attempts@example.test', 'status' => 'processing', 'last_attempt_at' => now()->subHour(),
            'attempts' => 7, 'claim_token' => Str::random(64),
        ]);

        $component = Livewire::actingAs($this->admin())->test(Manager::class)
            ->assertSee('Označi kao prekinuto')
            ->call('markInterrupted', $notification->id)->call('markInterrupted', $older->id);

        $this->assertSame('failed', $notification->fresh()->status);
        $this->assertNull($notification->fresh()->claim_token);
        $this->assertSame(3, $notification->fresh()->attempts);
        $this->assertSame(7, $older->fresh()->attempts);
        $this->assertStringContainsString('provjerite je li poruka već dostavljena', $notification->fresh()->last_error);
        app(StockNotificationService::class)->processReady();
        Mail::assertNothingSent();

        $component->call('send', $notification->id);
        $this->assertSame('sent', $notification->fresh()->status);
        $this->assertSame('failed', $older->fresh()->status);
        Mail::assertSentCount(1);
    }

    public function test_already_available_private_bundle_does_not_accept_a_subscription_from_its_eligible_buyer(): void
    {
        CustomerGroup::query()->create(['code' => 'public', 'name' => 'Kupci', 'is_active' => true, 'is_default' => true]);
        $group = CustomerGroup::query()->create(['code' => 'eligible', 'name' => 'Posebna grupa', 'is_active' => true]);
        $buyer = User::factory()->create();
        $buyer->customerGroups()->attach($group);
        $bundle = Bundle::query()->create(['title' => 'Dostupan privatni komplet', 'is_active' => true]);
        $bundle->products()->attach([
            $this->product('available-one', 5)->id => ['quantity' => 1],
            $this->product('available-two', 5)->id => ['quantity' => 1],
        ]);
        $bundle->discounts()->create(['customer_group_id' => $group->id, 'type' => 'percent', 'value' => 10]);

        $this->actingAs($buyer)->get(route('stock-notifications.create', ['bundle_id' => $bundle->id]))
            ->assertRedirect(route('bundles.index').'#bundle-'.$bundle->id);
        $this->postJson(route('stock-notifications.store'), [
            'bundle_id' => $bundle->id, 'email' => 'eligible@example.test', 'consent' => true,
        ])->assertUnprocessable()->assertJsonValidationErrors('bundle_id');
        $this->assertDatabaseCount('stock_notifications', 0);
        Mail::assertNothingSent();
    }

    private function viewer(): User
    {
        $user = User::factory()->create();
        Bouncer::allow($user)->to(['admin.access', 'catalog.products.view']);

        return $user;
    }

    private function admin(): User
    {
        $user = User::factory()->create();
        Bouncer::assign('superadmin')->to($user);

        return $user;
    }

    private function product(string $code, int $stock): Product
    {
        $product = Product::query()->create([
            'code' => $code, 'sku' => 'SKU-'.strtoupper($code), 'is_active' => true,
            'base_price' => 20, 'stock_qty' => $stock,
        ]);
        $product->translations()->create(['locale' => 'hr', 'name' => 'Artikl '.$code, 'slug' => 'artikl-'.$code]);

        return $product;
    }

    private function notification(Product $product, array $attributes = []): StockNotification
    {
        return StockNotification::query()->create(array_replace([
            'target_key' => 'product:'.$product->id, 'product_id' => $product->id,
            'email' => $product->code.'@example.test', 'locale' => 'hr', 'status' => 'waiting',
            'token' => Str::random(64), 'consented_at' => now(),
        ], $attributes));
    }
}
