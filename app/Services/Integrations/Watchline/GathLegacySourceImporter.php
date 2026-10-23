<?php

namespace App\Services\Integrations\Watchline;

use Illuminate\Support\Facades\Crypt;
use RuntimeException;

class GathLegacySourceImporter
{
    public function __construct(private readonly GathCsvReader $reader) {}

    /** Archive the existing local source without including or executing legacy PHP. */
    public function import(string $sourceRoot, ?string $destinationDirectory = null): array
    {
        if (config('storefront.home') !== 'watchline' || ! app()->environment(['local', 'testing'])) {
            throw new RuntimeException('Prijenos izvornog GATH izvještaja dostupan je samo u lokalnom Watchline projektu.');
        }
        $sourceRoot = realpath($sourceRoot);
        if ($sourceRoot === false || ! is_dir($sourceRoot)) {
            throw new RuntimeException('Izvorni lokalni projekt nije dostupan.');
        }
        $sourceCsv = $sourceRoot.'/webstanje.csv';
        $feed = $this->reader->read($sourceCsv);
        $bytes = file_get_contents($sourceCsv);
        if ($bytes === false || ! hash_equals($feed['checksum'], hash('sha256', $bytes))) {
            throw new RuntimeException('Izvorna GATH datoteka promijenjena je tijekom čitanja. Pokušajte ponovno.');
        }
        $directory = $destinationDirectory ?? storage_path('app/private/watchline/gath');
        if (str_contains($directory, '://') || (! is_dir($directory) && ! mkdir($directory, 0700, true))) {
            throw new RuntimeException('Privatno spremište nije dostupno.');
        }
        chmod($directory, 0700);
        $csvPath = $directory.'/webstanje.csv';
        $this->writePrivate($csvPath, $bytes);
        touch($csvPath, (int) filemtime($sourceCsv));

        $connection = $this->readDiagnosticConnection($sourceRoot.'/gath.php');
        $connectionPath = null;
        if ($connection !== null) {
            $connectionPath = $directory.'/legacy-soap-connection.encrypted';
            $payload = json_encode(['version' => 1, 'purpose' => 'legacy_soap_diagnostic'] + $connection, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
            $unchanged = false;
            if (is_file($connectionPath)) {
                try {
                    $unchanged = hash_equals($payload, Crypt::decryptString(file_get_contents($connectionPath)));
                } catch (\Throwable) {
                    // A previous app key cannot be used to read an old archive.
                }
            }
            if (! $unchanged) {
                $this->writePrivate($connectionPath, Crypt::encryptString($payload));
            }
            chmod($connectionPath, 0600);
        }

        return [
            'csv_path' => $csvPath, 'csv_relative_path' => 'watchline/gath/webstanje.csv',
            'checksum' => $feed['checksum'], 'fetched_count' => $feed['fetched_count'],
            'bytes' => $feed['bytes'], 'credentials_present' => $connection !== null,
            'credentials_archive_path' => $connectionPath, 'external_requests' => 0,
        ];
    }

    private function readDiagnosticConnection(string $path): ?array
    {
        if (! is_file($path) || ! is_readable($path) || filesize($path) > 2097152) {
            return null;
        }
        $source = file_get_contents($path);
        if ($source === false) {
            return null;
        }
        $tokens = token_get_all($source);
        $values = [];
        $names = ['$wsdl_url' => 'wsdl_url', '$wsdl_user' => 'username', '$wsdl_pass' => 'password'];
        for ($index = 0; $index < count($tokens); $index++) {
            $token = $tokens[$index];
            if (! is_array($token) || $token[0] !== T_VARIABLE || ! isset($names[$token[1]])) {
                continue;
            }
            $equals = $this->nextToken($tokens, $index + 1);
            $literal = $this->nextToken($tokens, $equals + 1);
            $end = $this->nextToken($tokens, $literal + 1);
            if (($tokens[$equals] ?? null) !== '=' || ! is_array($tokens[$literal] ?? null)
                || $tokens[$literal][0] !== T_CONSTANT_ENCAPSED_STRING || ($tokens[$end] ?? null) !== ';') {
                continue;
            }
            $text = $tokens[$literal][1];
            $body = substr($text, 1, -1);
            $values[$names[$token[1]]] = $text[0] === "'"
                ? preg_replace_callback('/\\\\([\\\\\'])/', fn (array $match): string => $match[1], $body)
                : stripcslashes($body);
        }
        if (count($values) !== 3 || in_array('', $values, true)) {
            return null;
        }

        return $values;
    }

    private function nextToken(array $tokens, int $index): int
    {
        while (isset($tokens[$index]) && is_array($tokens[$index]) && in_array($tokens[$index][0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
            $index++;
        }

        return $index;
    }

    private function writePrivate(string $path, string $contents): void
    {
        if (is_file($path) && hash_equals(hash('sha256', $contents), hash_file('sha256', $path))) {
            chmod($path, 0600);

            return;
        }
        $temporary = tempnam(dirname($path), '.gath-');
        if ($temporary === false) {
            throw new RuntimeException('Privatnu GATH kopiju nije moguće spremiti.');
        }
        try {
            chmod($temporary, 0600);
            if (file_put_contents($temporary, $contents, LOCK_EX) !== strlen($contents) || ! rename($temporary, $path)) {
                throw new RuntimeException('Privatnu GATH kopiju nije moguće spremiti.');
            }
            chmod($path, 0600);
        } finally {
            if (is_file($temporary)) {
                unlink($temporary);
            }
        }
    }
}
