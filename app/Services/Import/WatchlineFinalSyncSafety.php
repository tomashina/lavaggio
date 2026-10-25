<?php

namespace App\Services\Import;

use Illuminate\Database\ConnectionInterface;
use RuntimeException;

/** A Watchline refresh may write only a newly generated, authenticated private SQLite copy. */
class WatchlineFinalSyncSafety
{
    public static function frozenMySqlSource(array $config): bool
    {
        return in_array($config['driver'] ?? '', ['mysql', 'mariadb'], true)
            && (bool) preg_match('/\Awatchline_snapshot_[0-9]{8}(?:_[a-zA-Z0-9_]+)?\z/', (string) ($config['database'] ?? ''));
    }

    public static function assertCandidate(array $config): void
    {
        HerreraCutoverSafety::assertNoSplitConnection($config);
        $file = (string) ($config['database'] ?? '');
        clearstatcache(true, $file);
        $root = storage_path('app/private/watchline/final-sync');
        $relative = substr($file, strlen($root) + 1);
        if (! app()->environment(['local', 'testing']) || config('storefront.home') !== 'watchline' || ! filled(config('app.key'))
            || ($config['driver'] ?? '') !== 'sqlite'
            || ! str_starts_with($file, $root.'/')
            || ! preg_match('~\A[0-9]{8}_[0-9]{6}_[a-f0-9]{12}/target\.sqlite\z~', $relative)
            || ! is_file($file) || is_link($file) || realpath($file) !== $file
            || (stat($file)['nlink'] ?? 0) !== 1) {
            throw new RuntimeException('Watchline final sync accepts only a new private rehearsal SQLite candidate.');
        }
        $proof = dirname($file).'/candidate.json';
        clearstatcache(true, $proof);
        if (! is_file($proof) || is_link($proof) || realpath($proof) !== $proof) {
            throw new RuntimeException('The isolated Watchline candidate proof is missing.');
        }
        $document = json_decode(file_get_contents($proof), true, 512, JSON_THROW_ON_ERROR);
        $signature = $document['signature'] ?? '';
        unset($document['signature']);
        if (($config['watchline_candidate_proof'] ?? '') !== $proof
            || ($document['candidate'] ?? '') !== $file
            || ($document['original'] ?? '') === $file
            || ! hash_equals(self::signature($document), (string) $signature)) {
            throw new RuntimeException('The Watchline rehearsal candidate proof does not match this connection.');
        }
        $original = (string) ($document['original'] ?? '');
        if (! is_file($original) || (stat($original)['ino'] ?? null) === (stat($file)['ino'] ?? null)) {
            throw new RuntimeException('A rehearsal candidate cannot alias its original database.');
        }
    }

    public static function signCandidate(string $candidate, string $original): string
    {
        $proof = dirname($candidate).'/candidate.json';
        $document = ['version' => 1, 'candidate' => $candidate, 'original' => $original, 'created_at' => now()->toIso8601String()];
        $document['signature'] = self::signature($document);
        if (file_put_contents($proof, json_encode($document, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT), LOCK_EX) === false) {
            throw new RuntimeException('The candidate proof could not be written.');
        }
        chmod($proof, 0600);

        return $proof;
    }

    public static function validate(ConnectionInterface $target, ConnectionInterface $baseline, ConnectionInterface $fresh, string $prefix, string $baselineDate, string $freshDate): void
    {
        if (! app()->environment(['local', 'testing']) || config('storefront.home') !== 'watchline') {
            throw new RuntimeException('Watchline final-sync planning is local/testing only; production apply is not provided.');
        }
        if (! preg_match('/\A[a-zA-Z0-9_]+\z/', $prefix)) {
            throw new RuntimeException('A valid source prefix is required.');
        }
        foreach ([$baselineDate, $freshDate] as $date) {
            if (! preg_match('/\A(\d{4})-(\d{2})-(\d{2})\z/', $date, $parts)
                || ! checkdate((int) ($parts[2] ?? 0), (int) ($parts[3] ?? 0), (int) ($parts[1] ?? 0))) {
                throw new RuntimeException('Provide the actual baseline and fresh snapshot dates (YYYY-MM-DD).');
            }
        }
        if ($freshDate < $baselineDate || $freshDate > now()->toDateString()) {
            throw new RuntimeException('The fresh snapshot date must follow the baseline and cannot be in the future.');
        }
        if ($baseline->getDriverName() === $fresh->getDriverName()
            && $baseline->getDatabaseName() === $fresh->getDatabaseName()
            && $baseline->getConfig('host') === $fresh->getConfig('host')
            && $baseline->getConfig('port') === $fresh->getConfig('port')) {
            throw new RuntimeException('Baseline and fresh snapshots must have different immutable database identities.');
        }
        foreach ([$target, $baseline, $fresh] as $connection) {
            HerreraCutoverSafety::assertNoSplitConnection($connection->getConfig());
        }
        if ($target->getDriverName() !== 'sqlite' || ! is_file($target->getDatabaseName())
            || realpath($target->getDatabaseName()) !== $target->getDatabaseName()
            || (! app()->environment('testing') && realpath($target->getDatabaseName()) !== realpath(database_path('database.sqlite')))) {
            throw new RuntimeException('The rehearsal starts from the verified local Watchline SQLite application database.');
        }
        foreach ([[$baseline, $baselineDate], [$fresh, $freshDate]] as [$source, $captureDate]) {
            if ($source === $target || ($source->getDriverName() === 'sqlite' && realpath($source->getDatabaseName()) === realpath($target->getDatabaseName()))) {
                throw new RuntimeException('The original target and each frozen source must be separate.');
            }
            if (in_array($source->getDriverName(), ['mysql', 'mariadb'], true)) {
                if (! self::frozenMySqlSource($source->getConfig()) || ! in_array($source->getConfig('host'), ['127.0.0.1', 'localhost', '::1'], true)) {
                    throw new RuntimeException('Restore a frozen Watchline snapshot locally; direct remote/live source connections are rejected.');
                }
                if (! str_starts_with($source->getDatabaseName(), 'watchline_snapshot_'.str_replace('-', '', $captureDate))) {
                    throw new RuntimeException('The frozen snapshot name must match its declared capture date.');
                }
                $source->getPdo()->exec('SET SESSION TRANSACTION READ ONLY');
            } elseif ($source->getDriverName() === 'sqlite' && app()->environment('testing')) {
                $source->getPdo()->exec('PRAGMA query_only=ON');
            } else {
                throw new RuntimeException('Only frozen local MySQL snapshots are supported outside isolated tests.');
            }
            if ($source->getConfig('import_order_prefix') !== 'OC-WATCHLINE-') {
                throw new RuntimeException('Each source connection must declare the Watchline historical order prefix.');
            }
        }
        if ($baseline->getDriverName() !== $fresh->getDriverName()) {
            throw new RuntimeException('Use matching source snapshot formats for reproducible fingerprints.');
        }
    }

    private static function signature(array $document): string
    {
        return hash_hmac('sha256', json_encode($document, JSON_THROW_ON_ERROR), (string) config('app.key'));
    }
}
