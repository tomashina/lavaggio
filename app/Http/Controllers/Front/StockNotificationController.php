<?php

namespace App\Http\Controllers\Front;

use App\Http\Controllers\Controller;
use App\Models\Catalog\Bundle\Bundle;
use App\Models\Catalog\Product\Product;
use App\Models\Catalog\Product\ProductOptionValue;
use App\Models\Catalog\StockNotification;
use App\Services\Catalog\BundlePricingService;
use App\Services\Catalog\StockNotificationService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class StockNotificationController extends Controller
{
    private const SUCCESS_MESSAGE = 'Prijava je zabilježena. Poslat ćemo vam jednu obavijest kada ponovno bude dostupno.';

    public function create(Request $request, StockNotificationService $service)
    {
        $notification = $this->target($request);
        if ($service->isAvailable($notification)) {
            return redirect($notification->storefrontUrl())->with('status', 'Odabrani artikl ili komplet već je dostupan.');
        }

        return view('front.desktop.stock-notifications.create', [
            'product' => $notification->product,
            'bundle' => $notification->bundle,
            'productOptionValue' => $notification->optionValue,
            'targetName' => $notification->displayName(),
        ]);
    }

    public function store(Request $request, StockNotificationService $service)
    {
        if ($request->filled('website')) {
            return $this->success($request);
        }
        if (is_string($request->input('email'))) {
            $request->merge(['email' => mb_strtolower(trim($request->input('email')))]);
        }
        $data = $request->validate([
            'email' => ['required', 'string', 'email:rfc', 'max:190'],
            'consent' => ['required', 'accepted'],
        ], [
            'email.required' => 'Unesite svoju e-mail adresu.',
            'email.email' => 'Unesite ispravnu e-mail adresu.',
            'email.max' => 'E-mail adresa može imati najviše 190 znakova.',
            'consent.required' => 'Za slanje obavijesti potrebno je označiti privolu.',
            'consent.accepted' => 'Za slanje obavijesti potrebno je označiti privolu.',
        ]);
        $notification = $this->target($request);
        if ($service->isAvailable($notification)) {
            throw ValidationException::withMessages([
                $notification->bundle_id ? 'bundle_id' : 'product_id' => 'Već je dostupno! Vratite se na artikl ili komplet i dodajte ga u košaricu.',
            ]);
        }
        $targetKey = $notification->bundle_id ? 'bundle:'.$notification->bundle_id
            : 'product:'.$notification->product_id.':option:'.($notification->product_option_value_id ?? 0);
        // The unique key also protects simultaneous submissions of the same address.
        $saved = StockNotification::query()->firstOrCreate(
            ['target_key' => $targetKey, 'email' => $data['email']],
            [
                'product_id' => $notification->product_id, 'bundle_id' => $notification->bundle_id,
                'product_option_value_id' => $notification->product_option_value_id,
                'user_id' => $request->user()?->id, 'locale' => app()->getLocale(),
                'consented_at' => now(), 'status' => StockNotification::WAITING,
            ],
        );
        if (! $saved->wasRecentlyCreated && in_array($saved->status, [StockNotification::SENT, StockNotification::CANCELLED, StockNotification::FAILED], true)) {
            DB::transaction(function () use ($saved, $request, $service): void {
                $existing = StockNotification::query()->lockForUpdate()->find($saved->id);
                if ($existing && $existing->status === $saved->status && $existing->token === $saved->token
                    && ! $service->isAvailable($existing)) {
                    $existing->update([
                        'status' => StockNotification::WAITING, 'user_id' => $request->user()?->id,
                        'locale' => app()->getLocale(), 'consented_at' => now(), 'sent_at' => null,
                        'last_attempt_at' => null, 'attempts' => 0, 'last_error' => null,
                        'claim_token' => null, 'token' => Str::random(64),
                    ]);
                }
            });
        }

        return $this->success($request);
    }

    public function unsubscribe(string $token)
    {
        $notification = StockNotification::where('token', $token)->firstOrFail();

        return view('front.desktop.stock-notifications.unsubscribe', compact('notification'));
    }

    public function cancel(string $token)
    {
        $notification = StockNotification::where('token', $token)->firstOrFail();
        // A message already handed to the mail provider cannot be recalled.
        StockNotification::whereKey($notification->id)->where('token', $token)->whereIn('status', [StockNotification::WAITING, StockNotification::FAILED])
            ->update(['status' => StockNotification::CANCELLED, 'updated_at' => now()]);
        $notification->refresh();

        return view('front.desktop.stock-notifications.unsubscribe', compact('notification'));
    }

    private function target(Request $request): StockNotification
    {
        abort_unless(config('stock_notifications.enabled', true), 404);
        $data = $request->validate([
            'product_id' => ['nullable', 'required_without:bundle_id', 'prohibits:bundle_id', 'integer', 'min:1'],
            'bundle_id' => ['nullable', 'required_without:product_id', 'prohibits:product_id,product_option_value_id', 'integer', 'min:1'],
            'product_option_value_id' => ['nullable', 'integer', 'min:1'],
        ], [
            'product_id.required_without' => 'Odaberite artikl ili komplet.',
            'bundle_id.required_without' => 'Odaberite artikl ili komplet.',
            'product_id.prohibits' => 'Odaberite samo jedan artikl ili komplet.',
            'bundle_id.prohibits' => 'Odaberite samo jedan artikl ili komplet.',
        ]);
        $locale = app()->getLocale();
        $notification = new StockNotification(['locale' => $locale, 'user_id' => $request->user()?->id]);
        $notification->setRelation('user', $request->user());
        if (! empty($data['bundle_id'])) {
            $groups = app(BundlePricingService::class)->groupIds($request->user());
            $bundle = Bundle::available()->with(['products.translations', 'discounts'])
                ->whereHas('discounts', fn ($q) => $q->whereIn('customer_group_id', $groups)->where('value', '>', 0))
                ->find($data['bundle_id']);
            if (! $bundle || $bundle->products->count() < 2
                || $bundle->products->contains(fn ($product) => ! $this->publicProduct($product, $locale))) {
                throw ValidationException::withMessages(['bundle_id' => 'Odabrani komplet nije dostupan za prijavu.']);
            }
            $notification->bundle_id = $bundle->id;
            $notification->setRelation('bundle', $bundle);
        } else {
            $product = Product::with('translations')->find($data['product_id']);
            if (! $product || ! $this->publicProduct($product, $locale)) {
                throw ValidationException::withMessages(['product_id' => 'Odabrani artikl nije dostupan za prijavu.']);
            }
            $notification->product_id = $product->id;
            $notification->setRelation('product', $product);
            if (! empty($data['product_option_value_id'])) {
                $option = ProductOptionValue::with(['optionValue.option', 'parentOptionValue.option'])
                    ->where('product_id', $product->id)->find($data['product_option_value_id']);
                if (! $option || ! $option->isVisibleOnStorefront()) {
                    throw ValidationException::withMessages(['product_option_value_id' => 'Odabrana varijanta nije dostupna za prijavu.']);
                }
                $notification->product_option_value_id = $option->id;
                $notification->setRelation('optionValue', $option);
            }
        }

        return $notification;
    }

    private function publicProduct(Product $product, string $locale): bool
    {
        return $product->is_active && $product->translations->contains(fn ($translation) => in_array($translation->locale, [$locale, 'hr'], true) && trim((string) $translation->slug) !== '');
    }

    private function success(Request $request)
    {
        return $request->expectsJson() ? response()->json(['message' => self::SUCCESS_MESSAGE])
            : back()->with('success', self::SUCCESS_MESSAGE);
    }
}
