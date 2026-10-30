<?php

namespace App\Services;

use App\Models\Sales\GiftVoucher;
use App\Models\Sales\GiftVoucherRedemption;
use App\Models\Sales\Order\Order;
use App\Models\Sales\Order\OrderHistory;
use App\Models\Sales\Order\OrderItem;
use App\Models\Sales\Order\OrderTotal;
use App\Models\Settings\Local\OrderStatus;
use App\Models\Settings\Local\PaymentMethod;
use App\Models\User;
use App\Services\Payments\BankTransferUpiService;
use App\Services\Payments\CorvusPayFormService;
use App\Services\Payments\WSPayFormService;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Session;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class GiftVoucherService
{
    public const PAYMENT_CODE = 'gift_voucher';

    public const SHIPPING_CODE = 'gift_voucher_email';

    private const SESSION_KEY = 'front.cart.gift_voucher_code';

    public function availableAmounts(): array
    {
        return range((int) config('gift_vouchers.min_amount', 10), (int) config('gift_vouchers.max_amount', 300), max(1, (int) config('gift_vouchers.amount_step', 10)));
    }

    public function normalizeCode(?string $code): string
    {
        return Str::upper(trim((string) $code));
    }

    public function currentCode(): string
    {
        return $this->normalizeCode(Session::get(self::SESSION_KEY, ''));
    }

    public function clearCode(): void
    {
        Session::forget(self::SESSION_KEY);
    }

    public function findByCode(?string $code): ?GiftVoucher
    {
        if (($code = $this->normalizeCode($code)) === '' || ! $this->enabled()) {
            return null;
        }

        return GiftVoucher::query()->where('code_hash', hash('sha256', $code))->first();
    }

    public function applyCode(string $code): bool
    {
        $voucher = $this->findByCode($code);
        if (! $voucher || ! $voucher->isUsable()) {
            return false;
        }
        Session::put(self::SESSION_KEY, $this->normalizeCode($code));

        return true;
    }

    /** @return array{voucher:?GiftVoucher,amount:float,remaining_total:float,balance:float} */
    public function quote(float $grossTotal, ?string $code = null): array
    {
        $total = $this->cents($grossTotal);
        $voucher = $this->findByCode($code ?? $this->currentCode());
        $balance = $voucher?->isUsable() ? $this->cents($voucher->balance) : 0;
        $amount = min($total, $balance);

        return ['voucher' => $balance > 0 ? $voucher : null, 'amount' => (float) ($amount / 100), 'remaining_total' => (float) (($total - $amount) / 100), 'balance' => (float) ($balance / 100)];
    }

    /** @return Collection<int, PaymentMethod> */
    public function purchasePaymentMethods(float $amount): Collection
    {
        if (! $this->enabled()) {
            return collect();
        }

        return PaymentMethod::query()->where('is_active', true)->whereIn('code', config('gift_vouchers.payment_codes', []))
            ->orderBy('sort_order')->orderBy('id')->get()
            ->filter(fn (PaymentMethod $method) => app(BankTransferUpiService::class)->canBeOffered($method))
            ->filter(fn (PaymentMethod $method) => config('storefront.home') !== 'watchline'
                || app(WSPayFormService::class)->isWspayCode((string) $method->code)
                || app(CorvusPayFormService::class)->isCorvusCode((string) $method->code))
            ->filter(fn (PaymentMethod $method) => app(CorvusPayFormService::class)->canBeOffered($method) && app(WSPayFormService::class)->canBeOffered($method))
            ->filter(fn (PaymentMethod $method) => ($method->min_subtotal === null || $amount >= (float) $method->min_subtotal) && ($method->max_subtotal === null || $amount <= (float) $method->max_subtotal))
            ->values();
    }

    /** @return array{amount:float,payment_fee_total:float,grand_total:float} */
    public function purchaseTotals(float $amount, PaymentMethod $method): array
    {
        $cents = $this->cents($amount);
        $fee = $method->fee_type === 'percent'
            ? (int) round($cents * max(0, (float) $method->fee_value) / 100)
            : $this->cents($method->fee_value ?? 0);

        return ['amount' => (float) ($cents / 100), 'payment_fee_total' => (float) ($fee / 100), 'grand_total' => (float) (($cents + $fee) / 100)];
    }

    public function purchase(array $data, ?User $user = null): Order
    {
        abort_unless($this->enabled(), 404);
        $data = $this->validateVoucherData($data);
        $method = $this->purchasePaymentMethods((float) $data['amount'])->firstWhere('code', (string) ($data['payment_method_code'] ?? ''));
        if (! $method) {
            throw ValidationException::withMessages(['payment_method_code' => 'Odaberite dostupan način plaćanja poklon bona.']);
        }
        $amount = $this->cents($data['amount']);
        $fee = $this->cents($this->purchaseTotals((float) $data['amount'], $method)['payment_fee_total']);

        return DB::transaction(function () use ($data, $user, $method, $amount, $fee): Order {
            $status = OrderStatus::query()->where('is_active', true)->where('is_paid', false)->where('is_cancelled', false)->orderByDesc('is_default')->orderBy('sort_order')->first();
            $first = trim((string) ($data['customer_first_name'] ?? ''));
            $last = trim((string) ($data['customer_last_name'] ?? ''));
            $buyer = trim($first.' '.$last) ?: trim((string) ($data['buyer_name'] ?? $user?->name ?? $data['sender_name']));
            $buyerEmail = Str::lower(trim((string) ($data['customer_email'] ?? $data['buyer_email'] ?? $user?->email ?? '')));
            if (! filter_var($buyerEmail, FILTER_VALIDATE_EMAIL)) {
                throw ValidationException::withMessages(['customer_email' => 'Upišite ispravnu e-mail adresu kupca.']);
            }
            $order = Order::query()->create([
                'order_number' => 'LM-BON-'.now()->format('Ymd').'-'.Str::upper(Str::random(10)),
                'status_id' => $status?->id, 'user_id' => $user?->id, 'source' => 'web', 'locale' => 'hr', 'currency_code' => 'EUR', 'currency_rate' => 1,
                'customer_name' => $buyer, 'customer_email' => $buyerEmail, 'customer_phone' => (string) ($data['customer_phone'] ?? ''),
                'billing_first_name' => $first, 'billing_last_name' => $last,
                'billing_address_line_1' => $data['billing_address_line_1'] ?? null, 'billing_postal_code' => $data['billing_postal_code'] ?? null,
                'billing_city' => $data['billing_city'] ?? null, 'billing_country_code' => $data['billing_country_code'] ?? 'HR',
                'payment_method_code' => $method->code, 'payment_method_name' => $method->name,
                'shipping_method_code' => self::SHIPPING_CODE, 'shipping_method_name' => 'Dostava poklon bona e-mailom',
                'item_qty' => 1, 'subtotal' => $amount / 100, 'shipping_total' => 0, 'tax_total' => 0, 'discount_total' => 0,
                'payment_fee_total' => $fee / 100, 'grand_total' => ($amount + $fee) / 100,
                'payload' => ['gift_voucher_purchase' => true, 'customer_type' => 'B2C', 'placed_from' => 'gift_voucher_checkout'],
                'placed_at' => now(), 'created_by' => $user?->id, 'updated_by' => $user?->id,
            ]);
            OrderItem::query()->create(['order_id' => $order->id, 'product_id' => null, 'sku' => 'POKLON-BON', 'code' => 'POKLON-BON', 'name' => 'Poklon bon – '.number_format($amount / 100, 2, ',', '.').' €', 'unit_price' => $amount / 100, 'quantity' => 1, 'line_total' => $amount / 100, 'tax_amount' => 0, 'tax_rate' => 0, 'payload' => ['item_type' => 'gift_voucher']]);
            foreach ([['subtotal', 'Međuzbroj', $amount / 100, 100], ['payment_fee', 'Naknada plaćanja', $fee / 100, 300], ['grand_total', 'Ukupno', ($amount + $fee) / 100, 900]] as [$code, $title, $value, $sort]) {
                OrderTotal::query()->create(['order_id' => $order->id, 'code' => $code, 'title' => $title, 'value' => $value, 'sort_order' => $sort]);
            }
            OrderHistory::query()->create(['order_id' => $order->id, 'to_status_id' => $status?->id, 'changed_by' => $user?->id, 'comment' => 'Kupnja digitalnog poklon bona. Bon se izdaje nakon potvrde plaćanja.']);
            GiftVoucher::query()->create($this->voucherAttributes($data) + ['purchase_order_id' => $order->id, 'cart_item_key' => 'gift-voucher', 'buyer_name' => $buyer, 'buyer_email' => $buyerEmail, 'status' => GiftVoucher::STATUS_PENDING]);

            return $order;
        });
    }

    public function confirmPurchasePayment(Order $order): Order
    {
        return DB::transaction(function () use ($order): Order {
            $order = Order::query()->with('status')->lockForUpdate()->findOrFail($order->id);
            if (! data_get($order->payload, 'gift_voucher_purchase') || $order->status?->is_cancelled) {
                throw ValidationException::withMessages(['payment' => 'Plaćanje se može potvrditi samo za valjanu kupnju poklon bona.']);
            }
            if ($order->paid_at) {
                return $order;
            }
            $before = $order->status_id;
            $order->forceFill(['paid_at' => now(), 'updated_by' => auth()->id()])->save();
            OrderHistory::query()->create(['order_id' => $order->id, 'from_status_id' => $before, 'to_status_id' => $order->status_id, 'changed_by' => auth()->id(), 'comment' => 'Administrator je potvrdio uplatu za digitalni poklon bon.', 'payload' => ['origin' => 'gift_voucher_admin']]);
            activity('orders')->performedOn($order)->causedBy(auth()->user())->event('payment_confirmed')->withProperties(['admin_id' => auth()->id(), 'origin' => 'gift_voucher_admin'])->log('Administrator je potvrdio uplatu poklon bona.');
            $this->syncOrder($order);

            return $order->fresh();
        });
    }

    public function createManual(array $data): GiftVoucher
    {
        $data = $this->validateVoucherData($data);

        return DB::transaction(function () use ($data): GiftVoucher {
            $voucher = new GiftVoucher($this->voucherAttributes($data) + ['buyer_name' => $data['buyer_name'] ?? null, 'buyer_email' => $data['buyer_email'] ?? null, 'status' => GiftVoucher::STATUS_ACTIVE, 'issued_at' => now(), 'expires_at' => $data['expires_at'] ?? null]);
            $voucher->storeCode($this->newCode());
            $voucher->save();

            return $voucher;
        });
    }

    public function reserveForOrder(Order $order, string $code, float $amount): GiftVoucherRedemption
    {
        return DB::transaction(function () use ($order, $code, $amount): GiftVoucherRedemption {
            $order = Order::query()->lockForUpdate()->findOrFail($order->id);
            $requested = $this->cents($amount);
            if (! $this->enabled() || $requested <= 0 || data_get($order->payload, 'gift_voucher_purchase') || $requested > $this->cents(data_get($order->payload, 'original_total', $order->grand_total))) {
                throw $this->unavailable();
            }
            $existing = GiftVoucherRedemption::query()->where('order_id', $order->id)->lockForUpdate()->first();
            $voucher = GiftVoucher::query()->where('code_hash', hash('sha256', $this->normalizeCode($code)))->lockForUpdate()->first();
            if (! $voucher) {
                throw $this->unavailable();
            }
            if ($existing && in_array($existing->status, [GiftVoucherRedemption::STATUS_RESERVED, GiftVoucherRedemption::STATUS_REDEEMED], true)) {
                if ((int) $existing->gift_voucher_id !== (int) $voucher->id || $requested !== $this->cents($existing->amount)) {
                    throw $this->unavailable();
                }

                return $existing;
            }
            if (! $voucher->isUsable()) {
                throw $this->unavailable();
            }
            $available = $this->cents($voucher->balance);
            if ($requested > $available) {
                throw ValidationException::withMessages(['gift_voucher_code' => 'Raspoloživi iznos bona se promijenio. Ponovno provjerite narudžbu.']);
            }
            $voucher->balance = ($available - $requested) / 100;
            $voucher->save();

            return GiftVoucherRedemption::query()->updateOrCreate(['order_id' => $order->id], ['gift_voucher_id' => $voucher->id, 'amount' => $requested / 100, 'status' => GiftVoucherRedemption::STATUS_RESERVED, 'reserved_until' => now()->addMinutes((int) config('gift_vouchers.reservation_minutes', 180)), 'redeemed_at' => null, 'released_at' => null, 'release_reason' => null]);
        }, 3);
    }

    public function syncOrder(Order $order): void
    {
        if (! Schema::hasTable('gift_vouchers')) {
            return;
        }
        DB::transaction(function () use ($order): void {
            $order = Order::query()->with('status')->lockForUpdate()->find($order->id);
            if (! $order) {
                return;
            }
            $cancelled = (bool) $order->status?->is_cancelled;
            $redemption = GiftVoucherRedemption::query()->where('order_id', $order->id)->lockForUpdate()->first();
            if ($cancelled) {
                if ($redemption?->status === GiftVoucherRedemption::STATUS_RESERVED) {
                    $this->releaseLocked($redemption, 'order_cancelled');
                }
                GiftVoucher::query()->where('purchase_order_id', $order->id)->where('status', '!=', GiftVoucher::STATUS_CANCELLED)->update(['status' => GiftVoucher::STATUS_CANCELLED, 'cancelled_at' => now(), 'updated_at' => now()]);

                return;
            }
            if (! $order->paid_at) {
                return;
            }
            if ($redemption && $redemption->status !== GiftVoucherRedemption::STATUS_REDEEMED) {
                $voucher = GiftVoucher::query()->lockForUpdate()->findOrFail($redemption->gift_voucher_id);
                if ($redemption->status === GiftVoucherRedemption::STATUS_RELEASED) {
                    if (! $voucher->isUsable() || $this->cents($voucher->balance) < $this->cents($redemption->amount)) {
                        // A verified gateway charge is evidence of money received. Preserve it
                        // and flag the missing voucher tender instead of rolling payment back.
                        $this->recordSettlement($order, $redemption, 'attention', $this->cents($voucher->balance) < $this->cents($redemption->amount) ? 'insufficient_balance' : 'voucher_unavailable');

                        return;
                    }
                    $voucher->balance = ($this->cents($voucher->balance) - $this->cents($redemption->amount)) / 100;
                }
                $redemption->forceFill(['status' => GiftVoucherRedemption::STATUS_REDEEMED, 'redeemed_at' => now(), 'released_at' => null, 'release_reason' => null])->save();
                if ($voucher->status === GiftVoucher::STATUS_ACTIVE && $this->cents($voucher->balance) === 0) {
                    $voucher->status = GiftVoucher::STATUS_EXHAUSTED;
                }
                $voucher->save();
                $this->recordSettlement($order, $redemption, 'settled');
            }
            foreach (GiftVoucher::query()->where('purchase_order_id', $order->id)->lockForUpdate()->get() as $voucher) {
                if ($voucher->status === GiftVoucher::STATUS_CANCELLED) {
                    continue;
                }
                if (! $voucher->issued_at) {
                    $voucher->storeCode($this->newCode());
                    $voucher->forceFill(['issued_at' => now(), 'status' => GiftVoucher::STATUS_ACTIVE])->save();
                }
                if (! $voucher->email_sent_at && $voucher->status === GiftVoucher::STATUS_ACTIVE) {
                    $id = $voucher->id;
                    DB::afterCommit(static function () use ($id): void {
                        $voucher = GiftVoucher::query()->find($id);
                        if ($voucher && class_exists(GiftVoucherDeliveryService::class)) {
                            app(GiftVoucherDeliveryService::class)->send($voucher);
                        }
                    });
                }
            }
        }, 3);
    }

    private function recordSettlement(Order $order, GiftVoucherRedemption $redemption, string $status, ?string $reason = null): void
    {
        $payload = is_array($order->payload) ? $order->payload : [];
        $existing = (array) ($payload['gift_voucher_settlement'] ?? []);
        if (($existing['status'] ?? null) === $status
            && ($existing['reason'] ?? null) === $reason
            && (int) ($existing['redemption_id'] ?? 0) === (int) $redemption->id) {
            return;
        }
        $amount = (float) ($this->cents($redemption->amount) / 100);
        $message = $status === 'attention'
            ? 'Uplata je potvrđena, ali nakon isteka rezervacije poklon bon više ne pokriva iznos od '.number_format($amount, 2, ',', '.').' €. Potreban je ručni pregled prije isporuke.'
            : 'Pokriće poklon bonom uspješno je potvrđeno.';
        $payload['gift_voucher_settlement'] = [
            'status' => $status,
            'amount' => $amount,
            'reason' => $reason,
            'message' => $message,
            'redemption_id' => $redemption->id,
            ($status === 'attention' ? 'attention_at' : 'settled_at') => now()->toIso8601String(),
        ];
        $order->forceFill(['payload' => $payload])->save();
        if ($status === 'attention' || ($existing['status'] ?? null) === 'attention') {
            OrderHistory::query()->create([
                'order_id' => $order->id,
                'from_status_id' => $order->status_id,
                'to_status_id' => $order->status_id,
                'changed_by' => auth()->id(),
                'comment' => $message,
                'payload' => ['origin' => 'gift_voucher_settlement', 'status' => $status, 'amount' => $amount, 'reason' => $reason],
            ]);
        }
    }

    public function releaseExpiredReservations(): int
    {
        if (! Schema::hasTable('gift_voucher_redemptions')) {
            return 0;
        }
        $count = 0;
        GiftVoucherRedemption::query()->where('status', GiftVoucherRedemption::STATUS_RESERVED)->where('reserved_until', '<', now())->orderBy('id')->eachById(function (GiftVoucherRedemption $row) use (&$count): void {
            DB::transaction(function () use ($row, &$count): void {
                $order = Order::query()->lockForUpdate()->find($row->order_id);
                if ($order?->paid_at) {
                    return;
                }
                $locked = GiftVoucherRedemption::query()->lockForUpdate()->find($row->id);
                if ($locked?->status === GiftVoucherRedemption::STATUS_RESERVED && $locked->reserved_until?->isPast()) {
                    $this->releaseLocked($locked, 'reservation_expired');
                    $count++;
                }
            }, 3);
        });

        return $count;
    }

    public function setEnabled(GiftVoucher $voucher, bool $enabled): GiftVoucher
    {
        return DB::transaction(function () use ($voucher, $enabled): GiftVoucher {
            $voucher = GiftVoucher::query()->lockForUpdate()->findOrFail($voucher->id);
            if (! $voucher->issued_at || in_array($voucher->status, [GiftVoucher::STATUS_PENDING, GiftVoucher::STATUS_CANCELLED], true)) {
                throw $this->unavailable();
            }
            $voucher->status = $enabled ? ($this->cents($voucher->balance) > 0 ? GiftVoucher::STATUS_ACTIVE : GiftVoucher::STATUS_EXHAUSTED) : GiftVoucher::STATUS_DISABLED;
            $voucher->disabled_at = $enabled ? null : now();
            $voucher->save();

            return $voucher;
        });
    }

    public function internalPaymentMethod(): PaymentMethod
    {
        return new PaymentMethod(['code' => self::PAYMENT_CODE, 'name' => 'Poklon bon', 'provider' => self::PAYMENT_CODE, 'fee_type' => 'fixed', 'fee_value' => 0, 'is_active' => true]);
    }

    private function validateVoucherData(array $data): array
    {
        $data['recipient_email'] = Str::lower(trim((string) ($data['recipient_email'] ?? '')));

        return Validator::make($data, ['amount' => ['required', 'integer', Rule::in($this->availableAmounts())], 'recipient_name' => ['required', 'string', 'max:191'], 'recipient_email' => ['required', 'email:rfc', 'max:191'], 'sender_name' => ['required', 'string', 'max:191'], 'message' => ['nullable', 'string', 'max:1000'], 'expires_at' => ['nullable', 'date', 'after:today']], ['amount.in' => 'Odaberite ponuđeni iznos poklon bona.', 'recipient_name.required' => 'Upišite ime primatelja.', 'recipient_email.email' => 'Upišite ispravnu e-mail adresu primatelja.', 'sender_name.required' => 'Upišite ime pošiljatelja.'])->validate() + $data;
    }

    private function voucherAttributes(array $data): array
    {
        $amount = $this->cents($data['amount']) / 100;

        return ['initial_amount' => $amount, 'balance' => $amount, 'currency' => 'EUR', 'recipient_name' => trim($data['recipient_name']), 'recipient_email' => Str::lower(trim($data['recipient_email'])), 'sender_name' => trim($data['sender_name']), 'message' => trim((string) ($data['message'] ?? '')), 'locale' => 'hr'];
    }

    private function releaseLocked(GiftVoucherRedemption $redemption, string $reason): void
    {
        $voucher = GiftVoucher::query()->lockForUpdate()->findOrFail($redemption->gift_voucher_id);
        $voucher->balance = ($this->cents($voucher->balance) + $this->cents($redemption->amount)) / 100;
        if ($voucher->status === GiftVoucher::STATUS_EXHAUSTED) {
            $voucher->status = GiftVoucher::STATUS_ACTIVE;
        }
        $voucher->save();
        $redemption->forceFill(['status' => GiftVoucherRedemption::STATUS_RELEASED, 'released_at' => now(), 'release_reason' => $reason])->save();
    }

    private function enabled(): bool
    {
        return (bool) config('gift_vouchers.enabled', true) && Schema::hasTable('gift_vouchers');
    }

    private function cents(mixed $amount): int
    {
        return max(0, (int) round((float) $amount * 100));
    }

    private function unavailable(): ValidationException
    {
        return ValidationException::withMessages(['gift_voucher_code' => 'Poklon bon nije dostupan ili nema dovoljan raspoloživi iznos.']);
    }

    private function newCode(): string
    {
        do {
            $code = (string) config('gift_vouchers.code_prefix', 'LIBER-').Str::upper(Str::random((int) config('gift_vouchers.code_length', 16)));
        } while (GiftVoucher::query()->where('code_hash', hash('sha256', $code))->exists());

        return $code;
    }
}
