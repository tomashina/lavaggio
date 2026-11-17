<?php

namespace Tests\Feature\Payments;

use App\Livewire\Admin\Settings\Local\ResourceManager;
use App\Models\Sales\Order\Order;
use App\Models\Settings\Local\OrderStatus;
use App\Models\Settings\Local\PaymentMethod;
use App\Models\User;
use App\Services\Front\StoreNotificationService;
use App\Services\Payments\CorvusPayFormService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Mockery\MockInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use Silber\Bouncer\BouncerFacade as Bouncer;
use Tests\TestCase;

class CorvusPreauthorizationTest extends TestCase
{
    use RefreshDatabase;

    private PaymentMethod $method;

    private OrderStatus $pending;

    protected function setUp(): void
    {
        parent::setUp();
        config(['storefront.home' => 'lavaggio', 'commerce.b2b_only' => false]);
        Http::preventStrayRequests();
        $this->pending = OrderStatus::create(['code' => 'new', 'name' => 'Nova', 'is_active' => true]);
        OrderStatus::create(['code' => 'paid', 'name' => 'Plaćeno', 'is_paid' => true, 'is_active' => true]);
        OrderStatus::create(['code' => 'cancelled', 'name' => 'Otkazano', 'is_cancelled' => true, 'is_active' => true]);
        $this->method = PaymentMethod::create([
            'code' => 'corvus', 'name' => 'CorvusPay', 'is_active' => true,
            'settings' => ['corvus_store_id' => '123', 'corvus_secret_key' => 'fixture-secret',
                'corvus_mode' => 'live', 'corvus_require_complete' => 'true'],
        ]);
    }

    #[DataProvider('paymentModes')]
    public function test_admin_choice_is_saved_reloaded_and_sent_in_the_signed_payment_form(string $mode): void
    {
        $admin = User::factory()->create();
        Bouncer::assign('admin')->to($admin);
        Livewire::actingAs($admin)->test(ResourceManager::class, [
            'resource' => 'payment-methods', 'recordId' => $this->method->id, 'editPage' => true,
        ])
            ->assertSee('Predautorizacija — ručna potvrda naplate')
            ->set('form.corvus_require_complete', $mode)->call('save')->assertHasNoErrors();
        $component = Livewire::actingAs($admin)->test(ResourceManager::class, [
            'resource' => 'payment-methods', 'recordId' => $this->method->id, 'editPage' => true,
        ])->assertSet('form.corvus_require_complete', $mode);
        $this->assertSame('fixture-secret', $this->method->fresh()->settings['corvus_secret_key']);
        $this->assertStringNotContainsString('fixture-secret', $component->html());

        $order = $this->order();
        $form = app(CorvusPayFormService::class)->buildFormData($order);
        $this->assertSame($mode, $form['payload']['require_complete']);
        $this->assertSame($mode, data_get($order->fresh()->payload, 'corvuspay.request.require_complete'));
        $this->assertSame($this->signature($form['payload']), $form['payload']['signature']);
    }

    public static function paymentModes(): array
    {
        return ['manual completion' => ['true'], 'immediate charge' => ['false']];
    }

    #[DataProvider('paymentModes')]
    public function test_callback_uses_original_request_mode_even_after_settings_change(string $mode): void
    {
        $this->setMode($mode);
        $order = $this->order();
        app(CorvusPayFormService::class)->buildFormData($order);
        $this->setMode($mode === 'true' ? 'false' : 'true');
        $rebuilt = app(CorvusPayFormService::class)->buildFormData($order->fresh());
        $this->assertSame($mode, $rebuilt['payload']['require_complete']);
        $callback = $this->signedCallback($order);
        $callback['require_complete'] = $mode === 'true' ? 'false' : 'true';
        $result = app(CorvusPayFormService::class)->handleCallback($order, $callback, 'success');

        $this->assertSame($mode === 'true' ? 'preauthorized' : 'approved', $result['status']);
        $this->assertSame($mode === 'false', $result['paid']);
        $this->assertSame($mode === 'false', $result['newly_paid']);
        $this->assertSame($mode === 'true', $order->fresh()->paid_at === null);
        Http::assertNothingSent();
    }

    public function test_preauthorization_notifies_once_without_payment_and_cannot_be_restarted_or_abandoned(): void
    {
        $order = $this->order();
        $service = app(CorvusPayFormService::class);
        $form = $service->buildFormData($order);
        $this->mock(StoreNotificationService::class, function (MockInterface $mock): void {
            $mock->shouldReceive('sendOrderNotification')->once();
        });
        $url = route('checkout.corvus.success.static', $this->signedCallback($order));
        $successUrl = route('checkout.success', ['orderNumber' => $order->order_number]);
        foreach (range(1, 2) as $attempt) {
            $this->get($url)->assertRedirect($successUrl)
                ->assertSessionHas('status', __('ui.checkout.corvus.status.preauthorized'));
        }
        $order->refresh();
        $this->assertNull($order->paid_at);
        $this->assertSame($this->pending->id, $order->status_id);
        $this->assertNotNull(data_get($order->payload, 'corvuspay.preauthorized_at'));
        $this->assertSame(1, $order->transactions()->count());
        $this->assertSame('preauthorized', $order->transactions()->sole()->status);
        $this->assertSame(1, $order->history()->count());
        $this->assertNull($service->buildFormData($order));
        $this->get(route('checkout.corvus.start', ['orderNumber' => $order->order_number]))->assertRedirect($successUrl);
        $this->get($successUrl)->assertOk()->assertSee(__('ui.checkout.corvus.status.preauthorized'));

        $this->get($form['payload']['cancel_url'])->assertRedirect($successUrl)->assertSessionMissing('front.cart.items');
        $cancel = $service->handleCallback($order, $this->signedCallback($order, ''), 'cancel');
        $this->assertSame('already_preauthorized', $cancel['status']);
        $this->assertFalse($cancel['cancellation_applied']);
        $this->assertSame('preauthorized', data_get($order->fresh()->payload, 'corvuspay.status'));
        $this->assertSame('APPROVED', data_get($order->fresh()->payload, 'corvuspay.approval_code'));
        $this->assertNull(data_get($order->fresh()->payload, 'corvuspay.cancel_restocked_at'));
        $this->assertSame($this->pending->id, $order->fresh()->status_id);
        Http::assertNothingSent();
    }

    public function test_invalid_signature_cannot_reserve_or_pay_an_order(): void
    {
        $order = $this->order();
        $service = app(CorvusPayFormService::class);
        $service->buildFormData($order);
        $callback = $this->signedCallback($order);
        $callback['signature'] = 'forged';
        $this->assertSame('invalid_signature', $service->handleCallback($order, $callback, 'success')['status']);
        $this->assertNull($order->fresh()->paid_at);
        $this->assertFalse($service->hasPreauthorization($order->fresh()));
        $this->assertSame(0, $order->transactions()->count());
    }

    public function test_replaying_preauthorization_after_manual_paid_status_never_records_a_capture(): void
    {
        $order = $this->order();
        $service = app(CorvusPayFormService::class);
        $service->buildFormData($order);
        $service->handleCallback($order, $this->signedCallback($order), 'success');
        $order->refresh()->update(['paid_at' => now(), 'status_id' => OrderStatus::where('code', 'paid')->sole()->id]);
        $result = $service->handleCallback($order, $this->signedCallback($order), 'success');
        $this->assertSame('already_paid', $result['status']);
        $this->assertFalse($result['newly_paid']);
        $this->assertSame(0, $order->transactions()->where('status', 'approved')->count());
        $this->assertNotNull($order->fresh()->paid_at);
        Http::assertNothingSent();
    }

    public function test_migration_enables_existing_lavaggio_methods_without_changing_credentials_or_activation(): void
    {
        $this->setMode('false');
        $before = $this->method->fresh()->settings;
        $legacy = PaymentMethod::create(['code' => 'corvuspay', 'name' => 'Legacy', 'is_active' => false, 'settings' => ['custom' => 'keep']]);
        $other = PaymentMethod::create(['code' => 'bank', 'name' => 'Bank', 'settings' => ['custom' => 'keep']]);
        $migration = require database_path('migrations/2026_11_17_120000_enable_lavaggio_corvus_preauthorization.php');
        config(['storefront.home' => 'termol']);
        $migration->up();
        $this->assertSame($before, $this->method->fresh()->settings);
        config(['storefront.home' => 'lavaggio']);
        $migration->up();
        $this->assertSame([...$before, 'corvus_require_complete' => 'true'], $this->method->fresh()->settings);
        $this->assertTrue($this->method->fresh()->is_active);
        $this->assertFalse($legacy->fresh()->is_active);
        $this->assertSame(['custom' => 'keep', 'corvus_require_complete' => 'true'], $legacy->fresh()->settings);
        $this->assertSame(['custom' => 'keep'], $other->fresh()->settings);
        $this->setMode('false');
        $migration->down();
        $this->assertSame('false', $this->method->fresh()->settings['corvus_require_complete']);
    }

    private function setMode(string $mode): void
    {
        $this->method->refresh();
        $this->method->update(['settings' => [...$this->method->settings, 'corvus_require_complete' => $mode]]);
    }

    private function order(): Order
    {
        return Order::create([
            'order_number' => 'CORVUS-PREAUTH-'.(Order::count() + 1), 'status_id' => $this->pending->id,
            'currency_code' => 'EUR', 'customer_email' => 'fixture@example.test', 'customer_name' => 'Test buyer',
            'payment_method_code' => 'corvus', 'payment_method_name' => 'CorvusPay', 'grand_total' => 10,
        ]);
    }

    private function signedCallback(Order $order, string $approval = 'APPROVED'): array
    {
        $input = ['approval_code' => $approval, 'language' => 'hr', 'order_number' => $order->order_number];

        return [...$input, 'signature' => $this->signature($input)];
    }

    private function signature(array $input): string
    {
        unset($input['signature']);
        ksort($input, SORT_STRING);
        $message = '';
        foreach ($input as $key => $value) {
            $message .= $key.$value;
        }

        return hash_hmac('sha256', $message, 'fixture-secret');
    }
}
