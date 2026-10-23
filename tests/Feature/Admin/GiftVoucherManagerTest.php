<?php

namespace Tests\Feature\Admin;

use App\Livewire\Admin\Sales\GiftVoucher\Manager;
use App\Mail\GiftVoucherDelivered;
use App\Models\Sales\GiftVoucher;
use App\Models\Sales\GiftVoucherRedemption;
use App\Models\Sales\Order\Order;
use App\Models\Settings\Local\OrderStatus;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use Silber\Bouncer\BouncerFacade as Bouncer;
use Tests\TestCase;

class GiftVoucherManagerTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['gift_vouchers.enabled' => true, 'gift_vouchers.emails_enabled' => false]);
        Mail::fake();
    }

    public function test_admin_page_requires_login_and_order_read_permission(): void
    {
        $this->get(route('admin.gift-vouchers'))->assertRedirect(route('login'));
        $this->actingAs(User::factory()->create())->get(route('admin.gift-vouchers'))->assertForbidden();
        $this->actingAs($this->viewer())->get(route('admin.gift-vouchers'))
            ->assertOk()->assertSeeLivewire(Manager::class)->assertSee('Poklon bonovi');
        Mail::assertNothingSent();
    }

    public function test_viewer_can_read_masked_vouchers_but_does_not_get_full_code_or_actions(): void
    {
        $voucher = $this->voucher();
        $code = $voucher->code;
        Livewire::actingAs($this->viewer())->test(Manager::class)
            ->assertSee($voucher->recipient_email)->assertSee($voucher->code_suffix)
            ->assertDontSee($code)->assertDontSee('Izdaj poklon bon')->assertDontSee('Pošalji bon')
            ->call('detail', $voucher->id)->assertSee('Povijest korištenja')->assertDontSee($code)
            ->assertViewHas('canUpdate', false);
        $this->assertArrayNotHasKey('code_ciphertext', $voucher->toArray());
        $this->assertArrayNotHasKey('code_hash', $voucher->toArray());
        Mail::assertNothingSent();
    }

    #[DataProvider('mutatingMethods')]
    public function test_every_mutation_rechecks_order_update_permission(string $method): void
    {
        $voucher = $this->voucher();
        $component = Livewire::actingAs($this->viewer())->test(Manager::class);
        in_array($method, ['create', 'save'], true)
            ? $component->call($method)->assertForbidden()
            : $component->call($method, $voucher->id)->assertForbidden();
        $this->assertSame('active', $voucher->fresh()->status);
        $this->assertSame('50.00', $voucher->fresh()->balance);
        $this->assertDatabaseCount('gift_vouchers', 1);
        Mail::assertNothingSent();
    }

    public static function mutatingMethods(): array
    {
        return [['create'], ['save'], ['send'], ['toggle'], ['confirmPayment']];
    }

    public function test_a_revoked_permission_stops_an_existing_livewire_session(): void
    {
        $voucher = $this->voucher();
        $user = $this->editor();
        $component = Livewire::actingAs($user)->test(Manager::class)->assertViewHas('canUpdate', true);
        Bouncer::disallow($user)->to('sales.orders.update');
        Bouncer::refreshFor($user);
        $component->call('toggle', $voucher->id)->assertForbidden();
        $this->assertSame('active', $voucher->fresh()->status);
    }

    public function test_manual_issue_saves_a_valid_code_without_sending_when_mail_is_disabled(): void
    {
        Livewire::actingAs($this->editor())->test(Manager::class)->call('create')
            ->set('form', $this->validForm())->call('save')->assertHasNoErrors()
            ->assertSet('creating', false)
            ->assertDispatched('notify', fn ($event, $params): bool => $params['type'] === 'warning'
                && str_contains($params['message'], 'izdan'));
        $voucher = GiftVoucher::query()->sole();
        $this->assertSame('50.00', $voucher->initial_amount);
        $this->assertSame('50.00', $voucher->balance);
        $this->assertSame('active', $voucher->status);
        $this->assertSame('primatelj@example.test', $voucher->recipient_email);
        $this->assertNotNull($voucher->issued_at);
        $this->assertNotNull($voucher->code);
        $this->assertSame(hash('sha256', $voucher->code), $voucher->code_hash);
        $this->assertNull($voucher->email_sent_at);
        $this->assertNull($voucher->purchase_order_id);
        $this->assertDatabaseHas('gift_voucher_mail_deliveries', ['gift_voucher_id' => $voucher->id, 'kind' => 'voucher', 'status' => 'waiting']);
        Mail::assertNothingSent();
    }

    public function test_manual_issue_can_deliver_and_an_admin_can_explicitly_resend_the_same_code(): void
    {
        config(['gift_vouchers.emails_enabled' => true]);
        $component = Livewire::actingAs($this->editor())->test(Manager::class)->call('create')
            ->set('form', $this->validForm())->call('save')->assertHasNoErrors();
        $voucher = GiftVoucher::query()->sole();
        $code = $voucher->code;
        $this->assertNotNull($voucher->email_sent_at);
        Mail::assertSent(GiftVoucherDelivered::class, fn ($mail): bool => $mail->hasTo('primatelj@example.test') && $mail->voucher->code === $code);
        $component->call('send', $voucher->id)->assertHasNoErrors();
        Mail::assertSentCount(2);
        $this->assertSame($code, $voucher->fresh()->code);
        $this->assertSame('50.00', $voucher->fresh()->balance);
        $this->assertDatabaseCount('gift_vouchers', 1);
    }

    #[DataProvider('invalidManualData')]
    public function test_manual_issue_rejects_invalid_values(string $field, mixed $value): void
    {
        Livewire::actingAs($this->editor())->test(Manager::class)->call('create')
            ->set('form', array_replace($this->validForm(), [$field => $value]))
            ->call('save')->assertHasErrors('form.'.$field);
        $this->assertDatabaseCount('gift_vouchers', 0);
        Mail::assertNothingSent();
    }

    public static function invalidManualData(): array
    {
        return [
            ['amount', 15], ['amount', 0], ['amount', 310],
            ['recipient_email', 'invalid'], ['recipient_name', ''], ['sender_name', ''],
            ['buyer_email', 'invalid'], ['expires_at', '2000-01-01'], ['message', str_repeat('a', 1001)],
        ];
    }

    public function test_disabling_and_enabling_preserves_the_code_and_remaining_balance(): void
    {
        $voucher = $this->voucher(['balance' => 21]);
        $code = $voucher->code;
        $component = Livewire::actingAs($this->editor())->test(Manager::class);
        $component->call('toggle', $voucher->id)->assertHasNoErrors();
        $this->assertSame('disabled', $voucher->fresh()->status);
        $component->call('toggle', $voucher->id)->assertHasNoErrors();
        $this->assertSame('active', $voucher->fresh()->status);
        $this->assertSame($code, $voucher->fresh()->code);
        $this->assertSame('21.00', $voucher->fresh()->balance);
        $spent = $this->voucher(['status' => 'exhausted', 'balance' => 0]);
        $component->call('toggle', $spent->id)->assertHasErrors('voucher');
        $this->assertSame('exhausted', $spent->fresh()->status);
    }

    public function test_filters_search_code_email_and_purchase_order_and_show_global_statistics(): void
    {
        $active = $this->voucher(['recipient_email' => 'unique-recipient@example.test']);
        $order = $this->order(['order_number' => 'LM-BON-SEARCH']);
        $pending = $this->voucher(['status' => 'pending', 'issued_at' => null, 'purchase_order_id' => $order->id]);
        $failed = $this->voucher(['status' => 'disabled', 'email_error' => 'Slanje nije uspjelo.']);
        $sent = $this->voucher(['status' => 'exhausted', 'balance' => 0, 'email_sent_at' => now()]);
        Livewire::actingAs($this->editor())->test(Manager::class)
            ->assertViewHas('stats', fn ($stats): bool => $stats['total'] === 4 && $stats['active'] === 1 && $stats['pending'] === 1)
            ->set('search', 'unique-recipient')->assertViewHas('rows', fn ($rows): bool => $rows->pluck('id')->all() === [$active->id])
            ->set('search', strtolower($active->code))->assertViewHas('rows', fn ($rows): bool => $rows->pluck('id')->all() === [$active->id])
            ->set('search', 'LM-BON-SEARCH')->assertViewHas('rows', fn ($rows): bool => $rows->pluck('id')->all() === [$pending->id])
            ->call('clearFilters')->set('status', 'pending')->assertViewHas('rows', fn ($rows): bool => $rows->pluck('id')->all() === [$pending->id])
            ->call('clearFilters')->set('emailStatus', 'failed')->assertViewHas('rows', fn ($rows): bool => $rows->pluck('id')->all() === [$failed->id])
            ->call('clearFilters')->set('emailStatus', 'sent')->assertViewHas('rows', fn ($rows): bool => $rows->pluck('id')->all() === [$sent->id]);
    }

    public function test_detail_shows_redemption_history_and_full_code_only_to_an_editor(): void
    {
        $voucher = $this->voucher(['balance' => 35]);
        $order = $this->order(['order_number' => 'USED-VOUCHER-ORDER']);
        GiftVoucherRedemption::query()->create([
            'gift_voucher_id' => $voucher->id, 'order_id' => $order->id, 'amount' => 15,
            'status' => 'redeemed', 'redeemed_at' => now(),
        ]);
        Livewire::actingAs($this->editor())->test(Manager::class)->call('detail', $voucher->id)
            ->assertSee('Povijest korištenja')->assertSee('USED-VOUCHER-ORDER')
            ->assertSee('15,00')->assertSee('35,00')->assertSee('Iskorišteno')->assertSee($voucher->code);
    }

    public function test_payment_confirmation_issues_a_pending_purchase_once_with_history_and_keeps_delivery_unchanged(): void
    {
        $status = OrderStatus::query()->create(['code' => 'pending-gift', 'name' => 'Čeka uplatu', 'is_active' => true, 'is_paid' => false, 'is_cancelled' => false]);
        $order = $this->order(['status_id' => $status->id, 'payload' => ['gift_voucher_purchase' => true], 'shipping_method_code' => 'gift_voucher_email']);
        $voucher = $this->voucher(['purchase_order_id' => $order->id, 'status' => 'pending', 'issued_at' => null]);
        $admin = $this->editor();
        $component = Livewire::actingAs($admin)->test(Manager::class)->call('confirmPayment', $voucher->id)->assertHasNoErrors();
        $code = $voucher->fresh()->code;
        $this->assertSame('active', $voucher->fresh()->status);
        $this->assertNotNull($voucher->fresh()->issued_at);
        $this->assertNotNull($order->fresh()->paid_at);
        $this->assertSame($status->id, $order->fresh()->status_id);
        $this->assertSame('gift_voucher_email', $order->fresh()->shipping_method_code);
        $this->assertSame(1, $order->history()->count());
        $this->assertSame($admin->id, $order->history()->first()->changed_by);
        $component->call('confirmPayment', $voucher->id)->assertHasErrors('payment');
        $this->assertSame($code, $voucher->fresh()->code);
        $this->assertSame(1, $order->history()->count());
        Mail::assertNothingSent();
    }

    public function test_ordinary_orders_cannot_be_marked_paid_using_the_voucher_action_and_pending_bons_are_not_sent(): void
    {
        config(['gift_vouchers.emails_enabled' => true]);
        $order = $this->order();
        $voucher = $this->voucher(['purchase_order_id' => $order->id, 'status' => 'pending', 'issued_at' => null]);
        Livewire::actingAs($this->editor())->test(Manager::class)->call('confirmPayment', $voucher->id)
            ->assertHasErrors('payment')->call('send', $voucher->id);
        $this->assertNull($order->fresh()->paid_at);
        $this->assertSame(0, $order->history()->count());
        $this->assertSame('pending', $voucher->fresh()->status);
        Mail::assertNothingSent();
    }

    private function viewer(): User
    {
        $user = User::factory()->create();
        Bouncer::allow($user)->to(['admin.access', 'sales.orders.view']);

        return $user;
    }

    private function editor(): User
    {
        $user = $this->viewer();
        Bouncer::allow($user)->to('sales.orders.update');

        return $user;
    }

    private function validForm(): array
    {
        return ['amount' => 50, 'recipient_name' => 'Primatelj', 'recipient_email' => 'PRIMATELJ@example.test',
            'buyer_name' => 'Kupac', 'buyer_email' => 'kupac@example.test', 'sender_name' => 'Pošiljatelj',
            'message' => 'Sretan rođendan!', 'expires_at' => now()->addYear()->toDateString()];
    }

    private function voucher(array $attributes = []): GiftVoucher
    {
        $voucher = new GiftVoucher(array_replace([
            'initial_amount' => 50, 'balance' => 50, 'currency' => 'EUR',
            'recipient_name' => 'Primatelj', 'recipient_email' => Str::random(8).'@example.test',
            'sender_name' => 'Pošiljatelj', 'status' => 'active', 'issued_at' => now(), 'locale' => 'hr',
        ], $attributes));
        if ($voucher->issued_at) {
            $voucher->storeCode('LIBER-'.Str::upper(Str::random(16)));
        }
        $voucher->save();

        return $voucher;
    }

    private function order(array $attributes = []): Order
    {
        return Order::query()->create(array_replace([
            'order_number' => 'TEST-'.Str::upper(Str::random(10)), 'source' => 'web', 'locale' => 'hr',
            'currency_code' => 'EUR', 'currency_rate' => 1, 'customer_name' => 'Kupac',
            'customer_email' => 'kupac@example.test', 'grand_total' => 50, 'payload' => [],
        ], $attributes));
    }
}
