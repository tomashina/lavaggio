<?php

namespace Tests\Feature\Payments;

use App\Models\Sales\Order\Order;
use App\Models\Settings\Local\OrderStatus;
use App\Models\Settings\Local\PaymentMethod;
use App\Models\User;
use App\Services\Integrations\Fira\FiraInvoiceService;
use App\Services\Integrations\Fira\FiraOrderPayload;
use App\Services\Integrations\Fira\FiraSettingsService;
use App\Services\Payments\CorvusPayFormService;
use App\Services\Payments\WSPayFormService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class LavaggioFiraCardCallbacksTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['storefront.home' => 'lavaggio', 'commerce.b2c_only' => true, 'commerce.b2b_only' => false, 'commerce.local_safe_mode' => false]);
        Http::preventStrayRequests();
        OrderStatus::query()->create(['code' => 'new', 'name' => 'Nova', 'is_default' => true, 'is_active' => true]);
        OrderStatus::query()->create(['code' => 'paid', 'name' => 'Plaćeno', 'is_paid' => true, 'is_active' => true]);
        OrderStatus::query()->create(['code' => 'cancelled', 'name' => 'Otkazano', 'is_cancelled' => true, 'is_active' => true]);
        PaymentMethod::query()->create([
            'code' => 'corvus', 'name' => 'CorvusPay', 'provider' => 'corvus', 'is_active' => true,
            'settings' => ['corvus_mode' => 'live', 'corvus_store_id' => 'fixture-store', 'corvus_secret_key' => 'fixture-corvus-secret'],
        ]);
        PaymentMethod::query()->create([
            'code' => 'wspay', 'name' => 'WSPay', 'provider' => 'wspay', 'is_active' => true,
            'settings' => ['wspay_mode' => 'live', 'wspay_shop_id' => 'fixture-shop', 'wspay_secret_key' => 'fixture-wspay-secret'],
        ]);
        $settings = app(FiraSettingsService::class);
        $settings->save([...$settings->options(), 'document_type' => 'FISKALNI_RAČUN', 'send_enabled' => true,
            'point_of_sale_registered' => true, 'fira_sequence_confirmed' => true, 'auto_card_invoices_enabled' => true], 'fixture-fira-secret');
    }

    #[DataProvider('providers')]
    public function test_verified_live_card_callback_sends_one_fiscal_invoice_only_after_the_payment_commits(string $provider): void
    {
        $order = $this->order($provider);
        $transactionLevel = DB::transactionLevel();
        Http::fake([FiraSettingsService::ENDPOINT => function ($request) use ($order, $transactionLevel) {
            $this->assertSame($transactionLevel, DB::transactionLevel());
            $this->assertNotNull($order->fresh()->paid_at);
            $this->assertSame('FISKALNI_RAČUN', $request['invoiceType']);
            $this->assertSame('KARTICA', $request['paymentType']);
            $this->assertSame(30.0, $request['brutto']);

            return Http::response(['id' => 501, 'invoiceNumber' => '1-web-1', 'jir' => 'fixture-jir']);
        }]);

        DB::transaction(function () use ($order, $provider): void {
            $result = $this->signedPaymentCallback($order, $provider);
            $this->assertTrue($result['newly_paid']);
            $this->assertTrue($result['signature_valid']);
            Http::assertNothingSent();
            $this->assertDatabaseCount('lavaggio_fira_documents', 0);
        });

        Http::assertSentCount(1);
        $this->assertDatabaseHas('lavaggio_fira_documents', ['order_id' => $order->id, 'state' => 'created', 'document_type' => 'FISKALNI_RAČUN', 'has_jir' => true, 'requested_by' => null]);
        $this->assertFalse($this->signedPaymentCallback($order->fresh(), $provider)['newly_paid']);
        Http::assertSentCount(1);
    }

    #[DataProvider('providers')]
    public function test_rolled_back_payment_callback_does_not_send_or_leave_a_document_attempt(string $provider): void
    {
        $order = $this->order($provider);
        DB::beginTransaction();
        try {
            $this->assertTrue($this->signedPaymentCallback($order, $provider)['newly_paid']);
            Http::assertNothingSent();
        } finally {
            DB::rollBack();
        }

        $this->assertNull($order->fresh()->paid_at);
        $this->assertDatabaseCount('lavaggio_fira_documents', 0);
        Http::assertNothingSent();
    }

    #[DataProvider('providers')]
    public function test_unsigned_declined_or_restocked_card_callbacks_never_create_a_fira_document(string $provider): void
    {
        $order = $this->order($provider);
        $this->assertFalse($this->signedPaymentCallback($order, $provider, 'invalid')['signature_valid']);
        $this->assertFalse($this->signedPaymentCallback($order->fresh(), $provider, 'declined')['newly_paid']);
        $payload = $order->fresh()->payload;
        data_set($payload, $provider.'.cancel_restocked_at', now()->toIso8601String());
        $order->update(['payload' => $payload]);
        $this->assertFalse($this->signedPaymentCallback($order->fresh(), $provider)['newly_paid']);

        $this->assertNull($order->fresh()->paid_at);
        $this->assertDatabaseCount('lavaggio_fira_documents', 0);
        Http::assertNothingSent();
    }

    #[DataProvider('nonLiveRequests')]
    public function test_test_card_payment_or_missing_payment_request_never_fiscalizes(string $provider, ?string $mode): void
    {
        $order = $this->order($provider);
        $payload = $order->payload;
        if ($mode === null) {
            unset($payload[$provider]['request']);
        } else {
            data_set($payload, $provider.'.request.mode', $mode);
        }
        $order->update(['payload' => $payload]);
        $this->assertTrue($this->signedPaymentCallback($order, $provider)['newly_paid']);

        $this->assertNotNull($order->fresh()->paid_at);
        $this->assertDatabaseCount('lavaggio_fira_documents', 0);
        Http::assertNothingSent();
    }

    #[DataProvider('providers')]
    public function test_fira_failure_preserves_successful_card_payment_and_is_not_retried_on_callback_replay(string $provider): void
    {
        $order = $this->order($provider);
        Http::fake([FiraSettingsService::ENDPOINT => Http::response('fixture-fira-secret', 500)]);

        $this->assertTrue($this->signedPaymentCallback($order, $provider)['paid']);

        $this->assertNotNull($order->fresh()->paid_at);
        $this->assertDatabaseHas('lavaggio_fira_documents', ['order_id' => $order->id, 'state' => 'uncertain']);
        $this->signedPaymentCallback($order->fresh(), $provider);
        app(FiraInvoiceService::class)->issueAfterCardPayment($order->id, $provider);
        Http::assertSentCount(1);
    }

    #[DataProvider('providers')]
    public function test_fira_timeout_cannot_fail_committed_payment_or_retry_the_document(string $provider): void
    {
        $order = $this->order($provider);
        $attempts = 0;
        Http::fake(function () use (&$attempts) {
            $attempts++;
            throw new \Illuminate\Http\Client\ConnectionException('fixture-fira-secret');
        });

        $this->assertTrue($this->signedPaymentCallback($order, $provider)['paid']);

        $this->assertNotNull($order->fresh()->paid_at);
        $this->assertDatabaseHas('lavaggio_fira_documents', ['order_id' => $order->id, 'state' => 'uncertain']);
        $this->signedPaymentCallback($order->fresh(), $provider);
        app(FiraInvoiceService::class)->issueAfterCardPayment($order->id, $provider);
        $this->assertSame(1, $attempts);
    }

    #[DataProvider('providers')]
    public function test_order_amount_changed_after_payment_form_preparation_requires_manual_invoice_review(string $provider): void
    {
        $order = $this->order($provider);
        // The customer was sent to the gateway for 30 EUR. The later order snapshot is internally
        // consistent at 35 EUR, so only comparison with the actual payment request catches this.
        $order->items()->sole()->update(['unit_price' => 12, 'line_total' => 24, 'tax_amount' => 6]);
        $order->update(['subtotal' => 24, 'tax_total' => 7, 'grand_total' => 35]);
        $this->assertSame(35.0, app(FiraOrderPayload::class)->build($order, 'KARTICA')['brutto']);

        $this->assertTrue($this->signedPaymentCallback($order, $provider)['paid']);

        $this->assertDatabaseCount('lavaggio_fira_documents', 0);
        Http::assertNothingSent();
    }

    #[DataProvider('providers')]
    public function test_payment_request_for_another_order_cannot_trigger_automatic_invoice(string $provider): void
    {
        $order = $this->order($provider);
        $payload = $order->payload;
        data_set($payload, $provider.'.request.'.($provider === 'corvuspay' ? 'order_number' : 'shopping_cart_id'), 'ANOTHER-ORDER');
        $order->update(['payload' => $payload]);

        $this->assertTrue($this->signedPaymentCallback($order, $provider)['paid']);

        $this->assertDatabaseCount('lavaggio_fira_documents', 0);
        Http::assertNothingSent();
    }

    public function test_corvus_preauthorization_never_marks_paid_or_automatically_issues_a_fiscal_invoice(): void
    {
        $method = PaymentMethod::where('code', 'corvus')->sole();
        $method->update(['settings' => [...$method->settings, 'corvus_require_complete' => 'true']]);
        $order = $this->order('corvuspay');
        $result = $this->signedPaymentCallback($order, 'corvuspay');

        $this->assertSame('preauthorized', $result['status']);
        $this->assertFalse($result['paid']);
        $this->assertFalse($result['newly_paid']);
        $this->assertNull($order->fresh()->paid_at);
        app(FiraInvoiceService::class)->issueAfterCardPayment($order->id, 'corvuspay');
        $this->assertDatabaseCount('lavaggio_fira_documents', 0);
        Http::assertNothingSent();
    }

    public function test_corvus_payment_request_in_another_currency_cannot_trigger_automatic_invoice(): void
    {
        $order = $this->order('corvuspay');
        $payload = $order->payload;
        data_set($payload, 'corvuspay.request.currency', 'USD');
        $order->update(['payload' => $payload]);

        $this->assertTrue($this->signedPaymentCallback($order, 'corvuspay')['paid']);

        $this->assertDatabaseCount('lavaggio_fira_documents', 0);
        Http::assertNothingSent();
    }

    #[DataProvider('providers')]
    public function test_existing_manual_document_blocks_automatic_document_creation(string $provider): void
    {
        $order = $this->order($provider);
        Http::fake([FiraSettingsService::ENDPOINT => Http::response(['id' => 502, 'invoiceNumber' => 'P-2'])]);
        $payload = app(FiraOrderPayload::class)->build($order, 'KARTICA');
        app(FiraInvoiceService::class)->issue($order->id, 'KARTICA', User::factory()->create()->id, hash('sha256', json_encode($payload, JSON_THROW_ON_ERROR)));

        $this->assertTrue($this->signedPaymentCallback($order->fresh(), $provider)['newly_paid']);

        Http::assertSentCount(1);
        $this->assertDatabaseCount('lavaggio_fira_documents', 1);
    }

    public function test_bank_transfer_and_manually_marked_paid_order_remain_manual(): void
    {
        $card = $this->order('corvuspay');
        $bank = $this->order('wspay');
        $bank->update(['payment_method_code' => 'bank', 'payment_method_name' => 'Bankovna transakcija', 'paid_at' => now()]);
        $card->update(['paid_at' => now()]);

        app(FiraInvoiceService::class)->issueAfterCardPayment($bank->id, 'corvuspay');
        app(FiraInvoiceService::class)->issueAfterCardPayment($bank->id, 'wspay');
        $this->assertFalse($this->signedPaymentCallback($card->fresh(), 'corvuspay')['newly_paid']);

        $this->assertDatabaseCount('lavaggio_fira_documents', 0);
        Http::assertNothingSent();
    }

    public static function providers(): array
    {
        return ['CorvusPay' => ['corvuspay'], 'WSPay' => ['wspay']];
    }

    public static function nonLiveRequests(): array
    {
        return ['CorvusPay test' => ['corvuspay', 'test'], 'CorvusPay missing request' => ['corvuspay', null],
            'WSPay test' => ['wspay', 'test'], 'WSPay missing request' => ['wspay', null]];
    }

    private function order(string $provider): Order
    {
        $order = Order::query()->create([
            'order_number' => 'FIRA-CARD-'.(Order::query()->count() + 1),
            'status_id' => OrderStatus::query()->where('code', 'new')->sole()->id,
            'customer_name' => 'Ogledni Kupac', 'customer_email' => 'fixture@example.test',
            'billing_first_name' => 'Ogledni', 'billing_last_name' => 'Kupac',
            'billing_address_line_1' => 'Barban 70', 'billing_city' => 'Barban', 'billing_postal_code' => '52207', 'billing_country_code' => 'HR',
            'payment_method_code' => $provider === 'corvuspay' ? 'corvus' : 'wspay',
            'payment_method_name' => $provider === 'corvuspay' ? 'CorvusPay' : 'WSPay',
            'currency_code' => 'EUR', 'subtotal' => 20, 'shipping_total' => 4, 'tax_total' => 6, 'grand_total' => 30,
            'payload' => ['customer_type' => 'B2C', 'placed_from' => 'frontend_checkout', 'shipping' => ['tax_rate_percent' => 25, 'tax_total' => 1]],
        ]);
        $order->items()->create(['name' => 'Deterdžent', 'code' => 'DET-1', 'quantity' => 2, 'unit_price' => 10, 'line_total' => 20, 'tax_rate' => 25, 'tax_amount' => 5]);
        $form = $provider === 'corvuspay'
            ? app(CorvusPayFormService::class)->buildFormData($order)
            : app(WSPayFormService::class)->buildFormData($order);
        $this->assertNotNull($form);

        return $order->fresh();
    }

    private function signedPaymentCallback(Order $order, string $provider, string $kind = 'success'): array
    {
        $approval = $kind === 'declined' ? '' : 'APPROVED-'.$order->id;
        if ($provider === 'corvuspay') {
            $input = ['approval_code' => $approval, 'language' => 'hr', 'order_number' => $order->order_number];
            $input['signature'] = $kind === 'invalid' ? 'invalid' : hash_hmac('sha256', 'approval_code'.$approval.'languagehrorder_number'.$order->order_number, 'fixture-corvus-secret');

            return app(CorvusPayFormService::class)->handleCallback($order, $input, 'success');
        }
        $success = $kind === 'declined' ? '0' : '1';
        $input = ['ShoppingCartID' => $order->order_number, 'Success' => $success, 'ApprovalCode' => $approval, 'WsPayOrderId' => 'WSPAY-'.$order->id];
        $input['Signature'] = $kind === 'invalid' ? 'invalid' : hash('sha512', 'fixture-shop'.'fixture-wspay-secret'.$order->order_number.'fixture-wspay-secret'.$success.'fixture-wspay-secret'.$approval.'fixture-wspay-secret');

        return app(WSPayFormService::class)->handleCallback($order, $input, 'return');
    }
}
