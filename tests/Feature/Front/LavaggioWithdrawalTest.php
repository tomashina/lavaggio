<?php

namespace Tests\Feature\Front;

use App\Mail\ContractWithdrawalAdminMail;
use App\Mail\ContractWithdrawalReceiptMail;
use App\Models\Sales\ContractWithdrawal;
use App\Services\Front\StoreSettingsService;
use App\Services\Settings\SystemSettingsService;
use DOMDocument;
use DOMXPath;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class LavaggioWithdrawalTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'app.locale' => 'hr', 'app.fallback_locale' => 'hr',
            'storefront.home' => 'lavaggio', 'storefront.theme' => 'herrera',
            'commerce.b2c_only' => true, 'commerce.b2b_only' => false,
            'mail.default' => 'smtp',
        ]);
        app(SystemSettingsService::class)->putMany([
            'store_withdrawal_admin_email' => 'returns@example.test',
            'store_withdrawal_return_address' => 'Bareda d.o.o., Barban 70, 52207 Barban',
        ]);
    }

    public function test_lavaggio_form_requires_only_identity_email_and_order(): void
    {
        $response = $this->get('/forma-za-povrat-i-reklamacije')->assertOk();
        $dom = new DOMDocument;
        @$dom->loadHTML($response->getContent());
        $xpath = new DOMXPath($dom);
        $required = $xpath->query('//form[@data-withdrawal-form]//*[@required]/@name');

        $this->assertSame(['full_name', 'email', 'order_number'], array_map(
            static fn ($node) => $node->nodeValue, iterator_to_array($required),
        ));
        $this->assertSame(0, $xpath->query('//form[@data-withdrawal-form]//*[@name="address_line"]')->length);
        $this->assertSame(0, $xpath->query('//form[@data-withdrawal-form]//*[@name="note"]')->length);
    }

    public function test_minimal_request_is_reviewed_then_stored_with_receipts_for_both_parties(): void
    {
        Mail::fake();
        $review = $this->post('/forma-za-povrat-i-reklamacije', $this->minimalData())
            ->assertOk()->assertSee('Potvrditi raskid ugovora')->assertSee('Cijela narudžba');

        $this->assertDatabaseCount('contract_withdrawals', 0);
        Mail::assertNothingSent();
        $this->post('/forma-za-povrat-i-reklamacije/confirm', ['draft_token' => $this->draftToken($review->getContent())])
            ->assertRedirect('/forma-za-povrat-i-reklamacije')
            ->assertSessionMissing('warning');

        $withdrawal = ContractWithdrawal::query()->sole();
        $this->assertSame('Cijela narudžba', $withdrawal->items);
        $this->assertSame('', $withdrawal->address_line);
        $this->assertNotNull($withdrawal->submitted_at);
        $this->assertNotNull($withdrawal->consumer_notified_at);
        $this->assertNotNull($withdrawal->admin_notified_at);
        $this->assertSame($withdrawal->items, $withdrawal->request_snapshot['data']['items']);
        Mail::assertSent(ContractWithdrawalReceiptMail::class, static fn ($mail) => $mail->hasTo('ana@example.test'));
        Mail::assertSent(ContractWithdrawalAdminMail::class, static fn ($mail) => $mail->hasTo('returns@example.test'));
        $receipt = new ContractWithdrawalReceiptMail($withdrawal, 'Lavaggio');
        $this->assertStringContainsString('Cijela narudžba', $receipt->render());
    }

    public function test_partial_withdrawal_accepts_delivery_date_without_contract_date(): void
    {
        Mail::fake();
        $review = $this->post('/forma-za-povrat-i-reklamacije', $this->minimalData() + [
            'items' => 'Omekšivač 1 komad',
            'received_date' => now()->subDays(3)->toDateString(),
        ])->assertOk()->assertSee('Omekšivač 1 komad');

        $this->post('/forma-za-povrat-i-reklamacije/confirm', ['draft_token' => $this->draftToken($review->getContent())])
            ->assertRedirect();
        $withdrawal = ContractWithdrawal::query()->sole();
        $this->assertSame('Omekšivač 1 komad', $withdrawal->items);
        $this->assertSame(now()->subDays(3)->toDateString(), $withdrawal->received_date->toDateString());
        $this->assertNull($withdrawal->contract_date);
    }

    #[DataProvider('nonDeliveringMailers')]
    public function test_preview_mailers_do_not_claim_an_email_was_sent(string $mailer): void
    {
        config(['mail.default' => $mailer]);
        Mail::fake();
        $review = $this->post('/forma-za-povrat-i-reklamacije', $this->minimalData())->assertOk();
        $result = $this->post('/forma-za-povrat-i-reklamacije/confirm', ['draft_token' => $this->draftToken($review->getContent())])
            ->assertRedirect()->assertSessionHas('warning');

        $withdrawal = ContractWithdrawal::query()->sole();
        $result->assertSessionHas('status', __('return_request.received_status', ['reference' => $withdrawal->reference]));
        $this->assertNull($withdrawal->consumer_notified_at);
        $this->assertNull($withdrawal->admin_notified_at);
        Mail::assertNothingSent();
    }

    public static function nonDeliveringMailers(): array
    {
        return [['log'], ['array']];
    }

    public function test_other_storefronts_keep_existing_required_fields(): void
    {
        config(['storefront.home' => 'watchline']);
        $this->post('/forma-za-povrat-i-reklamacije', $this->minimalData())
            ->assertSessionHasErrors(['address_line', 'postal_code', 'city', 'country_code', 'items']);
        $this->assertDatabaseCount('contract_withdrawals', 0);
    }

    public function test_inherited_return_details_use_lavaggio_contact_but_custom_details_are_preserved(): void
    {
        $settings = app(SystemSettingsService::class);
        $settings->putMany([
            'store_footer_address' => "Bareda d.o.o.\nBarban 70\n52207 Barban",
            'store_footer_email_support' => 'info@lavaggio.hr',
            'store_withdrawal_admin_email' => 'webshop@termol.hr',
            'store_withdrawal_return_address' => 'TERMOL d.o.o., Lapovačka 11A, 32100 Vinkovci, Hrvatska',
        ]);
        $resolved = app(StoreSettingsService::class)->withdrawal();
        $this->assertSame('info@lavaggio.hr', $resolved['admin_email']);
        $this->assertStringContainsString('Barban 70', $resolved['return_address']);

        $settings->putMany([
            'store_withdrawal_admin_email' => 'returns@example.test',
            'store_withdrawal_return_address' => 'Posebno skladište za povrate',
        ]);
        $resolved = app(StoreSettingsService::class)->withdrawal();
        $this->assertSame('returns@example.test', $resolved['admin_email']);
        $this->assertSame('Posebno skladište za povrate', $resolved['return_address']);
    }

    private function minimalData(): array
    {
        return ['full_name' => 'Ana Horvat', 'email' => 'ana@example.test', 'order_number' => 'L-1001'];
    }

    private function draftToken(string $html): string
    {
        preg_match('/name="draft_token" value="([^"]+)"/', $html, $matches);
        $this->assertNotEmpty($matches[1] ?? null);

        return $matches[1];
    }
}
