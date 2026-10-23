<?php

namespace App\Services\Import;

use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/** Uses the existing separated staff identities and encrypted OpenCart verifier. */
class WatchlineStaffImportService extends HerreraStaffImportService
{
    public function import(ConnectionInterface $source, string $prefix = 'oc_', bool $apply = false, bool $finalSync = false): array
    {
        if ($finalSync) {
            throw new RuntimeException('Watchline staff imports do not support Herrera final sync.');
        }

        return parent::import($source, $prefix, $apply);
    }

    protected function guard(ConnectionInterface $source, string $prefix, bool $finalSync = false): void
    {
        $target = DB::connection();
        if (! app()->environment(['local', 'testing'])
            || ! preg_match('/^[a-zA-Z0-9_]+$/', $prefix)
            || $source === $target
            || ($source->getDriverName() === $target->getDriverName() && $source->getDatabaseName() === $target->getDatabaseName())) {
            throw new RuntimeException('Watchline staff requires separate isolated local source and target databases.');
        }
        if (! app()->environment('testing') && (
            $source->getName() !== 'watchline_source'
            || ! in_array($source->getConfig('host'), ['127.0.0.1', 'localhost', '::1'], true)
            || (int) $source->getConfig('port') !== 33107
            || ! preg_match('/\Awatchline_snapshot_[0-9]{8}\z/', $source->getDatabaseName())
            || $target->getDriverName() !== 'sqlite'
            || realpath($target->getDatabaseName()) !== realpath(database_path('database.sqlite'))
            || rtrim((string) config('app.url'), '/') !== 'http://watchline-new.test'
        )) {
            throw new RuntimeException('Watchline staff imports are restricted to this verified local Watchline preview.');
        }
        foreach (['user', 'user_group'] as $table) {
            if (! $source->getSchemaBuilder()->hasTable($prefix.$table)) {
                throw new RuntimeException('The Watchline staff source is incomplete.');
            }
        }
        foreach (['account_type', 'admin_username', 'admin_login_enabled'] as $column) {
            if (! $target->getSchemaBuilder()->hasColumn('users', $column)) {
                throw new RuntimeException('Install the staff identity migration before importing.');
            }
        }
        if (! $target->getSchemaBuilder()->hasTable('admin_customer_assignments')) {
            throw new RuntimeException('Install the staff customer-assignment migration before importing.');
        }
    }

    protected function sourceAssignments(ConnectionInterface $source, string $prefix): Collection
    {
        // Stock OpenCart has no Herrera customer-to-salesperson extension.
        return $source->getSchemaBuilder()->hasTable($prefix.'customer_to_user')
            ? parent::sourceAssignments($source, $prefix)
            : collect();
    }
}
