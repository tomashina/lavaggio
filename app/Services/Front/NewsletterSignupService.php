<?php

namespace App\Services\Front;

use App\Models\User;
use App\Models\User\NewsletterSignup;
use App\Services\Integrations\Newsletter\KlaviyoNewsletterService;
use App\Services\Integrations\Newsletter\MailchimpNewsletterService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

class NewsletterSignupService
{
    public function __construct(
        private readonly StoreSettingsService $storeSettings,
        private readonly MailchimpNewsletterService $mailchimp,
        private readonly KlaviyoNewsletterService $klaviyo,
    ) {}

    /**
     * @return array{signup: NewsletterSignup|null, synced: bool}
     */
    public function subscribe(
        string $email,
        bool $consentAccepted,
        ?User $user = null,
        string $source = NewsletterSignup::SOURCE_FOOTER,
        ?string $locale = null,
        ?string $ipAddress = null,
        ?string $userAgent = null,
        ?string $referrer = null,
    ): array {
        $newsletterSettings = $this->storeSettings->newsletter();
        $provider = $this->normalizeProvider((string) ($newsletterSettings['provider'] ?? NewsletterSignup::PROVIDER_NONE));
        $normalizedEmail = Str::lower(trim($email));
        $now = now();

        if ($provider === NewsletterSignup::PROVIDER_NONE) {
            throw new RuntimeException((string) __('ui.front.desktop.newsletter.status.failed'));
        }

        if (config('storefront.home') === 'watchline') {
            return $this->subscribeWatchline($normalizedEmail, $consentAccepted, $user, $source, $locale, $ipAddress, $userAgent, $referrer, $provider, $newsletterSettings);
        }

        if ($provider === NewsletterSignup::PROVIDER_DATABASE) {
            if (! Schema::hasTable('newsletter_signups')) {
                throw new RuntimeException((string) __('ui.front.desktop.newsletter.status.failed'));
            }

            $signup = NewsletterSignup::query()->firstOrNew(['email' => $normalizedEmail]);
            $payload = is_array($signup->payload) ? $signup->payload : [];

            $signup->fill([
                'user_id' => $signup->user_id ?: $user?->id,
                'email' => $normalizedEmail,
                'source' => $source,
                'locale' => trim((string) ($locale ?: app()->getLocale() ?: config('app.locale', 'hr'))) ?: 'hr',
                'provider' => $provider,
                'sync_status' => NewsletterSignup::SYNC_SYNCED,
                'consent_accepted' => $consentAccepted,
                'ip_address' => trim((string) $ipAddress) ?: null,
                'user_agent' => trim((string) $userAgent) ?: null,
                'subscribed_at' => $signup->subscribed_at ?: $now,
                'synced_at' => $now,
                'payload' => array_merge($payload, array_filter([
                    'last_referrer' => trim((string) $referrer) ?: null,
                    'last_seen_at' => $now->toIso8601String(),
                ], static fn ($value) => $value !== null)),
            ]);
            $signup->provider_error = null;
            $signup->save();

            return ['signup' => $signup->fresh(), 'synced' => true];
        }

        match ($provider) {
            NewsletterSignup::PROVIDER_MAILCHIMP => $this->mailchimp->subscribe($normalizedEmail, $newsletterSettings),
            NewsletterSignup::PROVIDER_KLAVIYO => $this->klaviyo->subscribe($normalizedEmail, $newsletterSettings),
            default => throw new RuntimeException('Newsletter provider nije podržan.'),
        };

        return ['signup' => null, 'synced' => true];
    }

    private function subscribeWatchline(string $email, bool $consent, ?User $user, string $source, ?string $locale, ?string $ip, ?string $agent, ?string $referrer, string $provider, array $settings): array
    {
        if (! ($settings['enabled'] ?? true) || ! Schema::hasTable('newsletter_signups')) {
            throw new RuntimeException((string) __('ui.front.desktop.newsletter.status.failed'));
        }
        // Staff testing an address must not bind that subscriber to the staff account.
        $customer = $user && $user->account_type === 'customer' && Str::lower(trim($user->email)) === $email ? $user : null;
        $result = DB::transaction(function () use ($email, $consent, $customer, $source, $locale, $ip, $agent, $referrer, $provider): array {
            // The unique email constraint and row lock serialize repeat submissions.
            $signup = NewsletterSignup::query()->whereRaw('LOWER(TRIM(email)) = ?', [$email])->lockForUpdate()->first();
            if (! $signup) {
                $signup = NewsletterSignup::query()->createOrFirst(['email' => $email], ['source' => $source, 'provider' => $provider]);
                $signup = NewsletterSignup::query()->lockForUpdate()->findOrFail($signup->id);
            }
            $existingConsent = (bool) $signup->consent_accepted;
            $payload = (array) $signup->payload;
            $payload['last_referrer'] = trim((string) $referrer) ?: null;
            $payload['last_seen_at'] = now()->toIso8601String();
            $signup->fill([
                'email' => $email, 'user_id' => $signup->user_id ?: $customer?->id, 'source' => $signup->source ?: $source,
                'locale' => $locale ?: app()->getLocale(), 'provider' => $provider,
                'consent_accepted' => $existingConsent || $consent, 'subscribed_at' => $signup->subscribed_at ?: now(),
                'ip_address' => trim((string) $ip) ?: null, 'user_agent' => trim((string) $agent) ?: null,
                'sync_status' => $provider === NewsletterSignup::PROVIDER_DATABASE ? NewsletterSignup::SYNC_SYNCED : NewsletterSignup::SYNC_PENDING,
                'synced_at' => $provider === NewsletterSignup::PROVIDER_DATABASE ? now() : $signup->synced_at, 'payload' => $payload,
            ])->save();
            $welcome = ! $existingConsent && $consent ? app(WatchlineNewsletterWelcomeService::class)->issue($signup, $customer) : 'existing';

            return ['signup' => $signup->fresh(), 'synced' => $provider === NewsletterSignup::PROVIDER_DATABASE, 'welcome' => $welcome];
        });
        if ($provider !== NewsletterSignup::PROVIDER_DATABASE && ! app()->environment('local') && ! config('commerce.local_safe_mode')) {
            try {
                match ($provider) {
                    NewsletterSignup::PROVIDER_MAILCHIMP => $this->mailchimp->subscribe($email, $settings),
                    NewsletterSignup::PROVIDER_KLAVIYO => $this->klaviyo->subscribe($email, $settings),
                };
                $result['signup']->update(['sync_status' => NewsletterSignup::SYNC_SYNCED, 'synced_at' => now(), 'provider_error' => null]);
                $result['synced'] = true;
            } catch (Throwable $exception) {
                $result['signup']->update(['sync_status' => NewsletterSignup::SYNC_FAILED, 'provider_error' => class_basename($exception)]);
            }
        }

        return $result;
    }

    private function normalizeProvider(string $provider): string
    {
        $provider = Str::lower(trim($provider));

        return in_array($provider, [
            NewsletterSignup::PROVIDER_NONE,
            NewsletterSignup::PROVIDER_DATABASE,
            NewsletterSignup::PROVIDER_MAILCHIMP,
            NewsletterSignup::PROVIDER_KLAVIYO,
        ], true) ? $provider : NewsletterSignup::PROVIDER_NONE;
    }
}
