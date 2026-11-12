<?php

namespace Tests\Feature\Shipping;

use App\Models\Sales\Order\BoxNowShipment;
use App\Models\Sales\Order\Order;
use App\Models\Settings\Local\OrderStatus;
use App\Models\Settings\Local\ShippingMethod;
use App\Models\User;
use App\Services\Integrations\BoxNow\BoxNowShipmentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Silber\Bouncer\BouncerFacade as Bouncer;
use Tests\TestCase;

class BoxNowShipmentTest extends TestCase
{
    use RefreshDatabase;

    private ShippingMethod $method;

    private Order $order;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        Storage::fake('local');
        $this->admin = User::factory()->create();
        Bouncer::assign('admin')->to($this->admin);
        $buyer = User::factory()->create();
        $this->method = ShippingMethod::query()->create([
            'code' => 'boxnow', 'name' => 'BOX NOW', 'carrier' => 'boxnow', 'is_active' => true,
            'settings' => [
                'boxnow_api_url' => 'https://api-stage.boxnow.hr',
                'boxnow_oauth_client_id' => 'test-client',
                'boxnow_oauth_client_secret_encrypted' => Crypt::encryptString('test-secret'),
                'boxnow_warehouse_id' => '100',
                'boxnow_webhook_token_encrypted' => Crypt::encryptString(str_repeat('w', 40)),
            ],
        ]);
        $status = OrderStatus::query()->create(['code' => 'new', 'name' => 'New', 'is_active' => true]);
        $this->order = Order::query()->create([
            'order_number' => 'BOX-TEST-001', 'user_id' => $buyer->id, 'status_id' => $status->id,
            'customer_name' => 'Test Kupac', 'customer_email' => $buyer->email, 'customer_phone' => '091 123 4567',
            'shipping_country_code' => 'HR', 'currency_code' => 'EUR', 'grand_total' => 45.50,
            'payment_method_code' => 'cod', 'shipping_method_code' => 'boxnow', 'shipping_method_name' => 'BOX NOW',
            'payload' => ['shipping' => ['boxnow' => ['locker_id' => '200', 'locker_name' => 'Zagreb']]],
        ]);
    }

    private function fakeApi(array $parcels = [], bool $labelFails = false): void
    {
        Http::fake([
            '*/auth-sessions' => Http::response(['access_token' => 'test-token', 'expires_in' => 3600]),
            '*/delivery-requests' => Http::response(['referenceNumber' => 'REF-1', 'parcels' => [['id' => '1234567890']]]),
            '*/parcels?*' => Http::response(['data' => $parcels]),
            '*/label.pdf' => $labelFails ? Http::response('Unavailable', 503) : Http::response('%PDF-1.4 test', 200, ['Content-Type' => 'application/pdf']),
        ]);
    }

    public function test_admin_send_is_idempotent_and_immediately_visible_to_customer(): void
    {
        $this->fakeApi();
        $this->actingAs($this->admin)->post(route('admin.orders.boxnow.send', $this->order))->assertRedirect();
        $this->actingAs($this->admin)->post(route('admin.orders.boxnow.send', $this->order))->assertRedirect();
        $this->assertDatabaseCount('box_now_shipments', 1);
        $this->assertSame('boxnow_waiting', $this->order->fresh()->status->code);
        $this->assertNull($this->order->fresh()->paid_at);
        $this->assertSame(1, $this->order->history()->count());
        Http::assertSentCount(2);
        Http::assertSent(fn ($request) => str_ends_with($request->url(), '/delivery-requests')
            && $request['destination']['locationId'] === '200' && $request['destination']['contactNumber'] === '+385911234567'
            && $request['paymentMode'] === 'cod' && $request['amountToBeCollected'] === '45.50' && count($request['items']) === 1);
        $this->actingAs($this->order->user)->get(route('account.orders.show', ['orderNumber' => $this->order->order_number]))
            ->assertOk()->assertSee('1234567890')->assertSee('Čeka preuzimanje od trgovine')->assertSee('Prati pošiljku na BOX NOW');
        $this->actingAs(User::factory()->create())->get(route('account.orders.show', ['orderNumber' => $this->order->order_number]))->assertNotFound();
    }

    public function test_label_failure_does_not_recreate_a_shipment(): void
    {
        $this->fakeApi(labelFails: true);
        $service = app(BoxNowShipmentService::class);
        $shipment = $service->send($this->order);
        $this->actingAs($this->admin)->get(route('admin.orders.boxnow.label', $this->order))->assertRedirect();
        $this->assertSame($shipment->id, $service->send($this->order)->id);
        $this->assertSame('1234567890', $shipment->fresh()->parcel_id);
        Http::assertSentCount(3);
    }

    public function test_timeout_is_reconciled_without_another_create_request(): void
    {
        Http::fake([
            '*/auth-sessions' => Http::response(['access_token' => 'token']),
            '*/delivery-requests' => Http::failedConnection(),
            '*/parcels?*' => Http::response(['data' => [['id' => '1234567890', 'state' => 'new', 'deliveryRequest' => ['orderNumber' => $this->order->order_number]]]]),
        ]);
        $service = app(BoxNowShipmentService::class);
        try {
            $service->send($this->order);
            $this->fail('Expected timeout.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('veza', $e->getMessage());
        }
        $this->assertNotNull(BoxNowShipment::first()->attempted_at);
        $shipment = $service->send($this->order);
        $this->assertSame('1234567890', $shipment->parcel_id);
        $this->assertSame(1, Http::recorded(fn ($request) => str_ends_with($request->url(), '/delivery-requests'))->count());
    }

    public function test_tracking_deduplicates_events_and_never_regresses_on_late_events(): void
    {
        $this->fakeApi();
        $service = app(BoxNowShipmentService::class);
        $shipment = $service->send($this->order);
        $this->travel(1)->minutes();
        $service->recordEvent($shipment, 'in-transit', now()->toIso8601String(), null, 'webhook');
        $this->assertSame('boxnow_sent', $this->order->fresh()->status->code);
        $this->travel(1)->minutes();
        $at = now()->toIso8601String();
        $service->recordEvent($shipment, 'in-final-destination', $at, 'Zagreb', 'poll');
        $service->recordEvent($shipment, 'final-destination', $at, 'Zagreb', 'webhook');
        $service->recordEvent($shipment, 'in-depot', now()->subSeconds(30)->toIso8601String(), null, 'webhook');
        $this->assertSame('boxnow_ready', $this->order->fresh()->status->code);
        $this->assertSame(4, $shipment->events()->count());
        $this->travel(1)->minutes();
        $service->recordEvent($shipment, 'delivered', now()->toIso8601String(), null, 'webhook');
        $this->assertSame('boxnow_delivered', $this->order->fresh()->status->code);
        $this->assertNull($this->order->fresh()->paid_at);
        $this->travel(1)->minutes();
        $service->recordEvent($shipment, 'in-transit', now()->toIso8601String(), null, 'webhook');
        $this->assertSame('delivered', $shipment->fresh()->state);
    }

    public function test_webhook_requires_token_and_matching_order_and_uses_event_not_parcel_state(): void
    {
        $this->fakeApi();
        app(BoxNowShipmentService::class)->send($this->order);
        $this->travel(1)->minutes();
        $url = route('boxnow.webhook', $this->method);
        $data = ['data' => ['parcelId' => '1234567890', 'orderNumber' => $this->order->order_number, 'event' => 'final-destination', 'parcelState' => 'in-transit', 'time' => now()->toIso8601String()]];
        $this->postJson($url, $data)->assertUnauthorized();
        $this->withToken(str_repeat('w', 40))->postJson($url, $data)->assertOk();
        $this->withToken(str_repeat('w', 40))->postJson($url, $data)->assertOk();
        $this->assertSame('boxnow_ready', $this->order->fresh()->status->code);
        $this->assertDatabaseCount('box_now_shipment_events', 2);
        $data['data']['orderNumber'] = 'OTHER';
        $this->withToken(str_repeat('w', 40))->postJson($url, $data)->assertStatus(409);
    }

    public function test_scheduled_polling_imports_events_and_preserves_cancelled_order(): void
    {
        $this->fakeApi([['id' => '1234567890', 'state' => 'in-transit', 'events' => [['type' => 'in-depot', 'createTime' => now()->addMinute()->toIso8601String(), 'locationDisplayName' => 'Zagreb']]]]);
        $shipment = app(BoxNowShipmentService::class)->send($this->order);
        $cancelled = OrderStatus::query()->create(['code' => 'cancelled', 'name' => 'Cancelled', 'is_cancelled' => true]);
        $this->order->update(['status_id' => $cancelled->id]);
        $this->travel(1)->minutes();
        $this->artisan('boxnow:sync')->assertSuccessful();
        $this->assertSame('in-depot', $shipment->fresh()->state);
        $this->assertSame('cancelled', $this->order->fresh()->status->code);
    }

    public function test_customer_and_read_only_staff_cannot_send_or_download_other_orders(): void
    {
        $this->fakeApi();
        $this->actingAs($this->order->user)->post(route('admin.orders.boxnow.send', $this->order))->assertForbidden();
        $reader = User::factory()->create();
        Bouncer::allow($reader)->to('admin.access');
        Bouncer::allow($reader)->to('sales.orders.view');
        $this->actingAs($reader)->post(route('admin.orders.boxnow.send', $this->order))->assertForbidden();
        Http::assertNothingSent();
    }

    public function test_missing_locker_is_rejected_before_calling_api(): void
    {
        $this->fakeApi();
        $this->order->update(['payload' => []]);
        $this->actingAs($this->admin)->post(route('admin.orders.boxnow.send', $this->order))
            ->assertSessionHas('notify.type', 'error');
        Http::assertNothingSent();
        $this->assertDatabaseCount('box_now_shipments', 0);
    }
}
