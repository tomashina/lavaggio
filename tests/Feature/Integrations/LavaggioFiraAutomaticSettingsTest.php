<?php

namespace Tests\Feature\Integrations;

use App\Livewire\Admin\Integrations\Fira\Dashboard;
use App\Models\Sales\Order\Order;
use App\Models\User;
use App\Services\Integrations\Fira\FiraSettingsService;
use App\Services\Settings\SystemSettingsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use Silber\Bouncer\BouncerFacade as Bouncer;
use Tests\TestCase;

class LavaggioFiraAutomaticSettingsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['storefront.home' => 'lavaggio']);
        Http::preventStrayRequests();
    }

    public function test_automatic_card_invoices_default_to_off_for_new_and_existing_settings(): void
    {
        $settings = app(FiraSettingsService::class);
        $this->assertFalse($settings->autoCardInvoicesEnabled());
        $legacy = $this->fiscalOptions();
        unset($legacy['auto_card_invoices_enabled']);
        app(SystemSettingsService::class)->put('lavaggio_fira', $legacy);
        $this->assertFalse($settings->options()['auto_card_invoices_enabled']);
        $this->assertFalse($settings->autoCardInvoicesEnabled());
        Http::assertNothingSent();
    }

    public function test_fully_configured_fiscal_mode_can_save_the_opt_in_without_sending_historical_orders(): void
    {
        Order::query()->create([
            'order_number' => 'FIRA-HISTORICAL-CARD', 'customer_name' => 'Test kupac',
            'customer_email' => 'fixture@example.test', 'payment_method_code' => 'corvus',
            'payment_method_name' => 'Kartica', 'paid_at' => now()->subDay(), 'grand_total' => 25,
        ]);
        $settings = app(FiraSettingsService::class);
        $settings->save($this->fiscalOptions(), 'fixture-secret-key');
        $this->assertTrue($settings->autoCardInvoicesEnabled());
        $this->assertSame([], $settings->blockers());
        $this->assertDatabaseCount('lavaggio_fira_documents', 0);
        Http::assertNothingSent();
    }

    #[DataProvider('invalidAutomaticConfigurations')]
    public function test_automatic_fiscalization_cannot_bypass_required_settings(array $override): void
    {
        $settings = app(FiraSettingsService::class);
        try {
            $settings->save([...$this->fiscalOptions(), ...$override], 'fixture-secret-key');
            $this->fail('Automatic invoices require the complete fiscal setup.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('form.auto_card_invoices_enabled', $exception->errors());
        }
        $this->assertFalse($settings->autoCardInvoicesEnabled());
        Http::assertNothingSent();
    }

    public static function invalidAutomaticConfigurations(): array
    {
        return [
            'offer mode' => [['document_type' => 'PONUDA']],
            'sending disabled' => [['send_enabled' => false]],
            'point of sale not registered' => [['point_of_sale_registered' => false]],
            'fiscal setup not confirmed' => [['fira_sequence_confirmed' => false]],
        ];
    }

    public function test_older_callers_omitting_the_option_cannot_leave_automatic_issuance_enabled(): void
    {
        $settings = app(FiraSettingsService::class);
        $settings->save($this->fiscalOptions());
        $legacy = $settings->options();
        unset($legacy['auto_card_invoices_enabled']);
        $settings->save($legacy);
        $this->assertFalse($settings->autoCardInvoicesEnabled());
        Http::assertNothingSent();
    }

    public function test_the_dashboard_saves_the_checkbox_and_explains_bank_transfers_remain_manual(): void
    {
        $admin = User::factory()->create();
        Bouncer::assign('superadmin')->to($admin);
        Livewire::actingAs($admin)->test(Dashboard::class)
            ->assertSet('form.auto_card_invoices_enabled', false)
            ->assertSee('Automatski izradi račun nakon potvrđene kartične uplate')
            ->assertSee('Ponude i bankovne transakcije ostaju ručne.')
            ->set('form.document_type', 'FISKALNI_RAČUN')
            ->set('form.point_of_sale_registered', true)
            ->set('form.fira_sequence_confirmed', true)
            ->set('form.send_enabled', true)
            ->set('form.auto_card_invoices_enabled', true)
            ->set('secret', 'fixture-secret-key')
            ->call('save')->assertHasNoErrors()
            ->assertSet('form.auto_card_invoices_enabled', true)->assertSet('secret', '');
        $this->assertTrue(app(FiraSettingsService::class)->autoCardInvoicesEnabled());
        Http::assertNothingSent();
    }

    public function test_offer_mode_can_still_be_enabled_manually_without_fiscal_registration(): void
    {
        $settings = app(FiraSettingsService::class);
        $settings->save([...$settings->options(), 'send_enabled' => true], 'fixture-secret-key');
        $this->assertSame('PONUDA', $settings->options()['document_type']);
        $this->assertFalse($settings->autoCardInvoicesEnabled());
        $this->assertSame([], $settings->blockers());
        Http::assertNothingSent();
    }

    private function fiscalOptions(): array
    {
        return [
            ...app(FiraSettingsService::class)->options(),
            'document_type' => 'FISKALNI_RAČUN',
            'point_of_sale_registered' => true,
            'fira_sequence_confirmed' => true,
            'send_enabled' => true,
            'auto_card_invoices_enabled' => true,
        ];
    }
}
