<?php

namespace App\Services\Front;

use App\Mail\NewsletterCouponMail;
use App\Models\Catalog\Action\CatalogAction;
use App\Models\Sales\Order\Order;
use App\Models\User;
use App\Models\User\NewsletterSignup;
use App\Support\OrderStatusClassification;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

class WatchlineNewsletterWelcomeService
{
    public const SOURCE = 'watchline-newsletter-welcome';

    public const PAYLOAD_KEY = 'watchline_welcome';

    public function __construct(private readonly StoreSettingsService $storeSettings) {}

    /** Called only for a newly recorded consent, while the signup row is locked. */
    public function issue(NewsletterSignup $signup, ?User $user = null): string
    {
        $settings = $this->storeSettings->newsletter();
        if (config('storefront.home') !== 'watchline' || ! $signup->consent_accepted || ! ($settings['coupon_enabled'] ?? true)) {
            return 'disabled';
        }
        if (data_get($signup->payload, self::PAYLOAD_KEY.'.action_id')) {
            return 'already_issued';
        }
        if ($this->hasPriorPurchase($signup->email, $user, $signup->user_id) || $this->hasReservation($signup->email, $user, $signup->user_id)) {
            return 'ineligible';
        }
        $percent = max(1, min(100, (float) ($settings['coupon_percent'] ?? 10)));
        $action = CatalogAction::firstOrCreate(['code' => 'watchline-welcome-'.$signup->id], [
            'scope' => CatalogAction::SCOPE_CART, 'type' => CatalogAction::TYPE_PERCENTAGE, 'discount_value' => $percent,
            'coupon_code' => 'WL-'.Str::upper(Str::random(18)), 'target_type' => CatalogAction::TARGET_ALL,
            'audience_type' => CatalogAction::AUDIENCE_ALL, 'min_subtotal' => 0, 'starts_at' => now(), 'ends_at' => null,
            'is_active' => true, 'usage_limit' => 1, 'usage_limit_per_user' => 1, 'used_count' => 0,
            'payload' => ['source' => self::SOURCE, 'newsletter_signup_id' => $signup->id, 'recipient_hash' => self::emailHash($signup->email)],
        ]);
        if (! $this->isWelcome($action)) {
            return 'ineligible';
        }
        $payload = (array) $signup->payload;
        $payload[self::PAYLOAD_KEY] = ['action_id' => $action->id, 'issued_at' => now()->toIso8601String(),
            'delivery_status' => 'pending', 'delivered_at' => null, 'attempts' => 0];
        $signup->update(['payload' => $payload]);

        return 'issued';
    }

    /** Safe, idempotent operator retry for a code that was already issued. */
    public function retryDelivery(NewsletterSignup $signup): string
    {
        return $this->deliver($signup, true);
    }

    public function deliver(NewsletterSignup $signup, bool $retry = false): string
    {
        if (config('storefront.home') !== 'watchline' || app()->environment('local') || config('commerce.local_safe_mode')
            || ! ($this->storeSettings->newsletter()['coupon_enabled'] ?? true)
            || ! ($this->storeSettings->email()['enabled'] ?? false)) {
            return 'blocked';
        }
        $claim = DB::transaction(function () use ($signup, $retry): ?array {
            $signup = NewsletterSignup::query()->lockForUpdate()->find($signup->id);
            $grant = (array) data_get($signup?->payload, self::PAYLOAD_KEY, []);
            if (! $signup?->consent_accepted || ! isset($grant['action_id']) || ! empty($grant['delivered_at'])
                || ($grant['delivery_status'] ?? '') === 'sending' || (($grant['delivery_status'] ?? '') === 'failed' && ! $retry)) {
                return null;
            }
            $action = CatalogAction::find($grant['action_id']);
            if (! $action || ! $this->canApply($action)) {
                return null;
            }
            $grant['delivery_status'] = 'sending';
            $grant['attempts'] = (int) ($grant['attempts'] ?? 0) + 1;
            $grant['claim_token'] = Str::random(32);
            $payload = (array) $signup->payload;
            $payload[self::PAYLOAD_KEY] = $grant;
            $signup->update(['payload' => $payload]);

            return compact('signup', 'action', 'grant');
        });
        if (! $claim) {
            return 'not_pending';
        }
        $status = 'sent';
        $errorCode = null;
        try {
            Mail::to($claim['signup']->email)->locale($claim['signup']->locale ?: 'hr')->send(new NewsletterCouponMail(
                couponCode: $claim['action']->coupon_code, storeName: 'Watchline', shopUrl: route('shop.index'),
                logoUrl: asset('assets/brand/watchline-logo-on-dark.svg'), discountPercent: (float) $claim['action']->discount_value, watchline: true,
            ));
        } catch (Throwable $exception) {
            $status = 'failed';
            $errorCode = class_basename($exception);
        }
        DB::transaction(function () use ($claim, $status, $errorCode): void {
            $signup = NewsletterSignup::query()->lockForUpdate()->find($claim['signup']->id);
            $payload = (array) $signup->payload;
            if (data_get($payload, self::PAYLOAD_KEY.'.claim_token') !== $claim['grant']['claim_token']) {
                return;
            }
            $payload[self::PAYLOAD_KEY]['delivery_status'] = $status;
            $payload[self::PAYLOAD_KEY]['error_code'] = $errorCode;
            $payload[self::PAYLOAD_KEY]['delivered_at'] = $status === 'sent' ? now()->toIso8601String() : null;
            unset($payload[self::PAYLOAD_KEY]['claim_token']);
            $signup->update(['payload' => $payload]);
        });

        return $status;
    }

    public function isWelcome(CatalogAction $action): bool
    {
        return data_get($action->payload, 'source') === self::SOURCE;
    }

    public function canApply(CatalogAction $action, ?User $user = null, bool $lockHistory = false): bool
    {
        if (! $this->isWelcome($action)) {
            return true;
        }
        $signup = NewsletterSignup::find((int) data_get($action->payload, 'newsletter_signup_id'));

        return $signup && (int) data_get($signup->payload, self::PAYLOAD_KEY.'.action_id') === (int) $action->id
            && $action->is_active && (int) $action->used_count === 0
            && (! $action->starts_at || $action->starts_at->lte(now()))
            && (! $action->ends_at || $action->ends_at->isFuture())
            && (! $user || hash_equals(self::emailHash($signup->email), self::emailHash($user->email)))
            && ! $this->hasPriorPurchase($signup->email, $user, $signup->user_id, $lockHistory)
            && ! $this->hasReservation($signup->email, $user, $signup->user_id, $lockHistory);
    }

    /** Must run within the order transaction, before any order/stock writes. */
    public function reserveForCheckout(string $coupon, string $email, ?User $user, array $summary): ?CatalogAction
    {
        if (config('storefront.home') !== 'watchline' || $coupon === '') {
            return null;
        }
        $action = CatalogAction::query()->whereRaw('UPPER(coupon_code) = ?', [Str::upper($coupon)])->lockForUpdate()->first();
        if (! $action || ! $this->isWelcome($action)) {
            return null;
        }
        $signup = NewsletterSignup::query()->find((int) data_get($action->payload, 'newsletter_signup_id'));
        $identityIds = User::query()->whereRaw('LOWER(TRIM(email)) = ?', [Str::lower(trim($signup?->email ?? $email))])->pluck('id')
            ->push($user?->id)->push($signup?->user_id)->filter()->unique()->sort()->values()->all();
        User::query()->whereIn('id', $identityIds)->orderBy('id')->lockForUpdate()->get();
        NewsletterSignup::query()->whereKey($signup?->id)->lockForUpdate()->first();
        if (! $this->canApply($action, $user, true)
            || ! hash_equals((string) data_get($action->payload, 'recipient_hash'), self::emailHash($email))
            || (float) ($summary['cart_discount_total'] ?? 0) <= 0
            || ($summary['cart_discount_action_code'] ?? null) !== $action->code) {
            throw ValidationException::withMessages(['coupon_code' => __('ui.cart.welcome_coupon_ineligible')]);
        }
        $action->update(['used_count' => 1]);

        return $action;
    }

    public function attachOrder(CatalogAction $action, Order $order): void
    {
        $payload = (array) $action->payload;
        $payload['reserved_order_id'] = $order->id;
        $action->update(['payload' => $payload]);
    }

    public function releaseCancelledOrder(Order $order): void
    {
        if (config('storefront.home') !== 'watchline' || $order->paid_at || ! data_get($order->payload, 'watchline_newsletter_welcome.action_id')
            || ! Order::query()->whereKey($order->id)->whereHas('status', fn ($query) => OrderStatusClassification::applyCancelledQuery($query))->exists()) {
            return;
        }
        DB::transaction(function () use ($order): void {
            $action = CatalogAction::query()->lockForUpdate()->find((int) data_get($order->payload, 'watchline_newsletter_welcome.action_id'));
            if (! $action || ! $this->isWelcome($action) || (int) data_get($action->payload, 'reserved_order_id') !== (int) $order->id) {
                return;
            }
            $payload = (array) $action->payload;
            unset($payload['reserved_order_id']);
            $action->update(['used_count' => 0, 'payload' => $payload]);
        });
    }

    private function hasPriorPurchase(string $email, ?User $user = null, ?int $signupUserId = null, bool $lockHistory = false): bool
    {
        $query = $this->identityOrders($email, $user, $signupUserId)->where(function ($query): void {
            $query->whereNotNull('paid_at')->orWhereHas('status', function ($status): void {
                $status->where('is_cancelled', false)->where(fn ($status) => $status->where('is_paid', true)
                    ->orWhereIn(DB::raw('LOWER(TRIM(code))'), ['herrera-oc-status-5', 'watchline-oc-status-5', 'completed', 'complete', 'delivered', 'dovrseno', 'dovršeno', 'isporuceno', 'isporučeno']));
            });
        });

        // Current reads are required after waiting on an identity lock under MySQL REPEATABLE READ.
        return $lockHistory ? $query->lockForUpdate()->first(['orders.id']) !== null : $query->exists();
    }

    private function hasReservation(string $email, ?User $user = null, ?int $signupUserId = null, bool $lockHistory = false): bool
    {
        $query = $this->identityOrders($email, $user, $signupUserId)->withoutCancelled()->whereNotNull('payload->watchline_newsletter_welcome');

        return $lockHistory ? $query->lockForUpdate()->first(['orders.id']) !== null : $query->exists();
    }

    private function identityOrders(string $email, ?User $user = null, ?int $signupUserId = null): Builder
    {
        $email = Str::lower(trim($email));
        $ids = User::query()->whereRaw('LOWER(TRIM(email)) = ?', [$email])->pluck('id')
            ->push($user?->id)->push($signupUserId)->filter()->unique()->values()->all();

        $emails = NewsletterSignup::query()->whereIn('user_id', $ids)->pluck('email')
            ->push($email)->map(fn ($value): string => Str::lower(trim((string) $value)))->filter()->unique()->values()->all();

        return Order::query()->where(function ($query) use ($emails, $ids): void {
            $query->whereIn(DB::raw('LOWER(TRIM(customer_email))'), $emails);
            if ($ids !== []) {
                $query->orWhereIn('user_id', $ids);
            }
        });
    }

    public static function emailHash(string $email): string
    {
        return hash_hmac('sha256', Str::lower(trim($email)), (string) config('app.key'));
    }
}
