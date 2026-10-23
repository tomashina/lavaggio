<?php

namespace App\Http\Controllers\Front;

use App\Http\Controllers\Controller;
use App\Models\Catalog\Bundle\Bundle;
use App\Services\Catalog\BundlePricingService;
use App\Services\Front\CartService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Session;
use Illuminate\Validation\ValidationException;

class BundleController extends Controller
{
    public function index(BundlePricingService $pricing, \App\Services\Catalog\BundleOfferPresenter $presenter)
    {
        $groups = $pricing->groupIds(auth()->user());
        $bundles = Bundle::available()->whereHas('discounts', fn ($q) => $q->whereIn('customer_group_id', $groups)->where('value', '>', 0))
            ->with(['products.translations', 'products.media', 'products.taxRate', 'products.categories',
                'products.optionValues.optionValue.option:id,is_active,payload',
                'products.optionValues.parentOptionValue.option:id,is_active,payload', 'discounts'])->orderBy('sort_order')->paginate(12);

        $offers = $bundles->getCollection()->mapWithKeys(fn ($bundle) => [$bundle->id => $presenter->present($bundle, $groups, auth()->user())]);

        return view('front.desktop.bundles.index', compact('bundles', 'groups', 'offers'));
    }

    public function store(Request $request, Bundle $bundle, CartService $cart, BundlePricingService $pricing)
    {
        $validated = $request->validate([
            'quantity' => ['sometimes', 'integer', 'min:1', 'max:99'],
        ], [
            'quantity.integer' => 'Broj kompleta mora biti cijeli broj.',
            'quantity.min' => 'Odaberite barem jedan komplet.',
            'quantity.max' => 'Možete odabrati najviše 99 kompleta.',
        ]);
        $setQuantity = (int) ($validated['quantity'] ?? 1);

        abort_unless(Bundle::available()->whereKey($bundle->id)->exists(), 404);
        abort_unless($bundle->discounts()->whereIn('customer_group_id', $pricing->groupIds($request->user()))->where('value', '>', 0)->exists(), 403);
        $bundle->load('products');
        abort_if($bundle->products->count() < 2, 422);
        $before = $cart->raw();
        $expected = $before;
        foreach ($bundle->products as $product) {
            $quantity = (int) $product->pivot->quantity * $setQuantity;
            $lineKey = $product->id.':0';
            $target = (int) ($before[$lineKey]['quantity'] ?? 0) + $quantity;
            $existing = (int) collect($before)->where('product_id', $product->id)->sum('quantity');
            $minimum = max(1, (int) $product->minimum_order_quantity);
            $step = max(1, (int) $product->order_quantity_step);
            if ($quantity < 1 || ! $product->is_active || $product->hasVisibleOptionRows()
                || $quantity + $existing > $product->availableStockQuantity() || $target > 999
                || $target < $minimum || ($target - $minimum) % $step !== 0) {
                throw ValidationException::withMessages(['bundle' => 'Neki artikli kompleta trenutno nisu dostupni u traženoj količini.']);
            }
            $expected[$lineKey] = [
                'product_id' => (int) $product->id,
                'product_option_value_id' => null,
                'quantity' => $target,
            ];
        }
        try {
            foreach ($bundle->products as $product) {
                if (! $cart->add($product, (int) $product->pivot->quantity * $setQuantity)) {
                    throw ValidationException::withMessages(['bundle' => 'Komplet trenutno nije moguće dodati u košaricu.']);
                }
            }
            if ($cart->raw() !== $expected) {
                throw ValidationException::withMessages(['bundle' => 'Komplet nije moguće dodati u traženoj količini. Košarica nije promijenjena.']);
            }
        } catch (\Throwable $exception) {
            Session::put('front.cart.items', $before);
            CartService::forgetRequestSnapshot();
            throw $exception;
        }

        return redirect()->route('cart.index')->with('status', 'Komplet je dodan u košaricu.');
    }
}
