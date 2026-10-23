<?php

namespace App\Services\Integrations\Watchline;

use App\Mail\WatchlineAutomationMail;
use App\Models\Catalog\Action\CatalogAction;
use App\Models\Catalog\Product\Product;
use App\Models\Content\Support\Comment;
use App\Models\Integrations\Watchline\AbandonedCart;
use App\Models\Integrations\Watchline\MessageDelivery;
use App\Models\Sales\Order\Order;
use App\Models\Settings\Local\OrderStatus;
use App\Models\User\NewsletterSignup;
use App\Services\Front\CartService;
use App\Services\Settings\SystemSettingsService;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Throwable;

class WatchlineMessageAutomationService
{
    public const JOBS = ['reviews', 'abandoned'];

    public function __construct(private readonly SystemSettingsService $systemSettings) {}

    public function options(string $job): array
    {
        return $this->settings($job);
    }

    public function settings(string $job): array
    {
        $this->assertJob($job);
        $defaults = ['enabled' => false, 'activated_at' => null, 'batch_size' => 100];
        $defaults += $job === 'reviews'
            ? ['delay_days' => 7, 'status_ids' => OrderStatus::query()->whereIn('code', ['herrera-oc-status-5', 'watchline-oc-status-5'])->pluck('id')->map(fn ($id) => (int) $id)->all(),
                'subject' => 'Kako vam se sviđa vaš Watchline odabir?', 'intro' => 'Hvala na kupnji. Podijelite iskustvo s kupljenim artiklima i pomozite drugim kupcima pri odabiru.']
            : ['delay_minutes' => 60, 'visitor_type' => 'all', 'template_id' => 7, 'coupon_enabled' => true, 'coupon_percent' => 10, 'coupon_days' => 7,
                'subject' => 'Vaš Watchline odabir vas čeka', 'intro' => 'Sačuvali smo artikle koje ste odabrali. Ako još razmišljate, svoju košaricu možete nastaviti putem poveznice u nastavku.'];
        $stored = $this->systemSettings->get('watchline_messages_'.$job, []);

        return array_replace($defaults, is_array($stored) ? $stored : []);
    }

    /** First activation (and reactivation) starts a new window, never an old mail backlog. */
    public function configure(string $job, array $settings): array
    {
        $current = $this->settings($job);
        $rules = ['enabled' => ['sometimes', 'boolean'], 'batch_size' => ['sometimes', 'integer', 'min:1', 'max:500'],
            'subject' => ['sometimes', 'string', 'max:200'], 'intro' => ['sometimes', 'string', 'max:2000']];
        $rules += $job === 'reviews'
            ? ['delay_days' => ['sometimes', 'integer', 'min:1', 'max:90'], 'status_ids' => ['sometimes', 'array'], 'status_ids.*' => ['integer', 'exists:order_statuses,id']]
            : ['delay_minutes' => ['sometimes', 'integer', 'min:1', 'max:10080'], 'visitor_type' => ['sometimes', 'in:all,registered,guest'],
                'template_id' => ['sometimes', 'integer', 'in:7'],
                'coupon_enabled' => ['sometimes', 'boolean'], 'coupon_percent' => ['sometimes', 'numeric', 'min:0', 'max:100'], 'coupon_days' => ['sometimes', 'integer', 'min:1', 'max:90']];
        $validated = Validator::make($settings, $rules)->validate();
        $next = array_replace($current, $validated);
        $next['enabled'] = (bool) $next['enabled'];
        if ($next['enabled'] && (! $current['enabled'] || ! $current['activated_at'])) {
            $next['activated_at'] = now()->toIso8601String();
        }
        $next['subject'] = trim(str_replace(["\r", "\n"], '', $next['subject']));
        $this->systemSettings->put('watchline_messages_'.$job, $next);

        return $next;
    }

    /** Public reports intentionally contain no recipients, order identifiers or message bodies. */
    public function preview(string $job): array
    {
        return $this->run($job, true);
    }

    public function run(string $job, bool $dryRun = true): array
    {
        $settings = $this->settings($job);
        $reasons = [];
        if (config('storefront.home') !== 'watchline') {
            $reasons[] = 'not_watchline';
        }
        if (! $settings['enabled']) {
            $reasons[] = 'job_disabled';
        }
        if (! $settings['activated_at']) {
            $reasons[] = 'activation_required';
        }
        if (! $this->schemaReady()) {
            $reasons[] = 'schema_missing';
        }
        if (! filter_var($this->systemSettings->get('store_email_enabled', false), FILTER_VALIDATE_BOOLEAN)) {
            $reasons[] = 'store_email_disabled';
        }
        if ((bool) config('commerce.local_safe_mode', false)) {
            $reasons[] = 'local_safe_mode';
        }
        if (app()->environment('local')) {
            $reasons[] = 'local_environment';
        }
        if ($job === 'reviews' && $settings['status_ids'] === []) {
            $reasons[] = 'review_status_required';
        }
        $candidates = $this->schemaReady() && $settings['activated_at'] && config('storefront.home') === 'watchline'
            ? $this->candidates($job, $settings) : collect();
        $report = ['job' => $job, 'status' => $reasons !== [] ? 'blocked' : ($dryRun ? 'preview' : 'completed'), 'dry_run' => $dryRun, 'eligible' => $candidates->count(), 'sent' => 0,
            'skipped' => 0, 'failed' => 0, 'blocked' => $reasons !== [], 'reasons' => $reasons];
        if ($dryRun || $report['blocked']) {
            return $report;
        }
        foreach ($candidates as $candidate) {
            try {
                $delivery = MessageDelivery::create(['job' => $job, 'target_id' => $candidate['target_id'],
                    'dedup_key' => hash('sha256', $job.':'.$candidate['target_id']),
                    'email_hash' => self::emailHash($candidate['email']), 'recipient' => $candidate['email'],
                    'status' => 'claimed', 'claimed_at' => now()]);
            } catch (UniqueConstraintViolationException) {
                $report['skipped']++;

                continue;
            }
            try {
                // Recheck after claiming: completing a purchase or unsubscribing wins.
                $cart = $job === 'abandoned' ? $candidate['cart']->fresh() : null;
                $orderStillEligible = $job !== 'reviews' || Order::query()->whereKey($candidate['target_id'])
                    ->whereIn('status_id', $settings['status_ids'])->whereHas('status', fn ($query) => $query->where('is_cancelled', false))->exists();
                if ($this->suppressed($job, $candidate['email']) || ! $orderStillEligible
                    || ($job === 'abandoned' && (! $cart || ! $cart->consent_at || $cart->closed_at || $cart->converted_at
                        || $cart->last_activity_at->gt(now()->subMinutes((int) $settings['delay_minutes']))))) {
                    $delivery->update(['status' => 'cancelled']);
                    $report['skipped']++;

                    continue;
                }
                $coupon = $job === 'abandoned' && $settings['coupon_enabled'] && (float) $settings['coupon_percent'] > 0
                    ? $this->createCartCoupon($delivery, $candidate, $settings) : null;
                $hash = self::emailHash($candidate['email']);
                $action = $job === 'abandoned'
                    ? URL::temporarySignedRoute('watchline.messages.recover', now()->addDays((int) $settings['coupon_days']), ['cart' => $candidate['target_id']])
                    : $candidate['products'][0]['url'];
                $mail = new WatchlineAutomationMail(['subject' => $settings['subject'], 'intro' => $settings['intro'],
                    'heading' => $job === 'reviews' ? 'Vaše iskustvo nam znači.' : 'Nastavite svoj odabir.',
                    'products' => $candidate['products'], 'coupon' => $coupon,
                    'action_url' => $action, 'action_label' => $job === 'reviews' ? 'Podijelite iskustvo' : 'Vratite se u košaricu',
                    'unsubscribe_url' => URL::temporarySignedRoute('watchline.messages.unsubscribe', now()->addYear(), ['job' => $job, 'hash' => $hash])]);
                Mail::to($candidate['email'])->send($mail);
                $delivery->update(['status' => 'sent', 'sent_at' => now()]);
                $report['sent']++;
            } catch (Throwable $exception) {
                // An ambiguous transport failure is never retried automatically.
                $delivery->update(['status' => 'failed', 'error_code' => class_basename($exception)]);
                $report['failed']++;
            }
        }

        if ($report['failed'] > 0) {
            $report['status'] = 'failed';
        }

        return $report;
    }

    /** Capture only new, consented storefront carts; never import old abandoned carts. */
    public function cartCaptureEnabled(): bool
    {
        if (config('storefront.home') !== 'watchline') {
            return false;
        }
        $settings = $this->settings('abandoned');

        return (bool) $settings['enabled'] && (bool) $settings['activated_at'] && $this->schemaReady();
    }

    public function captureCart(Request $request): void
    {
        if (! $this->cartCaptureEnabled() || ! $request->hasSession()
            || $request->session()->has('admin.customer_impersonation') || $request->session()->has('admin.staff_impersonation')) {
            return;
        }
        $settings = $this->settings('abandoned');
        if (! $settings['enabled'] || ! $settings['activated_at']) {
            return;
        }
        $user = $request->user();
        if ($user && $user->account_type !== 'customer') {
            return;
        }
        // Login rotates the session ID; keep this opaque cart identity so the
        // same guest cart cannot receive a second reminder after signing in.
        $cartKey = $request->session()->get('watchline.cart_key');
        if (! is_string($cartKey) || strlen($cartKey) !== 40) {
            $cartKey = Str::random(40);
            $request->session()->put('watchline.cart_key', $cartKey);
        }
        $sessionHash = hash_hmac('sha256', $cartKey, (string) config('app.key'));
        $existing = AbandonedCart::query()->where('session_hash', $sessionHash)->first();
        $contact = $request->session()->get('watchline.cart_contact', []);
        $email = Str::lower(trim((string) ($contact['email'] ?? $user?->email ?? $existing?->email ?? '')));
        $items = array_values(app(CartService::class)->raw());
        if ($items === []) {
            $existing?->update(['closed_at' => $existing->closed_at ?: now(), 'items' => []]);

            return;
        }
        if (! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return;
        }
        $consent = array_key_exists('consent', $contact) ? (bool) $contact['consent']
            : ((bool) $user?->profile?->newsletter_opt_in || NewsletterSignup::query()->whereRaw('LOWER(email) = ?', [$email])->where('consent_accepted', true)->exists());
        if (! $consent || $this->suppressed('abandoned', $email)) {
            $existing?->update(['consent_at' => null, 'email' => null, 'customer_name' => null]);

            return;
        }
        if ($existing?->closed_at || $existing?->converted_at || $existing?->created_at?->lt(Carbon::parse($settings['activated_at']))) {
            $cartKey = Str::random(40);
            $request->session()->put('watchline.cart_key', $cartKey);
            $sessionHash = hash_hmac('sha256', $cartKey, (string) config('app.key'));
            $existing = null;
        }
        $fingerprint = hash('sha256', json_encode($items));
        if ($existing && $existing->fingerprint === $fingerprint && $existing->email_hash === self::emailHash($email)
            && $existing->consent_at && ! $existing->closed_at && ! $existing->converted_at) {
            if ($request->routeIs('cart.items.*', 'cart.coupon.*', 'checkout.options', 'watchline.messages.capture')) {
                $existing->update(['last_activity_at' => now()]);
            }

            return;
        }
        AbandonedCart::updateOrCreate(['session_hash' => $sessionHash], ['user_id' => $user?->id, 'email' => $email,
            'email_hash' => self::emailHash($email), 'customer_name' => mb_substr((string) ($contact['name'] ?? $user?->name ?? ''), 0, 120),
            'locale' => (string) app()->getLocale(), 'items' => $items, 'fingerprint' => $fingerprint,
            'consent_at' => now(), 'last_activity_at' => now(), 'closed_at' => null, 'converted_at' => null]);
    }

    public function markConverted(Order $order): void
    {
        if (! $this->cartCaptureEnabled() || ! $order->customer_email
            || $order->source === 'opencart_import' || ! $order->placed_at) {
            return;
        }
        AbandonedCart::query()->where('email_hash', self::emailHash($order->customer_email))
            ->whereNull('converted_at')->update(['converted_at' => now()]);
    }

    public function unsubscribe(string $job, string $hash): void
    {
        $this->assertJob($job);
        DB::table('watchline_message_suppressions')->updateOrInsert(['job' => $job, 'email_hash' => $hash], ['created_at' => now(), 'updated_at' => now()]);
        if ($job === 'abandoned') {
            AbandonedCart::query()->where('email_hash', $hash)->update(['consent_at' => null]);
        }
    }

    public static function emailHash(string $email): string
    {
        return hash_hmac('sha256', Str::lower(trim($email)), (string) config('app.key'));
    }

    private function candidates(string $job, array $settings): Collection
    {
        $limit = max(1, min(500, (int) $settings['batch_size']));
        $deliveredTargets = MessageDelivery::query()->where('job', $job)->select('target_id');
        if ($job === 'reviews') {
            return Order::query()->where('source', '!=', 'opencart_import')->whereIn('status_id', $settings['status_ids'])
                ->whereHas('status', fn ($query) => $query->where('is_cancelled', false))
                ->where('placed_at', '>=', Carbon::parse($settings['activated_at']))
                ->where('placed_at', '<=', now()->subDays((int) $settings['delay_days']))
                ->whereNotIn('id', $deliveredTargets)->with(['items.product.translations'])->lazyById(100)
                ->map(function (Order $order): ?array {
                    $email = Str::lower(trim((string) $order->customer_email));
                    if (! filter_var($email, FILTER_VALIDATE_EMAIL) || $this->suppressed('reviews', $email)) {
                        return null;
                    }
                    $products = $order->items->pluck('product')->filter()->unique('id')->filter(fn (Product $product): bool => $product->is_active);
                    $reviewed = Comment::query()->where('commentable_type', (new Product)->getMorphClass())
                        ->whereIn('commentable_id', $products->pluck('id'))
                        ->where(fn ($query) => $query->whereRaw('LOWER(author_email) = ?', [$email])
                            ->when($order->user_id, fn ($query) => $query->orWhere('user_id', $order->user_id)))
                        ->pluck('commentable_id')->all();
                    $items = $this->publicProducts($products->whereNotIn('id', $reviewed), true);

                    return $items === [] ? null : ['target_id' => (string) $order->id, 'email' => $email, 'products' => $items];
                })->filter()->take($limit)->values()->collect();
        }

        return AbandonedCart::query()->whereNotNull('consent_at')->whereNotNull('email')->whereNull('closed_at')->whereNull('converted_at')
            ->where('created_at', '>=', Carbon::parse($settings['activated_at']))
            ->where('last_activity_at', '<=', now()->subMinutes((int) $settings['delay_minutes']))
            ->when($settings['visitor_type'] === 'registered', fn ($query) => $query->whereNotNull('user_id'))
            ->when($settings['visitor_type'] === 'guest', fn ($query) => $query->whereNull('user_id'))
            ->whereNotIn('id', $deliveredTargets)->lazyById(100)
            ->map(function (AbandonedCart $cart): ?array {
                $email = (string) $cart->email;
                if (! filter_var($email, FILTER_VALIDATE_EMAIL) || $this->suppressed('abandoned', $email)) {
                    return null;
                }
                $products = Product::query()->visibleOnStorefront(true)->whereIn('id', array_column($cart->items, 'product_id'))
                    ->with(['translations', 'optionValues'])->get();
                $quantities = [];
                foreach ($cart->items as $line) {
                    $product = $products->firstWhere('id', (int) ($line['product_id'] ?? 0));
                    if (! $product) {
                        continue;
                    }
                    $optionId = (int) ($line['product_option_value_id'] ?? 0);
                    if ($optionId > 0 && ! $product->optionValues->contains(fn ($option): bool => (int) $option->id === $optionId && $option->is_active && $option->stock_qty > 0)) {
                        continue;
                    }
                    $quantities[$product->id] = ($quantities[$product->id] ?? 0) + max(1, (int) ($line['quantity'] ?? 1));
                }
                $items = array_map(fn (array $item): array => $item + ['quantity' => $quantities[$item['id']]],
                    $this->publicProducts($products->whereIn('id', array_keys($quantities))));

                return $items === [] ? null : ['target_id' => $cart->id, 'email' => $email, 'products' => $items, 'cart' => $cart];
            })->filter()->take($limit)->values()->collect();
    }

    private function publicProducts(Collection $products, bool $review = false): array
    {
        return $products->map(function (Product $product) use ($review): ?array {
            $translation = $product->translations->firstWhere('locale', 'hr') ?? $product->translations->firstWhere('locale', config('app.locale'));
            if (! trim((string) $translation?->slug)) {
                return null;
            }

            return ['id' => (int) $product->id, 'name' => (string) $translation->name,
                'url' => route('products.show', ['slug' => $translation->slug]).($review ? '#product-comments' : '')];
        })->filter()->values()->all();
    }

    private function createCartCoupon(MessageDelivery $delivery, array $candidate, array $settings): array
    {
        $code = 'WL-'.Str::upper(Str::random(16));
        $action = CatalogAction::create(['code' => 'watchline-abandoned-'.$delivery->id, 'scope' => CatalogAction::SCOPE_CART,
            'type' => CatalogAction::TYPE_PERCENTAGE, 'discount_value' => (float) $settings['coupon_percent'],
            'coupon_code' => $code, 'target_type' => CatalogAction::TARGET_PRODUCT, 'audience_type' => CatalogAction::AUDIENCE_ALL,
            'starts_at' => now(), 'ends_at' => now()->addDays((int) $settings['coupon_days']), 'is_active' => true,
            'usage_limit' => 1, 'usage_limit_per_user' => 1, 'priority' => 0,
            'payload' => ['source' => 'watchline-abandoned-automation']]);
        foreach (array_column($candidate['products'], 'id') as $productId) {
            $action->targets()->create(['target_type' => CatalogAction::TARGET_PRODUCT, 'target_id' => $productId]);
        }
        $delivery->update(['payload' => ['coupon_action_id' => $action->id]]);

        return ['code' => $code, 'discount' => (float) $settings['coupon_percent'], 'expires' => $action->ends_at->format('d.m.Y.')];
    }

    private function suppressed(string $job, string $email): bool
    {
        return DB::table('watchline_message_suppressions')->where('job', $job)->where('email_hash', self::emailHash($email))->exists();
    }

    private function schemaReady(): bool
    {
        return Schema::hasTable('watchline_message_deliveries') && Schema::hasTable('watchline_abandoned_carts')
            && Schema::hasTable('watchline_message_suppressions');
    }

    private function assertJob(string $job): void
    {
        if (! in_array($job, self::JOBS, true)) {
            throw new InvalidArgumentException('Unknown Watchline message automation.');
        }
    }
}
