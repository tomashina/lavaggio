<?php

namespace Tests\Feature\Import;

use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class LavaggioMailPreflightTest extends TestCase
{
    #[DataProvider('mailProfiles')]
    public function test_actual_deploy_settings_guard_reads_without_writes_and_preserves_integration_protections(array $overrides, bool $succeeds, string $expected): void
    {
        $script = file_get_contents(base_path('scripts/deploy-lavaggio-cpanel.sh'));
        $start = strpos($script, '$settings = [];');
        $end = strpos($script, '$manifest = ', $start);
        $this->assertNotFalse($start);
        $this->assertNotFalse($end);
        $guard = substr($script, $start, $end - $start);
        $settings = array_replace([
            'store_email_enabled' => true, 'store_email_mailer' => 'smtp',
            'store_email_smtp_host' => ' mail.example.invalid ', 'store_email_smtp_port' => 587,
            'store_email_smtp_username' => ' shop@example.invalid ', 'store_email_smtp_password' => ' fixture password ',
            'store_email_smtp_encryption' => 'tls', 'store_email_from_address' => ' shop@example.invalid ',
            'store_email_reply_to' => '', 'gls_api_enabled' => false, 'watchline_integrations' => [],
        ], $overrides);

        $harness = '<?php' . "\n"
            .'function stopDeploy(string $message): never { fwrite(STDERR, $message); exit(1); }'."\n"
            .'$root = '.var_export(base_path(), true).';'."\n"
            .'$values = ["STOREFRONT_HOME" => "lavaggio", "APP_ENV" => "staging"]; $connection = "sqlite";'."\n"
            .'$pdo = new PDO("sqlite::memory:"); $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);'."\n"
            .'$pdo->exec("CREATE TABLE system_settings (`key` TEXT PRIMARY KEY, `value` TEXT)");'."\n"
            .'$insert = $pdo->prepare("INSERT INTO system_settings (`key`, `value`) VALUES (?, ?)");'."\n"
            .'foreach ('.var_export($settings, true).' as $key => $value) { $insert->execute([$key, json_encode($value)]); }'."\n"
            .'$before = $pdo->query("SELECT * FROM system_settings ORDER BY `key`")->fetchAll();'."\n"
            .'$pdo->exec("PRAGMA query_only=ON");'."\n"
            .$guard
            .'if ($before !== $pdo->query("SELECT * FROM system_settings ORDER BY `key`")->fetchAll()) { exit(2); } echo "unchanged";';
        $process = new Process([PHP_BINARY]);
        $process->setInput($harness)->run();

        $this->assertSame($succeeds, $process->isSuccessful(), $process->getErrorOutput());
        $this->assertStringContainsString($expected, $process->getOutput().$process->getErrorOutput());
        $this->assertStringNotContainsString('fixture password', $process->getOutput().$process->getErrorOutput());
    }

    public static function mailProfiles(): array
    {
        return [
            'explicit SMTP with trimmed identity' => [[], true, 'unchanged'],
            'disabled email remains valid' => [['store_email_enabled' => false, 'store_email_smtp_password' => ''], true, 'unchanged'],
            'enabled log is not delivery' => [['store_email_mailer' => 'log'], false, 'explicitly configured SMTP'],
            'missing SMTP credential' => [['store_email_smtp_password' => ''], false, 'stored username and password'],
            'GLS remains disabled' => [['gls_api_enabled' => true], false, 'GLS API must remain disabled'],
            'inherited integration remains disabled' => [['watchline_integrations' => ['live_enabled' => true]], false, 'Inherited integrations must remain disabled'],
            'inherited schedule remains disabled' => [['watchline_integrations' => ['schedules' => [['enabled' => true]]]], false, 'Inherited integration schedules must remain disabled'],
        ];
    }
}
