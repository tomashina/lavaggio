<?php

namespace Tests\Feature\Sales;

use App\Providers\AppServiceProvider;
use App\Services\Front\StoreSettingsService;
use App\Support\Mail\StoreMailConfiguration;
use Illuminate\Support\Facades\Mail;
use PHPUnit\Framework\Attributes\DataProvider;
use ReflectionMethod;
use Tests\TestCase;

class StoreMailConfigurationTest extends TestCase
{
    #[DataProvider('safeModeProfiles')]
    public function test_safe_mode_honors_only_enabled_deployed_lavaggio_smtp(string $environment, string $storefront, bool $enabled, string $expected): void
    {
        $this->applySettings($environment, $storefront, $this->settings(['enabled' => $enabled]));

        $this->assertSame($expected, config('mail.default'));
        $this->assertTrue(config('commerce.local_safe_mode'));
        Mail::assertNothingSent();
        Mail::assertNothingQueued();
    }

    public static function safeModeProfiles(): array
    {
        return [
            'staging Lavaggio enabled' => ['staging', 'lavaggio', true, 'smtp'],
            'production Lavaggio enabled' => ['production', 'lavaggio', true, 'smtp'],
            'staging Lavaggio disabled' => ['staging', 'lavaggio', false, 'log'],
            'local Lavaggio' => ['local', 'lavaggio', true, 'log'],
            'testing Lavaggio' => ['testing', 'lavaggio', true, 'log'],
            'unrecognized environment' => ['preview', 'lavaggio', true, 'log'],
            'staging Watchline' => ['staging', 'watchline', true, 'log'],
            'production other storefront' => ['production', 'termol', true, 'log'],
        ];
    }

    public function test_admin_smtp_configuration_trims_identity_fields_and_preserves_password(): void
    {
        $settings = $this->settings([
            'host' => ' mail.example.invalid ', 'username' => ' shop@example.invalid ',
            'password' => ' fixture password ', 'from_address' => ' shop@example.invalid ',
            'from_name' => ' Lavaggio ', 'reply_to' => ' help@example.invalid ',
        ]);
        config(['mail.mailers.smtp.url' => 'smtp://old.example.invalid', 'mail.mailers.smtp.scheme' => 'smtps']);
        $this->applySettings('staging', 'lavaggio', $settings);

        $this->assertSame('mail.example.invalid', config('mail.mailers.smtp.host'));
        $this->assertSame('shop@example.invalid', config('mail.mailers.smtp.username'));
        $this->assertSame(' fixture password ', config('mail.mailers.smtp.password'));
        $this->assertSame('shop@example.invalid', config('mail.from.address'));
        $this->assertSame('Lavaggio', config('mail.from.name'));
        $this->assertSame('help@example.invalid', config('mail.reply_to.address'));
        $this->assertSame('smtp', config('mail.mailers.smtp.scheme'));
        $this->assertTrue(config('mail.mailers.smtp.require_tls'));
        $this->assertNull(config('mail.mailers.smtp.url'));
        $this->assertSame($settings, $this->settingsMock->email());
        Mail::assertNothingSent();
    }

    #[DataProvider('invalidSmtpSettings')]
    public function test_incomplete_or_invalid_smtp_keeps_safe_mode_mail_local(array $override): void
    {
        $settings = $this->settings($override);
        $this->assertNotNull(StoreMailConfiguration::smtpConfigurationError($settings));
        $this->applySettings('staging', 'lavaggio', $settings);

        $this->assertSame('log', config('mail.default'));
        Mail::assertNothingSent();
    }

    public static function invalidSmtpSettings(): array
    {
        return [
            'not SMTP' => [['mailer' => 'sendmail']],
            'host absent' => [['host' => ' ']],
            'host contains whitespace' => [['host' => 'mail example.invalid']],
            'port absent' => [['port' => null]],
            'invalid port' => [['port' => 65536]],
            'username absent' => [['username' => ' ']],
            'password absent' => [['password' => '']],
            'invalid sender' => [['from_address' => 'invalid']],
            'invalid reply-to' => [['reply_to' => 'invalid']],
        ];
    }

    public function test_regular_non_safe_storefront_keeps_admin_mail_behavior(): void
    {
        $this->applySettings('production', 'watchline', $this->settings(['encryption' => 'ssl', 'port' => 465]), false);

        $this->assertSame('smtp', config('mail.default'));
        $this->assertSame('smtps', config('mail.mailers.smtp.scheme'));
        $this->assertFalse(config('mail.mailers.smtp.require_tls'));
        Mail::assertNothingSent();
    }

    private StoreSettingsService $settingsMock;

    private function applySettings(string $environment, string $storefront, array $settings, bool $safeMode = true): void
    {
        Mail::fake();
        $this->app['env'] = $environment;
        config(['app.env' => $environment, 'storefront.home' => $storefront, 'commerce.local_safe_mode' => $safeMode, 'mail.default' => 'array']);
        $this->settingsMock = $this->mock(StoreSettingsService::class);
        $this->settingsMock->shouldReceive('email')->andReturn($settings);

        $method = new ReflectionMethod(AppServiceProvider::class, 'applyDynamicStoreMailSettings');
        $method->invoke(new AppServiceProvider($this->app));
    }

    private function settings(array $override = []): array
    {
        return array_replace([
            'enabled' => true, 'mailer' => 'smtp', 'host' => 'mail.example.invalid', 'port' => 587,
            'username' => 'shop@example.invalid', 'password' => 'fixture-password', 'encryption' => 'tls',
            'from_address' => 'shop@example.invalid', 'from_name' => 'Lavaggio', 'reply_to' => '',
        ], $override);
    }
}
