<?php

namespace Tests\Feature\Shipping;

use App\Models\Sales\Order\Order;
use App\Models\User;
use App\Services\Settings\SystemSettingsService;
use App\Services\Shipping\GlsAddressLabelPdf;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use Silber\Bouncer\BouncerFacade as Bouncer;
use Tests\TestCase;

class GlsAddressLabelTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        $this->admin = User::factory()->create();
        Bouncer::assign('admin')->to($this->admin);
        app(SystemSettingsService::class)->putMany([
            'gls_api_enabled' => false, 'gls_api_pickup_name' => 'Trgovina Žić',
            'gls_api_pickup_street' => 'Skladišna 10', 'gls_api_pickup_postal_code' => '10000',
            'gls_api_pickup_city' => 'Zagreb', 'gls_api_pickup_country_code' => 'HR',
            'gls_api_pickup_contact_phone' => '+385 1 000 0000',
        ]);
    }

    #[DataProvider('services')]
    public function test_pdf_is_one_100_by_150_mm_page_without_api_or_order_mutations(string $method): void
    {
        $order = $this->order($method);
        $before = $order->fresh()->getAttributes();
        $response = $this->actingAs($this->admin)->get(route('admin.orders.gls.address-label', $order))
            ->assertOk()->assertHeader('Content-Type', 'application/pdf')
            ->assertHeader('Content-Disposition', 'inline; filename="adresna-naljepnica-label-001-100x150.pdf"');
        $pdf = $response->getContent();
        $this->assertStringStartsWith('%PDF-', $pdf);
        preg_match('/\/MediaBox\s*\[\s*0(?:\.0+)?\s+0(?:\.0+)?\s+([\d.]+)\s+([\d.]+)/', $pdf, $size);
        $this->assertEqualsWithDelta(100, (float) $size[1] * 25.4 / 72, 0.01);
        $this->assertEqualsWithDelta(150, (float) $size[2] * 25.4 / 72, 0.01);
        $this->assertSame(1, preg_match_all('/\/Type\s*\/Page\b/', $pdf));
        $this->assertStringContainsString('private', $response->headers->get('Cache-Control'));
        $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
        $this->assertSame($before, $order->fresh()->getAttributes());
        $this->assertSame(0, $order->history()->count());
        $this->assertSame(0, $order->transactions()->count());
        Http::assertNothingSent();
    }

    public static function services(): array
    {
        return [['gls_home'], ['gls_locker']];
    }

    public function test_locker_label_uses_saved_point_instead_of_customer_home_or_billing_address(): void
    {
        $order = $this->order('gls_locker');
        $data = app(GlsAddressLabelPdf::class)->data($order);
        $html = view('admin.orders.address-label', $data + ['fontSize' => 10])->render();
        foreach (['Ana Šimić', 'GLS Paketomat 1401', 'HR10000-PARCELLOCK12', 'Paketomatska 79', 'Trgovina Žić'] as $text) {
            $this->assertStringContainsString($text, $html);
        }
        $this->assertStringNotContainsString('Kućna 15', $html);
        $this->assertStringNotContainsString('Računska 20', $html);
        $this->assertSame('Paketomatska 79', $data['destination']['street']);

        $order->shipping_method_code = 'gls_home';
        $data = app(GlsAddressLabelPdf::class)->data($order);
        $this->assertFalse($data['isLocker']);
        $this->assertSame('Kućna 15', $data['destination']['street']);
    }

    public function test_missing_locker_address_is_blocked_without_home_address_fallback(): void
    {
        $order = $this->order('gls_locker');
        $order->update(['payload' => ['shipping' => ['gls_dpm' => ['id' => 'ONLY-ID']]]]);
        $this->actingAs($this->admin)->get(route('admin.orders.gls.address-label', $order))
            ->assertRedirect(route('admin.orders.show', $order))->assertSessionHas('notify.type', 'error');
        Http::assertNothingSent();
    }

    public function test_store_pickup_label_identifies_the_customer_without_shipping_or_billing_addresses(): void
    {
        config(['storefront.home' => 'lavaggio']);
        $order = $this->order('pup');
        $order->update(['shipping_method_name' => 'Preuzimanje u Barbanu']);
        $url = route('admin.orders.pickup-label', $order);
        $this->get($url)->assertRedirect();
        $this->actingAs(User::factory()->create())->get($url)->assertForbidden();
        $this->actingAs($this->admin)->get(route('admin.orders.show', $order))->assertOk()
            ->assertSee('Etiketa za preuzimanje')->assertSee($url, false);
        $before = $order->fresh()->getAttributes();
        $response = $this->get($url)->assertOk()->assertHeader('Content-Type', 'application/pdf');
        $this->assertSame(1, preg_match_all('/\/Type\s*\/Page\b/', $response->getContent()));
        $this->assertMatchesRegularExpression('/\/MediaBox\s*\[\s*0(?:\.0+)?\s+0(?:\.0+)?\s+283\.46\d*\s+425\.19\d*/', $response->getContent());
        $html = view('admin.orders.pickup-label', ['order' => $order, 'fontSize' => 10])->render();
        foreach (['Ana Šimić', 'Telefon kupca', '+385 91 000 0000', 'LABEL-001', 'Preuzimanje u Barbanu'] as $value) {
            $this->assertStringContainsString($value, $html);
        }
        $this->assertStringNotContainsString('Kućna 15', $html);
        $this->assertSame($before, $order->fresh()->getAttributes());
        $order->update(['shipping_method_code' => 'gls_home']);
        $this->get($url)->assertNotFound();
        $order->update(['shipping_method_code' => 'custom-pickup', 'customer_phone' => null, 'payload' => ['shipping' => ['service_type' => 'pickup']]]);
        $this->get($url)->assertOk();
        $this->view('admin.orders.pickup-label', ['order' => $order, 'fontSize' => 10])->assertDontSee('Telefon kupca');
        Http::assertNothingSent();
    }

    public function test_sender_comes_from_admin_and_incomplete_pickup_address_is_not_mixed_with_store_address(): void
    {
        $settings = app(SystemSettingsService::class);
        $settings->putMany(['gls_api_pickup_name' => 'Novo skladište', 'gls_api_pickup_city' => 'Varaždin']);
        $this->assertSame('Novo skladište', app(GlsAddressLabelPdf::class)->data($this->order('gls_home'))['sender']['name']);
        $settings->putMany(['gls_api_pickup_postal_code' => '', 'store_schema_address_postal_code' => '99999']);
        $order = $this->order('gls_home', 'LABEL-002');
        $this->actingAs($this->admin)->get(route('admin.orders.gls.address-label', $order))
            ->assertRedirect(route('admin.orders.show', $order))->assertSessionHas('notify.type', 'error');
        $settings->putMany([
            'gls_api_pickup_street' => '', 'gls_api_pickup_city' => '',
            'store_schema_business_name' => 'Trgovina iz postavki', 'store_schema_address_street' => 'Poslovna 5',
            'store_schema_address_city' => 'Split', 'store_schema_address_postal_code' => '21000', 'store_schema_address_country' => 'HR',
        ]);
        $sender = app(GlsAddressLabelPdf::class)->data($order)['sender'];
        $this->assertSame('Trgovina iz postavki', $sender['name']);
        $this->assertSame('Poslovna 5', $sender['street']);
        $this->assertSame('Split', $sender['city']);
    }

    public function test_admin_button_and_access_restrictions_match_order_permissions(): void
    {
        $order = $this->order('gls_home');
        $url = route('admin.orders.gls.address-label', $order);
        $this->get($url)->assertRedirect();
        $this->actingAs(User::factory()->create())->get($url)->assertForbidden();
        $this->actingAs($this->admin)->get(route('admin.orders.show', $order))->assertOk()
            ->assertSee('PDF za paket 10 × 15 cm')->assertSee($url, false);
        $order->update(['shipping_method_code' => 'boxnow']);
        $this->get($url)->assertNotFound();

        $order->update(['shipping_method_code' => 'gls_locker']);
        Bouncer::allow('order_manager')->to(['admin.access', 'sales.orders.view']);
        $manager = User::factory()->create(['account_type' => 'staff']);
        Bouncer::assign('order_manager')->to($manager);
        $this->actingAs($manager)->get($url)->assertForbidden();
        $customer = User::factory()->create(['account_type' => 'customer']);
        $order->update(['user_id' => $customer->id]);
        DB::table('admin_customer_assignments')->insert(['admin_user_id' => $manager->id, 'customer_user_id' => $customer->id, 'created_at' => now(), 'updated_at' => now()]);
        $this->get($url)->assertOk()->assertHeader('Content-Type', 'application/pdf');
        Http::assertNothingSent();
    }

    private function order(string $method, string $number = 'LABEL-001'): Order
    {
        return Order::create([
            'order_number' => $number, 'customer_name' => 'Kupac za račun', 'customer_email' => 'primatelj@example.test',
            'customer_phone' => '+385 91 000 0000', 'shipping_first_name' => 'Ana', 'shipping_last_name' => 'Šimić',
            'shipping_address_line_1' => 'Kućna 15', 'shipping_postal_code' => '10000', 'shipping_city' => 'Zagreb', 'shipping_country_code' => 'HR',
            'billing_address_line_1' => 'Računska 20', 'shipping_method_code' => $method, 'shipping_method_name' => 'GLS dostava',
            'grand_total' => 20.89, 'placed_at' => now(),
            'payload' => ['shipping' => ['gls_dpm' => ['id' => 'HR10000-PARCELLOCK12', 'name' => 'GLS Paketomat 1401',
                'address_line_1' => 'Paketomatska 79', 'postal_code' => '10020', 'city' => 'Zagreb']]],
        ]);
    }
}
