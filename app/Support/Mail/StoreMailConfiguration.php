<?php

namespace App\Support\Mail;

/** Shared by application boot and the read-only Lavaggio deployment preflight. */
final class StoreMailConfiguration
{
    public static function normalize(array $settings): array
    {
        foreach (['mailer', 'host', 'username', 'encryption', 'from_address', 'from_name', 'reply_to'] as $key) {
            $settings[$key] = trim((string) ($settings[$key] ?? ''));
        }

        // Password whitespace can be intentional and must remain untouched.
        return $settings;
    }

    public static function allowsSmtpInSafeMode(string $storefront, string $environment, array $settings): bool
    {
        return $storefront === 'lavaggio'
            && in_array($environment, ['staging', 'production'], true)
            && filter_var($settings['enabled'] ?? false, FILTER_VALIDATE_BOOLEAN)
            && self::smtpConfigurationError($settings) === null;
    }

    public static function smtpConfigurationError(array $settings): ?string
    {
        $settings = self::normalize($settings);
        if ($settings['mailer'] !== 'smtp') {
            return 'Enabled Lavaggio store email requires an explicitly configured SMTP mailer.';
        }
        if ($settings['host'] === '' || preg_match('/\s/u', $settings['host'])) {
            return 'Enabled Lavaggio SMTP requires a host without embedded whitespace.';
        }
        if (filter_var($settings['port'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 65535]]) === false) {
            return 'Enabled Lavaggio SMTP requires a valid port.';
        }
        if ($settings['username'] === '' || trim((string) ($settings['password'] ?? '')) === '') {
            return 'Enabled Lavaggio SMTP requires stored username and password credentials.';
        }
        if (! filter_var($settings['from_address'], FILTER_VALIDATE_EMAIL)) {
            return 'Enabled Lavaggio SMTP requires a valid sender address.';
        }
        if ($settings['reply_to'] !== '' && ! filter_var($settings['reply_to'], FILTER_VALIDATE_EMAIL)) {
            return 'Enabled Lavaggio SMTP requires a valid optional reply-to address.';
        }
        if (! in_array($settings['encryption'], ['', 'tls', 'ssl'], true)) {
            return 'Enabled Lavaggio SMTP requires a supported encryption setting.';
        }

        return null;
    }
}
