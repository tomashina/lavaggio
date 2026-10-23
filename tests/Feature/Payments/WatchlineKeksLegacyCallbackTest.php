<?php

namespace Tests\Feature\Payments;

use App\Livewire\Admin\Settings\Local\ResourceManager;
use App\Models\Sales\Order\Order;
use App\Models\Settings\Local\OrderStatus;
use App\Models\Settings\Local\PaymentMethod;
use App\Models\Settings\Local\ShippingMethod;
use App\Models\User;
use App\Services\Import\WatchlineCheckoutConfigurationService;
use App\Services\Payments\KeksPayService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use Silber\Bouncer\BouncerFacade as Bouncer;
use Tests\TestCase;

class WatchlineKeksLegacyCallbackTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['commerce.local_safe_mode' => true, 'commerce.b2b_only' => false]);
        Http::preventStrayRequests();
        OrderStatus::create(['code' => 'new', 'name' => 'Nova', 'is_default' => true, 'is_active' => true]);
        OrderStatus::create(['code' => 'paid', 'name' => 'Plaćena', 'is_paid' => true, 'is_active' => true]);
    }

    public function test_exact_query_credentials_authorize_the_callback_without_storing_them_in_order_data(): void
    {
        $order = $this->order();
        $request = Request::create('/checkout/keks/advice', 'POST');
        $request->query->replace(['user' => 'callback-user-fixture', 'pass' => 'callback-pass-fixture']);
        $result = app(KeksPayService::class)->handleAdvice($this->advice() + [
            'user' => 'callback-user-fixture', 'pass' => 'callback-pass-fixture',
        ], $request);

        $this->assertSame(0, $result['status']);
        $this->assertNotNull($order->fresh()->paid_at);
        $this->assertSame('approved', $order->fresh()->transactions()->sole()->status);
        $this->assertArrayNotHasKey('user', $order->fresh()->transactions()->sole()->payload['raw']);
        $this->assertArrayNotHasKey('pass', $order->fresh()->payload['kekspay']['advice']);
        $this->assertStringNotContainsString('callback-pass-fixture', json_encode($order->fresh()->payload));
    }

    #[DataProvider('invalidCredentials')]
    public function test_both_query_credentials_are_required_and_untrusted_shapes_are_rejected(mixed $user, mixed $password): void
    {
        $order = $this->order();
        $request = Request::create('/checkout/keks/advice', 'POST');
        $request->query->replace(['user' => $user, 'pass' => $password]);
        $request->headers->set('Authorization', 'Token unrelated-source-token');
        $result = app(KeksPayService::class)->handleAdvice($this->advice() + [
            'user' => 'callback-user-fixture', 'pass' => 'callback-pass-fixture',
        ], $request);

        $this->assertSame(-3, $result['status']);
        $this->assertNull($order->fresh()->paid_at);
        $this->assertSame(0, $order->transactions()->count());
        $this->assertSame(0, $order->history()->count());
    }

    public static function invalidCredentials(): array
    {
        return [
            'wrong password' => ['callback-user-fixture', 'wrong'],
            'wrong username' => ['wrong', 'callback-pass-fixture'],
            'no query credentials' => [null, null],
            'empty query credentials' => ['', ''],
            'array username' => [['callback-user-fixture'], 'callback-pass-fixture'],
            'array password' => ['callback-user-fixture', ['callback-pass-fixture']],
        ];
    }

    public function test_missing_configured_legacy_credentials_fail_closed_and_safe_mode_blocks_payment_preparation(): void
    {
        $order = $this->order();
        PaymentMethod::where('code', 'keks')->firstOrFail()->update(['settings' => [
            'keks_advice_auth_mode' => 'legacy_query_credentials', 'keks_advice_username' => '', 'keks_advice_password' => '',
            'keks_cid' => 'fixture-cid', 'keks_tid' => 'fixture-tid', 'keks_des_key' => '123456789012345678901234',
        ]]);
        $request = Request::create('/checkout/keks/advice', 'POST');
        $request->query->replace(['user' => '', 'pass' => '']);
        $this->assertSame(-3, app(KeksPayService::class)->handleAdvice($this->advice(), $request)['status']);
        $this->assertNull(app(KeksPayService::class)->buildSellData($order));
        $this->assertNull($order->fresh()->payload);
        $this->assertDatabaseCount('order_transactions', 0);
    }

    public function test_existing_token_authentication_remains_available_when_explicitly_selected(): void
    {
        $order = $this->order();
        PaymentMethod::where('code', 'keks')->firstOrFail()->update(['settings' => [
            'keks_advice_auth_mode' => 'token', 'keks_advice_token' => 'explicit-token-fixture',
        ]]);
        $request = Request::create('/checkout/keks/advice', 'POST');
        $request->headers->set('Authorization', 'Token explicit-token-fixture');
        $this->assertSame(0, app(KeksPayService::class)->handleAdvice($this->advice(), $request)['status']);
        $this->assertNotNull($order->fresh()->paid_at);
    }

    public function test_configuration_maps_raw_des_key_and_targeted_command_preserves_every_other_setting(): void
    {
        $snapshot = $this->snapshot();
        app(WatchlineCheckoutConfigurationService::class)->configure($snapshot);
        $keks = PaymentMethod::where('code', 'keks')->firstOrFail();
        $this->assertFalse($keks->is_active);
        $this->assertSame('123456789012345678901234', $keks->settings['keks_des_key']);
        $this->assertSame('legacy_query_credentials', $keks->settings['keks_advice_auth_mode']);
        $this->assertArrayNotHasKey('keks_advice_token', $keks->settings);
        $keks->update(['name' => 'Ručni naziv', 'fee_value' => 1.75]);
        $before = $keks->fresh();
        $shipping = ShippingMethod::where('code', 'gls_locker')->firstOrFail();
        $shipping->update(['price' => 8.25]);
        $shippingState = $shipping->fresh()->getAttributes();
        $bankState = PaymentMethod::where('code', 'bank')->firstOrFail()->getAttributes();
        $snapshot['keks_callback']['pass'] = 'updated-callback-pass-fixture';
        Storage::fake('local');
        Storage::disk('local')->put('watchline/fake-checkout.json', json_encode($snapshot));

        $this->artisan('watchline:configure-checkout', ['--snapshot' => 'watchline/fake-checkout.json', '--keks-callback-only' => true])->assertSuccessful();
        $after = $keks->fresh();
        $this->assertSame($before->name, $after->name);
        $this->assertSame($before->fee_value, $after->fee_value);
        $this->assertSame($before->settings['keks_des_key'], $after->settings['keks_des_key']);
        $this->assertSame('updated-callback-pass-fixture', $after->settings['keks_advice_password']);
        $this->assertSame($before->settings['watchline_checkout'], $after->settings['watchline_checkout']);
        $this->assertSame($shippingState, $shipping->fresh()->getAttributes());
        $this->assertSame($bankState, PaymentMethod::where('code', 'bank')->firstOrFail()->getAttributes());
    }

    public function test_snapshot_refresh_without_callback_pair_preserves_working_authentication_and_initial_missing_pair_fails_closed(): void
    {
        $snapshot = $this->snapshot();
        $withoutCallback = $snapshot;
        unset($withoutCallback['keks_callback']);
        $configuration = app(WatchlineCheckoutConfigurationService::class);
        $configuration->configure($withoutCallback);
        $settings = PaymentMethod::where('code', 'keks')->firstOrFail()->settings;
        $this->assertSame('legacy_query_credentials', $settings['keks_advice_auth_mode']);
        $this->assertSame('', $settings['keks_advice_password']);
        $configuration->updateKeksCallbackAuth($snapshot);
        $report = $configuration->configure($withoutCallback);
        $this->assertSame([], $report['preserved_admin_edits']);
        $settings = PaymentMethod::where('code', 'keks')->firstOrFail()->settings;
        $this->assertSame('callback-user-fixture', $settings['keks_advice_username']);
        $this->assertSame('callback-pass-fixture', $settings['keks_advice_password']);
        $configuration->configure($withoutCallback, true);
        $this->assertSame('callback-pass-fixture', PaymentMethod::where('code', 'keks')->firstOrFail()->settings['keks_advice_password']);
    }

    public function test_admin_can_save_legacy_callback_mode_without_hydrating_or_erasing_its_password(): void
    {
        app(WatchlineCheckoutConfigurationService::class)->configure($this->snapshot());
        $method = PaymentMethod::where('code', 'keks')->firstOrFail();
        $admin = User::factory()->create();
        Bouncer::assign('admin')->to($admin);
        Livewire::actingAs($admin)->test(ResourceManager::class, ['resource' => 'payment-methods', 'recordId' => $method->id, 'editPage' => true])
            ->assertSet('form.keks_advice_auth_mode', 'legacy_query_credentials')
            ->assertSet('form.keks_advice_username', 'callback-user-fixture')->assertSet('form.keks_advice_password', '')
            ->assertSee('Korisnik i lozinka — stari Watchline')->assertDontSee('callback-pass-fixture')
            ->set('form.name', 'KEKS Pay — ručni naziv')->call('save')->assertHasNoErrors();
        $this->assertSame('legacy_query_credentials', $method->fresh()->settings['keks_advice_auth_mode']);
        $this->assertSame('callback-pass-fixture', $method->fresh()->settings['keks_advice_password']);
    }

    private function snapshot(): array
    {
        return ['language_id' => 2, 'settings' => [
            'kekspay_status' => '0', 'kekspay_cid' => 'fixture-cid', 'kekspay_tid' => 'fixture-tid',
            'kekspay_password' => '123456789012345678901234', 'kekspay_token' => 'unproven-token-fixture',
        ], 'keks_callback' => ['user' => 'callback-user-fixture', 'pass' => 'callback-pass-fixture']];
    }

    private function order(): Order
    {
        PaymentMethod::create(['code' => 'keks', 'name' => 'KEKS Pay', 'provider' => 'keks', 'is_active' => true,
            'settings' => ['keks_advice_auth_mode' => 'legacy_query_credentials',
                'keks_advice_username' => 'callback-user-fixture', 'keks_advice_password' => 'callback-pass-fixture']]);

        return Order::create(['order_number' => 'KEKS-LEGACY-FIXTURE', 'status_id' => OrderStatus::where('code', 'new')->value('id'),
            'customer_name' => 'Test Kupac', 'customer_email' => 'callback-fixture@example.test',
            'payment_method_code' => 'keks', 'currency_code' => 'EUR', 'grand_total' => 20]);
    }

    private function advice(): array
    {
        return ['bill_id' => 'KEKS-LEGACY-FIXTURE', 'status' => 0, 'amount' => 20, 'keks_id' => 'KEKS-TRANSACTION-FIXTURE'];
    }
}
