<?php

namespace App\Http\Controllers\Front;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Front\Concerns\ResolvesFrontendView;
use App\Models\Catalog\Product\Product;
use App\Models\Catalog\Product\ProductOptionValue;
use App\Services\Front\CartRecommendationService;
use App\Services\Front\CartService;
use App\Services\Front\CartStaffAccess;
use App\Services\GiftVoucherService;
use App\Services\Pricing\B2BAccessService;
use App\Support\Currency;
use App\Support\StorefrontTheme;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class CartController extends Controller
{
    use ResolvesFrontendView;

    public function __construct(
        private readonly CartService $cart
    ) {}

    public function index(Request $request): View
    {
        $lines = $this->cart->lines();
        $summary = $this->cart->summary();
        $giftVouchers = app(GiftVoucherService::class);
        $isLibermediaB2cCart = StorefrontTheme::usesLibermediaBrand() && app(B2BAccessService::class)->b2cEnabled();

        return view($this->frontendView($request, 'cart.index'), [
            'lines' => $lines,
            'summary' => $summary,
            'giftVoucherCode' => $giftVouchers->currentCode(),
            'giftVoucherQuote' => $giftVouchers->quote((float) ($summary['grand_total'] ?? 0)),
            'isLibermediaB2cCart' => $isLibermediaB2cCart,
            'cartRecommendations' => $isLibermediaB2cCart
                ? app(CartRecommendationService::class)->forLines($lines)
                : ['products' => collect(), 'source' => 'related'],
            'staffStockByLine' => $isLibermediaB2cCart ? [] : app(CartStaffAccess::class)->stockForLines($request, $lines),
        ]);
    }

    public function preview(): View
    {
        return view('front.desktop.partials.header-cart-popover-content', [
            'cartLines' => $this->cart->lines(),
            'cartSummary' => $this->cart->summary(),
        ]);
    }

    public function store(Request $request): JsonResponse|RedirectResponse
    {
        $validated = $request->validate([
            'product_id' => ['required', 'integer', 'exists:products,id'],
            'product_option_value_id' => ['nullable', 'integer', Rule::exists('catalog_product_option_values', 'id')],
            'quantity' => ['nullable', 'integer', 'min:1', 'max:999'],
        ]);

        $product = Product::query()
            ->where('is_active', true)
            ->findOrFail((int) $validated['product_id']);

        $optionValueId = isset($validated['product_option_value_id'])
            ? (int) $validated['product_option_value_id']
            : null;

        $hasVisibleOptions = $product->hasVisibleOptionRows();
        $hasAvailableOptions = $product->hasAvailableOptionRows();

        if ($hasVisibleOptions && ! $hasAvailableOptions) {
            $message = __('ui.cart.status.unavailable');

            if ($request->expectsJson() || $request->ajax()) {
                return response()->json([
                    'ok' => false,
                    'message' => $message,
                ], 422);
            }

            return back()->with('status', $message);
        }

        if ($hasAvailableOptions && ! $optionValueId) {
            if ($request->expectsJson() || $request->ajax()) {
                return response()->json([
                    'ok' => false,
                    'message' => __('ui.cart.errors.select_size'),
                    'errors' => ['product_option_value_id' => [__('ui.cart.errors.select_size')]],
                ], 422);
            }

            return back()
                ->withErrors(['product_option_value_id' => __('ui.cart.errors.select_size')])
                ->withInput();
        }

        if ($optionValueId) {
            $optionRow = ProductOptionValue::query()
                ->where('id', $optionValueId)
                ->where('product_id', $product->id)
                ->where('is_active', true)
                ->with([
                    'optionValue.option:id,is_active,payload',
                    'parentOptionValue.option:id,is_active,payload',
                ])
                ->first();
            if (! $optionRow || ! $optionRow->isVisibleOnStorefront()) {
                if ($request->expectsJson() || $request->ajax()) {
                    return response()->json([
                        'ok' => false,
                        'message' => __('ui.cart.errors.invalid_size'),
                        'errors' => ['product_option_value_id' => [__('ui.cart.errors.invalid_size')]],
                    ], 422);
                }

                return back()
                    ->withErrors(['product_option_value_id' => __('ui.cart.errors.invalid_size')])
                    ->withInput();
            }
        }

        $ok = $this->cart->add($product, (int) ($validated['quantity'] ?? 1), $optionValueId);

        $message = $ok ? __('ui.cart.status.added') : __('ui.cart.status.unavailable');
        if ($request->expectsJson() || $request->ajax()) {
            $access = app(B2BAccessService::class);
            $showModal = ! $access->requiresApprovedAccount()
                || (StorefrontTheme::usesLibermediaBrand() && $access->b2cEnabled());

            return response()->json([
                'ok' => $ok,
                'message' => $message,
                'summary' => $this->cart->summary(),
                'confirmation' => $showModal ? 'modal' : 'notice',
            ], $ok ? 200 : 422);
        }

        return $ok
            ? back()->with('status', $message)
            : back()->with('warning', $message);
    }

    public function update(Request $request, Product $product): RedirectResponse
    {
        $validated = $request->validate([
            'product_option_value_id' => ['nullable', 'integer', Rule::exists('catalog_product_option_values', 'id')],
            'quantity' => ['required', 'integer', 'min:0', 'max:999'],
        ]);

        $optionValueId = isset($validated['product_option_value_id'])
            ? (int) $validated['product_option_value_id']
            : null;
        $availableStock = $product->availableStockQuantity();
        if ($optionValueId !== null) {
            $availableStock = (int) (ProductOptionValue::query()
                ->where('id', $optionValueId)
                ->where('product_id', $product->id)
                ->where('is_active', true)
                ->value('stock_qty') ?? 0);
        }

        $ok = $this->cart->set(
            $product,
            (int) $validated['quantity'],
            $optionValueId
        );

        $quantityWasStockAdjusted = (int) $validated['quantity'] > $availableStock && $availableStock > 0;
        $message = $ok
            ? __('ui.cart.status.updated')
            : ($quantityWasStockAdjusted
                ? __('ui.cart.status.adjusted_with_stock', ['stock' => $availableStock])
                : __('ui.cart.status.adjusted'));

        return $ok
            ? back()->with('status', $message)
            : back()->with('warning', $message);
    }

    public function destroy(Request $request, Product $product): JsonResponse|RedirectResponse
    {
        $validated = $request->validate([
            'product_option_value_id' => ['nullable', 'integer', Rule::exists('catalog_product_option_values', 'id')],
        ]);

        $this->cart->remove(
            (int) $product->id,
            isset($validated['product_option_value_id']) ? (int) $validated['product_option_value_id'] : null
        );

        if ($request->expectsJson() || $request->ajax()) {
            return response()->json([
                'ok' => true,
                'message' => __('ui.cart.status.removed'),
                'summary' => $this->cart->summary(),
            ]);
        }

        return back()->with('status', __('ui.cart.status.removed'));
    }

    public function clear(): RedirectResponse
    {
        $this->cart->clear();
        app(GiftVoucherService::class)->clearCode();

        return redirect()->route('cart.index')->with('status', __('ui.cart.status.cleared'));
    }

    public function applyCoupon(Request $request): JsonResponse|RedirectResponse
    {
        $validated = $request->validate(
            [
                'coupon_code' => ['required', 'string', 'max:60'],
            ],
            [
                'coupon_code.required' => __('ui.cart.validation.coupon_required'),
                'coupon_code.string' => __('ui.cart.validation.coupon_string'),
                'coupon_code.max' => __('ui.cart.validation.coupon_max'),
            ]
        );

        $applied = $this->cart->applyCoupon((string) $validated['coupon_code']);
        $message = $applied ? __('ui.cart.status.coupon_applied') : __('ui.cart.status.coupon_invalid');
        if ($request->expectsJson() || $request->ajax()) {
            return response()->json([
                'ok' => $applied,
                'message' => $message,
                'coupon_code' => $this->cart->couponCode() ?: null,
                'summary' => $this->cart->summary(),
                ...($applied ? [] : ['errors' => ['coupon_code' => [$message]]]),
            ], $applied ? 200 : 422);
        }

        return redirect()
            ->route('cart.index')
            ->with('status', $message);
    }

    public function removeCoupon(Request $request): JsonResponse|RedirectResponse
    {
        $this->cart->clearCoupon();
        if ($request->expectsJson() || $request->ajax()) {
            return response()->json([
                'ok' => true,
                'message' => __('ui.cart.status.coupon_removed'),
                'coupon_code' => null,
                'summary' => $this->cart->summary(),
            ]);
        }

        return redirect()
            ->route('cart.index')
            ->with('status', __('ui.cart.status.coupon_removed'));
    }

    public function applyGiftVoucher(Request $request, GiftVoucherService $giftVouchers): JsonResponse|RedirectResponse
    {
        $validated = $request->validate([
            'gift_voucher_code' => ['required', 'string', 'max:80'],
            'return_to' => ['nullable', Rule::in(['cart', 'checkout'])],
        ], [
            'gift_voucher_code.required' => 'Unesite kod poklon bona.',
            'gift_voucher_code.string' => 'Unesite ispravan kod poklon bona.',
            'gift_voucher_code.max' => 'Kod poklon bona je predugačak.',
        ]);
        if (! $giftVouchers->applyCode((string) $validated['gift_voucher_code'])) {
            $message = 'Ovaj poklon bon nije dostupan. Provjerite kod, saldo i rok valjanosti.';
            if ($request->expectsJson()) {
                return response()->json(['message' => $message, 'errors' => ['gift_voucher_code' => [$message]]], 422);
            }

            return redirect()->route(($validated['return_to'] ?? '') === 'checkout' ? 'checkout.create' : 'cart.index')
                ->withErrors(['gift_voucher_code' => $message])->withInput();
        }

        return $this->giftVoucherResponse($request, $giftVouchers, 'Poklon bon je primijenjen.');
    }

    public function removeGiftVoucher(Request $request, GiftVoucherService $giftVouchers): JsonResponse|RedirectResponse
    {
        $giftVouchers->clearCode();

        return $this->giftVoucherResponse($request, $giftVouchers, 'Poklon bon je uklonjen.');
    }

    private function giftVoucherResponse(Request $request, GiftVoucherService $giftVouchers, string $message): JsonResponse|RedirectResponse
    {
        if ($request->expectsJson()) {
            $quote = $giftVouchers->quote((float) ($this->cart->summary()['grand_total'] ?? 0));

            return response()->json([
                'message' => $message,
                'code' => $giftVouchers->currentCode(),
                'amount' => (float) $quote['amount'],
                'amount_formatted' => Currency::format((float) $quote['amount']),
                'remaining_total' => (float) $quote['remaining_total'],
                'remaining_total_formatted' => Currency::format((float) $quote['remaining_total']),
            ]);
        }

        return redirect()->route($request->input('return_to') === 'checkout' ? 'checkout.create' : 'cart.index')
            ->with('status', $message);
    }
}
