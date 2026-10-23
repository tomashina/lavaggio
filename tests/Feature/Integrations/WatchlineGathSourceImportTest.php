<?php

namespace Tests\Feature\Integrations;

use App\Services\Integrations\Watchline\GathLegacySourceImporter;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class WatchlineGathSourceImportTest extends TestCase
{
    public function test_source_php_is_never_executed_and_original_csv_and_encrypted_connection_are_private_and_repeatable(): void
    {
        config(['storefront.home' => 'watchline']);
        Http::preventStrayRequests();
        $directory = sys_get_temp_dir().'/watchline-gath-source-'.bin2hex(random_bytes(8));
        mkdir($directory.'/source', 0700, true);
        $marker = $directory.'/executed';
        $bytes = "\xEF\xBB\xBF00123;2;12,3456;CLUSE\r\n";
        file_put_contents($directory.'/source/webstanje.csv', $bytes);
        $php = "<?php\n".'file_put_contents('.var_export($marker, true).', "wrong");'."\n"
            .'$wsdl_url = \'https://diagnostic.example/service?wsdl\';'."\n"
            .'$wsdl_user = \'fixture-user\';'."\n"
            .'$wsdl_pass = \'fixture-password\';'."\n"
            .'throw new RuntimeException("Never execute this file");';
        file_put_contents($directory.'/source/gath.php', $php);
        try {
            $importer = app(GathLegacySourceImporter::class);
            $report = $importer->import($directory.'/source', $directory.'/private');

            $this->assertSame($bytes, file_get_contents($report['csv_path']));
            $this->assertSame(hash('sha256', $bytes), $report['checksum']);
            $this->assertSame(1, $report['fetched_count']);
            $this->assertTrue($report['credentials_present']);
            $this->assertFileDoesNotExist($marker);
            $encrypted = file_get_contents($report['credentials_archive_path']);
            $this->assertStringNotContainsString('fixture-password', $encrypted);
            $connection = json_decode(Crypt::decryptString($encrypted), true, flags: JSON_THROW_ON_ERROR);
            $this->assertSame('fixture-password', $connection['password']);
            $this->assertSame('fixture-user', $connection['username']);
            $this->assertSame('legacy_soap_diagnostic', $connection['purpose']);
            $this->assertSame(0600, fileperms($report['csv_path']) & 0777);
            $this->assertSame(0600, fileperms($report['credentials_archive_path']) & 0777);
            $this->assertSame(0700, fileperms($directory.'/private') & 0777);

            $repeated = $importer->import($directory.'/source', $directory.'/private');

            $this->assertSame($report, $repeated);
            $this->assertSame($encrypted, file_get_contents($repeated['credentials_archive_path']));
            $this->assertSame($php, file_get_contents($directory.'/source/gath.php'));
            Http::assertNothingSent();
        } finally {
            File::deleteDirectory($directory);
        }
    }

    public function test_dynamic_php_expressions_are_not_interpreted_as_credentials(): void
    {
        config(['storefront.home' => 'watchline']);
        $directory = sys_get_temp_dir().'/watchline-gath-dynamic-'.bin2hex(random_bytes(8));
        mkdir($directory.'/source', 0700, true);
        file_put_contents($directory.'/source/webstanje.csv', "SKU;1;20,00\n");
        file_put_contents($directory.'/source/gath.php', '<?php $wsdl_url = "https://example.test"; $wsdl_user = "prefix" . getenv("USER"); $wsdl_pass = getenv("PASSWORD");');
        try {
            $report = app(GathLegacySourceImporter::class)->import($directory.'/source', $directory.'/private');
            $this->assertFalse($report['credentials_present']);
            $this->assertNull($report['credentials_archive_path']);
            $this->assertFileDoesNotExist($directory.'/private/legacy-soap-connection.encrypted');
        } finally {
            File::deleteDirectory($directory);
        }
    }
}
