<?php

namespace App\Services\Catalog;

use App\Mail\BackInStockMail;
use App\Models\Catalog\Product\Product;
use App\Models\Catalog\StockNotification;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Illuminate\Support\Testing\Fakes\MailFake;

class StockNotificationService
{
    public static function relations(): array
    {
        return ['user', 'product.translations', 'product.optionValues.optionValue.option', 'product.optionValues.parentOptionValue.option',
            'optionValue.optionValue.option', 'optionValue.optionValue.translations',
            'optionValue.parentOptionValue.option', 'optionValue.parentOptionValue.translations',
            'bundle.discounts', 'bundle.products.translations', 'bundle.products.optionValues.optionValue.option', 'bundle.products.optionValues.parentOptionValue.option'];
    }

    public function isAvailable(StockNotification $notification): bool
    {
        $notification->loadMissing(self::relations());
        if ($notification->bundle_id) {
            $bundle = $notification->bundle;
            if (! $bundle || ! $bundle->is_active || $bundle->starts_at?->isFuture() || $bundle->ends_at?->isPast() || $bundle->products->count() < 2) {
                return false;
            }
            $groups = app(BundlePricingService::class)->groupIds($notification->user);
            $hasDiscount = $bundle->discounts->contains(fn ($discount) => in_array((int) $discount->customer_group_id, $groups, true) && (float) $discount->value > 0);

            return $hasDiscount && $bundle->products->every(fn (Product $product) => $this->productIsAvailable($product, (int) $product->pivot->quantity, false, $notification->locale));
        }
        $product = $notification->product;
        if (! $product || ! $this->isPublic($product, $notification->locale)) {
            return false;
        }
        if ($notification->product_option_value_id) {
            $option = $notification->optionValue;

            return $option && (int) $option->product_id === (int) $product->id
                && $option->isVisibleOnStorefront() && (int) $option->stock_qty >= max(1, (int) $product->minimum_order_quantity);
        }

        return $this->productIsAvailable($product, max(1, (int) $product->minimum_order_quantity), true, $notification->locale);
    }

    private function isPublic(Product $product, string $locale = 'hr'): bool
    {
        $product->loadMissing('translations');

        return $product->is_active && $product->translations->contains(fn ($translation) => in_array($translation->locale, [$locale, 'hr'], true) && trim((string) $translation->slug) !== '');
    }

    private function productIsAvailable(Product $product, int $quantity, bool $allowOptions = true, string $locale = 'hr'): bool
    {
        if (! $this->isPublic($product, $locale) || $quantity < max(1, (int) $product->minimum_order_quantity)
            || $quantity > 999 || (! $allowOptions && $quantity % max(1, (int) $product->order_quantity_step) !== 0)) {
            return false;
        }
        if ($product->hasVisibleOptionRows()) {
            return $allowOptions && $product->availableOptionRows()->contains(fn ($option) => (int) $option->stock_qty >= $quantity);
        }

        return $product->availableStockQuantity() >= $quantity;
    }

    public function canSend(): bool
    {
        return (bool) config('stock_notifications.enabled', true) && (bool) config('stock_notifications.emails_enabled', false)
            && (Mail::getFacadeRoot() instanceof MailFake || $this->hasDeliveryTransport((string) config('mail.default')));
    }

    private function hasDeliveryTransport(string $mailer, array $visited = []): bool
    {
        if (in_array($mailer, $visited, true)) {
            return false;
        }
        $configuration = config('mail.mailers.'.$mailer, []);
        $transport = $configuration['transport'] ?? $mailer;
        if (in_array($transport, ['log', 'array', ''], true)) {
            return false;
        }
        if (in_array($transport, ['failover', 'roundrobin'], true)) {
            $mailers = $configuration['mailers'] ?? [];

            return $mailers !== [] && collect($mailers)->every(fn ($name) => $this->hasDeliveryTransport($name, [...$visited, $mailer]));
        }

        return true;
    }

    public function sendNow(int $id): array
    {
        if (! $this->canSend()) {
            return $this->result(false, 'disabled', 'Slanje e-pošte nije uključeno ili postavljeno u ovom okruženju.');
        }
        $notification = StockNotification::with(self::relations())->find($id);
        if (! $notification || in_array($notification->status, [StockNotification::SENT, StockNotification::CANCELLED, StockNotification::PROCESSING], true)) {
            return $this->result(false, 'processed', 'Obavijest je već obrađena ili se trenutačno šalje.');
        }
        if (! filter_var($notification->email, FILTER_VALIDATE_EMAIL)) {
            return $this->result(false, 'invalid', 'E-mail adresa nije ispravna.');
        }
        if (! $notification->consented_at) {
            return $this->result(false, 'consent', 'Nedostaje privola za ovu obavijest.');
        }
        if (! $this->isAvailable($notification)) {
            return $this->result(false, 'unavailable', 'Artikl ili komplet još nije dostupan.');
        }
        $claim = Str::random(64);
        $claimed = StockNotification::whereKey($id)->whereIn('status', [StockNotification::WAITING, StockNotification::FAILED])
            ->update(['status' => StockNotification::PROCESSING, 'claim_token' => $claim, 'last_attempt_at' => now(), 'attempts' => DB::raw('attempts + 1'), 'updated_at' => now()]);
        if (! $claimed) {
            return $this->result(false, 'processed', 'Obavijest se već šalje.');
        }
        try {
            $notification = StockNotification::with(self::relations())->findOrFail($id);
            if (! $this->isAvailable($notification)) {
                $this->claimed($id, $claim)->update(['status' => StockNotification::WAITING, 'claim_token' => null]);

                return $this->result(false, 'unavailable', 'Artikl više nije dostupan; prijava ostaje na čekanju.');
            }
            $sentMessage = Mail::to($notification->email)->send((new BackInStockMail($notification))->locale($notification->locale));
            if (! $sentMessage && ! (Mail::getFacadeRoot() instanceof MailFake)) {
                throw new \RuntimeException('Slanje poruke je zaustavljeno.');
            }
        } catch (\Throwable $exception) {
            $this->claimed($id, $claim)->update(['status' => StockNotification::FAILED, 'claim_token' => null, 'last_error' => class_basename($exception).': Slanje nije uspjelo.', 'updated_at' => now()]);

            return $this->result(false, 'failed', 'Slanje nije uspjelo. Prijava je sačuvana za ponovni pokušaj.');
        }
        try {
            $this->claimed($id, $claim)->update(['status' => StockNotification::SENT, 'sent_at' => now(), 'claim_token' => null, 'last_error' => null, 'updated_at' => now()]);

            return $this->result(true, null, 'Obavijest o dostupnosti je poslana.');
        } catch (\Throwable) {
            // The provider accepted this mail. Keep the claim for manual review,
            // rather than automatically delivering it twice after a database error.
            return $this->result(false, 'unconfirmed', 'Poruka je predana za slanje, ali zapis dostave nije potvrđen. Provjerite dostavu prije ponovnog pokušaja.');
        }
    }

    public function processReady(int $limit = 50, bool $dryRun = false): array
    {
        $stats = ['checked' => 0, 'ready' => 0, 'sent' => 0, 'failed' => 0, 'disabled' => 0];
        if (! $dryRun && ! $this->canSend()) {
            $stats['disabled'] = 1;

            return $stats;
        }
        $limit = max(1, min($limit, 1000));
        StockNotification::query()->with(self::relations())->where(function ($q): void {
            $q->where('status', StockNotification::WAITING)->orWhere(fn ($q) => $q->where('status', StockNotification::FAILED)
                ->where('attempts', '<', (int) config('stock_notifications.max_attempts', 3))
                ->where('last_attempt_at', '<=', now()->subMinutes((int) config('stock_notifications.retry_minutes', 5))));
        })->chunkById(100, function ($notifications) use (&$stats, $limit, $dryRun): bool {
            foreach ($notifications as $notification) {
                $stats['checked']++;
                if (! $this->isAvailable($notification)) {
                    continue;
                }
                $stats['ready']++;
                if (! $dryRun) {
                    $result = $this->sendNow($notification->id);
                    if ($result['sent']) {
                        $stats['sent']++;
                    } elseif (in_array($result['reason'], ['failed', 'unconfirmed'], true)) {
                        $stats['failed']++;
                    }
                }
                if ($stats['ready'] >= $limit) {
                    return false;
                }
            }

            return true;
        });

        return $stats;
    }

    private function claimed(int $id, string $claim)
    {
        return StockNotification::whereKey($id)->where('status', StockNotification::PROCESSING)->where('claim_token', $claim);
    }

    private function result(bool $sent, ?string $reason, string $message): array
    {
        return compact('sent', 'reason', 'message');
    }
}
