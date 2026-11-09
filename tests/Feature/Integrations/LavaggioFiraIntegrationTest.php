<?php

namespace Tests\Feature\Integrations;

use App\Livewire\Admin\Integrations\Fira\Dashboard;
use App\Livewire\Admin\Integrations\Fira\OrderPanel;
use App\Models\Sales\Order\Order;
use App\Models\Settings\Local\OrderStatus;
use App\Models\Settings\System\SystemSetting;
use App\Models\User;
use App\Services\Integrations\Fira\FiraInvoiceService;
use App\Services\Integrations\Fira\FiraOrderPayload;
use App\Services\Integrations\Fira\FiraSettingsService;
use App\Services\Settings\SystemSettingsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Silber\Bouncer\BouncerFacade as Bouncer;
use Tests\TestCase;

class LavaggioFiraIntegrationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['storefront.home' => 'lavaggio', 'commerce.b2c_only' => true, 'commerce.b2b_only' => false]);
        Http::preventStrayRequests();
    }

    public function test_default_configuration_never_sends_and_saving_a_secret_does_not_activate_it(): void
    {
        $settings = app(FiraSettingsService::class);
        $this->assertFalse($settings->options()['send_enabled']);
        $this->assertSame('PONUDA', $settings->options()['document_type']);
        $settings->save($settings->options(), 'fixture-secret-key');
        $stored = SystemSetting::query()->where('key', 'lavaggio_fira_secret')->value('value');
        $this->assertStringNotContainsString('fixture-secret-key', $stored);
        $this->assertSame('fixture-secret-key', $settings->secret());
        $settings->save($settings->options());
        $this->assertSame('fixture-secret-key', $settings->secret());
        $order = $this->order();
        try {
            app(FiraInvoiceService::class)->issue($order->id, 'KARTICA', $this->admin()->id, $this->hash($order));
            $this->fail('Disabled configuration must block the request.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('fira', $exception->errors());
        }
        $this->assertDatabaseCount('lavaggio_fira_documents', 0);
        Http::assertNothingSent();
    }

    public function test_registration_and_sequence_confirmation_are_required_before_activation(): void
    {
        $settings = app(FiraSettingsService::class);
        $this->expectException(ValidationException::class);
        $settings->save([...$settings->options(), 'document_type' => 'FISKALNI_RAČUN', 'send_enabled' => true], 'fixture-secret-key');
    }

    public function test_offer_can_be_enabled_without_fiscal_registration_and_manual_action_sends_only_ponuda(): void
    {
        $settings = app(FiraSettingsService::class);
        $settings->save([...$settings->options(), 'send_enabled' => true], 'fixture-secret-key');
        $this->assertFalse($settings->options()['point_of_sale_registered']);
        $this->assertFalse($settings->options()['fira_sequence_confirmed']);
        $this->assertSame([], $settings->blockers());
        Http::fake([FiraSettingsService::ENDPOINT => Http::response(['id' => 411, 'invoiceNumber' => 'P-1', 'invoiceType' => 'PONUDA'])]);
        $order = $this->order();
        $order->update(['paid_at' => now()]);
        Livewire::actingAs($this->admin())->test(OrderPanel::class, ['orderId' => $order->id])
            ->set('paymentType', 'TRANSAKCIJSKI')->call('previewInvoice')
            ->assertSee('Izradi ponudu u FIRA-i')->assertDontSee('Izradi fiskalni račun u FIRA-i')
            ->set('confirmed', true)->call('issueInvoice')->assertHasNoErrors()
            ->assertSee('FIRA ponuda')->assertSee('P-1')->assertSee('Napravi račun iz ponude')
            ->assertDontSee('JIR nije potvrđen');
        Http::assertSent(fn ($request) => $request['invoiceType'] === 'PONUDA'
            && $request['webshopType'] === 'CUSTOM'
            && $request->hasHeader('FIRA-Api-Key', 'fixture-secret-key')
            && ! isset($request['prepaidAmount']));
        Http::assertSentCount(1);
        $this->assertDatabaseHas('lavaggio_fira_documents', ['order_id' => $order->id, 'document_type' => 'PONUDA', 'state' => 'created', 'has_jir' => false]);
    }

    public function test_existing_offer_blocks_a_second_document_after_switching_to_fiscal_mode(): void
    {
        $settings = app(FiraSettingsService::class);
        $settings->save([...$settings->options(), 'send_enabled' => true], 'fixture-secret-key');
        Http::fake([FiraSettingsService::ENDPOINT => Http::response(['id' => 412, 'invoiceNumber' => 'P-2', 'invoiceType' => 'PONUDA'])]);
        $order = $this->order();
        $admin = $this->admin();
        $invoices = app(FiraInvoiceService::class);
        $invoices->issue($order->id, 'KARTICA', $admin->id, $this->hash($order));
        $this->enable();
        try {
            $invoices->issue($order->id, 'KARTICA', $admin->id, $this->hash($order));
            $this->fail('The offer must be converted in FIRA rather than creating a second document.');
        } catch (ValidationException $exception) {
            $this->assertStringContainsString('Ponudu po potrebi pretvorite', $exception->getMessage());
        }
        Http::assertSentCount(1);
        $this->assertDatabaseCount('lavaggio_fira_documents', 1);
    }

    public function test_switching_document_type_invalidates_an_old_offer_preview_before_sending(): void
    {
        $settings = app(FiraSettingsService::class);
        $settings->save([...$settings->options(), 'send_enabled' => true], 'fixture-secret-key');
        $order = $this->order();
        $admin = $this->admin();
        $preview = Livewire::actingAs($admin)->test(OrderPanel::class, ['orderId' => $order->id])
            ->set('paymentType', 'KARTICA')->call('previewInvoice')->set('confirmed', true);
        $this->enable();
        $preview->call('issueInvoice')->assertHasErrors('fira')
            ->assertDontSee('Izradi fiskalni račun u FIRA-i')
            ->assertSee('Ponovno otvorite pregled');
        Http::assertNothingSent();
        $this->assertDatabaseCount('lavaggio_fira_documents', 0);
    }

    public function test_offer_enable_switch_cannot_bypass_fiscal_registration_guard(): void
    {
        $settings = app(FiraSettingsService::class);
        $settings->save([...$settings->options(), 'send_enabled' => true], 'fixture-secret-key');
        app(SystemSettingsService::class)->put('lavaggio_fira', [...$settings->options(), 'document_type' => 'FISKALNI_RAČUN']);
        $order = $this->order();
        try {
            app(FiraInvoiceService::class)->issue($order->id, 'KARTICA', $this->admin()->id, $this->hash($order));
            $this->fail('Fiscal registration guard must also run before transport.');
        } catch (ValidationException $exception) {
            $this->assertStringContainsString('Prodajno mjesto', $exception->getMessage());
        }
        Http::assertNothingSent();
        $this->assertDatabaseCount('lavaggio_fira_documents', 0);
    }

    public function test_failed_new_preview_clears_the_previous_review_and_confirmation(): void
    {
        $order = $this->order();
        $panel = Livewire::actingAs($this->admin())->test(OrderPanel::class, ['orderId' => $order->id])
            ->set('paymentType', 'KARTICA')->call('previewInvoice')->set('confirmed', true);
        $order->update(['payment_fee_total' => 1]);
        $panel->call('previewInvoice')->assertHasErrors('fira')->assertSet('preview', null)->assertSet('confirmed', false);
        Http::assertNothingSent();
    }

    public function test_settings_never_hydrate_the_secret_and_unauthorized_access_is_rejected(): void
    {
        $settings = app(FiraSettingsService::class);
        $settings->save($settings->options(), 'fixture-secret-key');
        Livewire::actingAs($this->admin())->test(Dashboard::class)
            ->assertSet('secret', '')->assertDontSee('fixture-secret-key');
        Livewire::actingAs(User::factory()->create())->test(Dashboard::class)->assertForbidden();
        Http::assertNothingSent();
    }

    public function test_preview_uses_discounted_snapshot_net_prices_and_taxable_shipping_without_sending(): void
    {
        $order = $this->order();
        $payload = app(FiraOrderPayload::class)->build($order, 'KARTICA');
        $this->assertSame('PONUDA', $payload['invoiceType']);
        $this->assertFalse($payload['b2bClient']);
        $this->assertFalse($payload['taxesIncluded']);
        $this->assertSame(80.0, $payload['lineItems'][0]['price']);
        $this->assertSame(0.25, $payload['lineItems'][0]['taxRate']);
        $this->assertSame(4.0, $payload['lineItems'][1]['price']);
        $this->assertSame(164.0, $payload['netto']);
        $this->assertSame(41.0, $payload['taxValue']);
        $this->assertSame(205.0, $payload['brutto']);
        Livewire::actingAs($this->admin())->test(OrderPanel::class, ['orderId' => $order->id])
            ->set('paymentType', 'KARTICA')->call('previewInvoice')->assertHasNoErrors()
            ->assertSee('205,00')->assertDontSee('Izradi fiskalni račun u FIRA-i');
        Http::assertNothingSent();
    }

    public function test_manual_issue_uses_documented_header_and_records_fiscalization_only_when_jir_exists(): void
    {
        $this->enable();
        Http::fake([FiraSettingsService::ENDPOINT => Http::response([
            'id' => 321, 'invoiceNumber' => '1-web-1', 'jir' => null,
            'invoiceFiscalPaymentStatus' => 'NEUSPJEŠNO_FISKALIZIRANO',
        ])]);
        $order = $this->order();
        app(FiraInvoiceService::class)->issue($order->id, 'KARTICA', $this->admin()->id, $this->hash($order));
        Http::assertSent(fn ($request) => $request->url() === FiraSettingsService::ENDPOINT
            && $request->hasHeader('FIRA-Api-Key', 'fixture-secret-key')
            && $request['webshopOrderId'] === $order->id && $request['brutto'] === 205.0);
        Http::assertSentCount(1);
        $this->assertDatabaseHas('lavaggio_fira_documents', ['order_id' => $order->id, 'state' => 'created', 'invoice_id' => '321', 'has_jir' => false]);
        try {
            app(FiraInvoiceService::class)->issue($order->id, 'KARTICA', $this->admin()->id, $this->hash($order));
            $this->fail('A duplicate issue must be blocked.');
        } catch (ValidationException) {
            Http::assertSentCount(1);
        }
    }

    public function test_timeout_and_uncertain_response_are_not_retried_or_exposed(): void
    {
        $this->enable();
        Http::fake(fn () => throw new \Illuminate\Http\Client\ConnectionException('fixture-secret-key'));
        $order = $this->order();
        $admin = $this->admin();
        try {
            app(FiraInvoiceService::class)->issue($order->id, 'KARTICA', $admin->id, $this->hash($order));
            $this->fail('Timeout must remain uncertain.');
        } catch (ValidationException $exception) {
            $this->assertStringNotContainsString('fixture-secret-key', $exception->getMessage());
        }
        $this->assertDatabaseHas('lavaggio_fira_documents', ['order_id' => $order->id, 'state' => 'uncertain']);
        $this->expectException(ValidationException::class);
        app(FiraInvoiceService::class)->issue($order->id, 'KARTICA', $admin->id, $this->hash($order));
    }

    public function test_paid_bank_transfer_snapshot_is_explicitly_marked_prepaid(): void
    {
        $this->enable();
        $order = $this->order();
        $order->update(['paid_at' => now()]);
        $builder = app(FiraOrderPayload::class);
        $this->assertSame(205.0, $builder->build($order, 'TRANSAKCIJSKI')['prepaidAmount']);
        $this->assertArrayNotHasKey('prepaidAmount', $builder->build($order, 'KARTICA'));
        Http::assertNothingSent();
    }

    public function test_jir_is_required_to_display_fiscalization_as_confirmed(): void
    {
        $this->enable();
        Http::fake([FiraSettingsService::ENDPOINT => Http::response([
            'id' => 322, 'invoiceNumber' => '2-web-1', 'jir' => 'fixture-jir',
            'invoiceFiscalPaymentStatus' => 'FISKALIZIRANO',
        ])]);
        $order = $this->order();
        $admin = $this->admin();
        app(FiraInvoiceService::class)->issue($order->id, 'KARTICA', $admin->id, $this->hash($order));
        Livewire::actingAs($admin)->test(OrderPanel::class, ['orderId' => $order->id])
            ->assertSee('Fiskalizacija potvrđena (JIR zaprimljen).')
            ->assertDontSee('Izradi fiskalni račun u FIRA-i');
        Http::assertSentCount(1);
    }

    public function test_success_response_without_invoice_id_is_uncertain_and_does_not_leak_response_body(): void
    {
        $this->enable();
        Http::fake([FiraSettingsService::ENDPOINT => Http::response(['error' => 'fixture-secret-key'])]);
        $order = $this->order();
        try {
            app(FiraInvoiceService::class)->issue($order->id, 'KARTICA', $this->admin()->id, $this->hash($order));
            $this->fail('An unconfirmed response must not be marked as created.');
        } catch (ValidationException $exception) {
            $this->assertStringNotContainsString('fixture-secret-key', $exception->getMessage());
        }
        $this->assertDatabaseHas('lavaggio_fira_documents', ['order_id' => $order->id, 'state' => 'uncertain']);
        Http::assertSentCount(1);
    }

    public function test_local_environment_cannot_send_even_when_all_saved_switches_are_enabled(): void
    {
        $this->enable();
        $order = $this->order();
        $this->app['env'] = 'local';
        try {
            app(FiraInvoiceService::class)->issue($order->id, 'KARTICA', $this->admin()->id, $this->hash($order));
            $this->fail('Local use must remain read-only.');
        } catch (ValidationException) {
            Http::assertNothingSent();
            $this->assertDatabaseCount('lavaggio_fira_documents', 0);
        }
    }

    public function test_changed_snapshot_cannot_be_sent_using_an_old_review(): void
    {
        $this->enable();
        $order = $this->order();
        $hash = $this->hash($order);
        $order->update(['customer_name' => 'Changed customer']);
        try {
            app(FiraInvoiceService::class)->issue($order->id, 'KARTICA', $this->admin()->id, $hash);
            $this->fail('A changed payload must be reviewed again.');
        } catch (ValidationException) {
            Http::assertNothingSent();
            $this->assertDatabaseCount('lavaggio_fira_documents', 0);
        }
    }

    public function test_inconsistent_amounts_and_unsupported_fees_block_before_any_send(): void
    {
        $order = $this->order();
        $order->update(['payment_fee_total' => 1]);
        $this->expectException(ValidationException::class);
        app(FiraOrderPayload::class)->build($order, 'KARTICA');
    }

    public function test_order_panel_rejects_users_without_order_update_permission(): void
    {
        $order = $this->order();
        $user = User::factory()->create();
        Bouncer::allow($user)->to('admin.access');
        Bouncer::allow($user)->to('sales.orders.view');
        Livewire::actingAs($user)->test(OrderPanel::class, ['orderId' => $order->id])->assertForbidden();
        Http::assertNothingSent();
    }

    public function test_no_automatic_call_occurs_on_order_creation_or_status_update(): void
    {
        $this->enable();
        $order = $this->order();
        $order->update(['paid_at' => now()]);
        Http::assertNothingSent();
        $this->assertDatabaseCount('lavaggio_fira_documents', 0);
    }

    private function hash(Order $order): string
    {
        return hash('sha256', json_encode(app(FiraOrderPayload::class)->build($order, 'KARTICA'), JSON_THROW_ON_ERROR));
    }

    private function enable(): void
    {
        $settings = app(FiraSettingsService::class);
        $settings->save([...$settings->options(), 'document_type' => 'FISKALNI_RAČUN', 'point_of_sale_registered' => true, 'fira_sequence_confirmed' => true, 'send_enabled' => true], 'fixture-secret-key');
    }

    private function admin(): User
    {
        $user = User::factory()->create();
        Bouncer::assign('superadmin')->to($user);

        return $user;
    }

    private function order(): Order
    {
        $status = OrderStatus::query()->firstOrCreate(['code' => 'pending'], ['name' => 'Na čekanju', 'is_active' => true]);
        $order = Order::query()->create([
            'order_number' => 'FIRA-'.(Order::query()->count() + 1), 'status_id' => $status->id,
            'customer_name' => 'Test kupac', 'customer_email' => 'fixture@example.test',
            'billing_address_line_1' => 'Ulica 1', 'billing_city' => 'Barban', 'billing_postal_code' => '52207', 'billing_country_code' => 'HR',
            'currency_code' => 'EUR', 'subtotal' => 200, 'discount_total' => 40, 'shipping_total' => 4,
            'tax_total' => 41, 'grand_total' => 205, 'payment_fee_total' => 0, 'payment_method_name' => 'Kartica',
            'payload' => ['customer_type' => 'B2C', 'placed_from' => 'frontend_checkout', 'shipping' => ['tax_total' => 1, 'tax_rate_percent' => 25]],
        ]);
        $order->items()->create(['name' => 'Deterdžent', 'code' => 'DET-1', 'unit_price' => 100, 'discount_amount' => 40, 'line_total' => 160,
            'quantity' => 2, 'tax_rate' => 25, 'tax_amount' => 40]);

        return $order;
    }
}
