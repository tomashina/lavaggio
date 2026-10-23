<?php

namespace App\Http\Controllers\Front;

use App\Http\Controllers\Controller;
use App\Models\Sales\GiftVoucher;
use App\Models\Sales\Order\Order;
use App\Models\User\UserAddress;
use App\Services\Front\AddressDirectoryService;
use App\Services\GiftVoucherDeliveryService;
use App\Services\GiftVoucherService;
use App\Services\Payments\BankTransferUpiService;
use App\Services\Payments\CorvusPayFormService;
use App\Services\Payments\KeksPayService;
use App\Services\Payments\WSPayFormService;
use App\Support\Currency;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class GiftVoucherController extends Controller
{
    public function create(Request $request, GiftVoucherService $giftVouchers): View|JsonResponse
    {
        abort_unless((bool) config('gift_vouchers.enabled', true), 404);
        $amounts = collect($giftVouchers->availableAmounts())->values();
        $selectedAmount = (int) $request->query('amount', old('amount', config('gift_vouchers.default_amount', 30)));
        if (! $amounts->contains($selectedAmount)) {
            $selectedAmount = (int) $amounts->first();
        }
        $paymentMethods = $giftVouchers->purchasePaymentMethods($selectedAmount);
        $paymentOptions = $paymentMethods->map(function ($method) use ($giftVouchers, $selectedAmount): array {
            $totals = $giftVouchers->purchaseTotals($selectedAmount, $method);

            return [
                'code' => (string) $method->code,
                'name' => (string) $method->name,
                'description' => (string) $method->description,
                'payment_fee_total' => (float) $totals['payment_fee_total'],
                'payment_fee_total_formatted' => Currency::format((float) $totals['payment_fee_total']),
                'grand_total_formatted' => Currency::format((float) $totals['grand_total']),
            ];
        })->values();
        if ($request->expectsJson()) {
            return response()->json(['payment_methods' => $paymentOptions, 'amount' => $selectedAmount, 'amount_formatted' => Currency::format($selectedAmount)])
                ->header('Cache-Control', 'private, no-store');
        }

        $user = $request->user();
        $user?->loadMissing(['profile', 'addresses']);
        $billing = $user?->addresses?->firstWhere('type', UserAddress::TYPE_BILLING);

        return view('front.desktop.gift-vouchers.create', [
            'amounts' => $amounts,
            'selectedAmount' => $selectedAmount,
            'paymentMethods' => $paymentMethods,
            'paymentOptions' => $paymentOptions,
            'countryOptions' => app(AddressDirectoryService::class)->countries('hr'),
            'defaults' => [
                'sender_name' => (string) ($user?->name ?? ''),
                'customer_first_name' => (string) ($billing?->first_name ?: $user?->profile?->first_name ?: ''),
                'customer_last_name' => (string) ($billing?->last_name ?: $user?->profile?->last_name ?: ''),
                'customer_email' => (string) ($user?->email ?? ''),
                'customer_phone' => (string) ($user?->profile?->phone ?? ''),
                'billing_address_line_1' => (string) ($billing?->address_line_1 ?? ''),
                'billing_postal_code' => (string) ($billing?->postal_code ?? ''),
                'billing_city' => (string) ($billing?->city ?? ''),
                'billing_country_code' => (string) ($billing?->country_code ?: 'HR'),
            ],
        ]);
    }

    public function store(Request $request, GiftVoucherService $giftVouchers, GiftVoucherDeliveryService $delivery): RedirectResponse
    {
        abort_unless((bool) config('gift_vouchers.enabled', true), 404);
        $validated = $request->validate([
            'amount' => ['required', 'integer', Rule::in($giftVouchers->availableAmounts())],
            'recipient_name' => ['required', 'string', 'max:191'],
            'recipient_email' => ['required', 'email:rfc', 'max:191'],
            'sender_name' => ['required', 'string', 'max:191'],
            'message' => ['nullable', 'string', 'max:1000'],
            'customer_first_name' => ['required', 'string', 'max:120'],
            'customer_last_name' => ['required', 'string', 'max:120'],
            'customer_email' => ['required', 'email:rfc', 'max:191'],
            'customer_phone' => ['required', 'string', 'max:25', 'regex:/^\+?[0-9][0-9\s\-\/]{5,24}$/'],
            'billing_address_line_1' => ['required', 'string', 'max:191'],
            'billing_postal_code' => ['required', 'string', 'max:32'],
            'billing_city' => ['required', 'string', 'max:120'],
            'billing_country_code' => ['required', 'string', 'size:2'],
            'payment_method_code' => ['required', 'string', 'max:60'],
            'accept_terms' => ['accepted'],
        ], [
            'required' => 'Ispunite ovo polje.',
            'email' => 'Unesite ispravnu e-mail adresu.',
            'max' => 'Unos je predugačak (najviše :max znakova).',
            'string' => 'Unesite ispravan tekst.',
            'amount.integer' => 'Odaberite iznos poklon bona.',
            'amount.in' => 'Odaberite iznos od 10 do 300 € u koracima od 10 €.',
            'customer_phone.regex' => 'Unesite ispravan broj telefona.',
            'billing_country_code.size' => 'Odaberite državu.',
            'accept_terms.accepted' => 'Za nastavak prihvatite uvjete kupnje.',
        ]);

        $order = $giftVouchers->purchase($validated, $request->user());
        $request->session()->put('front.checkout.last_order_id', (int) $order->id);
        $request->session()->put('front.checkout.last_order_number', (string) $order->order_number);
        $delivery->sendPurchaseConfirmation($order);

        $route = match (true) {
            app(WSPayFormService::class)->isWspayCode((string) $order->payment_method_code) => 'checkout.wspay.start',
            app(CorvusPayFormService::class)->isCorvusCode((string) $order->payment_method_code) => 'checkout.corvus.start',
            app(KeksPayService::class)->isKeksCode((string) $order->payment_method_code) => 'checkout.keks.start',
            default => 'checkout.success',
        };

        return redirect()->route($route, ['orderNumber' => $order->order_number])
            ->with('status', 'Narudžba poklon bona je zaprimljena. Bon šaljemo primatelju nakon potvrde uplate.');
    }

    public function purchaseStatus(Request $request, string $orderNumber): View
    {
        abort_unless((bool) config('gift_vouchers.enabled', true), 404);
        $order = Order::query()->where('order_number', $orderNumber)->with('status')->firstOrFail();
        abort_unless((bool) data_get($order->payload, 'gift_voucher_purchase'), 404);
        $voucher = GiftVoucher::query()->where('purchase_order_id', $order->id)->firstOrFail();
        $paymentUrl = null;
        if (! $order->paid_at && ! $order->status?->is_cancelled) {
            $route = match (true) {
                app(WSPayFormService::class)->isWspayCode((string) $order->payment_method_code) => 'checkout.wspay.start',
                app(CorvusPayFormService::class)->isCorvusCode((string) $order->payment_method_code) => 'checkout.corvus.start',
                app(KeksPayService::class)->isKeksCode((string) $order->payment_method_code) => 'checkout.keks.start',
                default => null,
            };
            if ($route) {
                $request->session()->put('front.checkout.last_order_id', (int) $order->id);
                $request->session()->put('front.checkout.last_order_number', (string) $order->order_number);
                $paymentUrl = route($route, ['orderNumber' => $order->order_number]);
            }
        }

        return view('front.desktop.gift-vouchers.purchase', [
            'order' => $order,
            'voucher' => $voucher,
            'paymentUrl' => $paymentUrl,
            'bankTransfer' => app(BankTransferUpiService::class)->ensureForOrder($order),
        ]);
    }
}
