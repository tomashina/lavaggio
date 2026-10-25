<?php

namespace App\Services\Import;

use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;
use SQLite3;
use Throwable;

/** Plans without writes; rehearses the conservative core merge only on a disposable local copy. */
class WatchlineFinalSyncService
{
    private const STAGES = ['catalog', 'customers', 'billing_addresses', 'orders', 'seo', 'relations'];

    private const CORE_KEYS = [
        'product' => ['product_id'], 'product_description' => ['product_id', 'language_id'],
        'category' => ['category_id'], 'category_description' => ['category_id', 'language_id'],
        'manufacturer' => ['manufacturer_id'], 'manufacturer_description' => ['manufacturer_id', 'language_id'],
        'customer' => ['customer_id'], 'customer_group' => ['customer_group_id'], 'address' => ['address_id'],
        'order' => ['order_id'], 'order_product' => ['order_product_id'], 'order_total' => ['order_total_id'],
        'order_history' => ['order_history_id'], 'order_status' => ['order_status_id', 'language_id'],
        'product_special' => ['product_special_id'], 'product_discount' => ['product_discount_id'],
        'product_to_category' => ['product_id', 'category_id'], 'product_related' => ['product_id', 'related_id'],
        'user' => ['user_id'], 'user_group' => ['user_group_id'], 'review' => ['review_id'],
        'url_alias' => ['url_alias_id'], 'seo_url' => ['seo_url_id'],
    ];

    private const ENTITY_TABLES = ['product' => 'products', 'category' => 'categories', 'manufacturer' => 'catalog_manufacturers',
        'customer' => 'users', 'profile' => 'user_profiles', 'address' => 'user_addresses', 'order' => 'orders',
        'order_product' => 'order_items', 'order_total' => 'order_totals', 'order_history' => 'order_history'];

    public function plan(string $baselineConnection, string $sourceConnection, string $prefix, string $baselineDate, string $sourceDate): array
    {
        $target = DB::connection();
        $baseline = DB::connection($baselineConnection);
        $this->configureFreshAlias($baseline, $sourceConnection);
        $fresh = DB::connection($sourceConnection);
        WatchlineFinalSyncSafety::validate($target, $baseline, $fresh, $prefix, $baselineDate, $sourceDate);
        $before = $this->snapshot($baseline, $prefix);
        $after = $this->snapshot($fresh, $prefix);
        $deltas = [];
        foreach (array_unique([...array_keys($before), ...array_keys($after)]) as $table) {
            $old = $before[$table]['rows'] ?? [];
            $new = $after[$table]['rows'] ?? [];
            $deltas[$table] = [
                'baseline_rows' => count($old), 'fresh_rows' => count($new),
                'new' => count(array_diff_key($new, $old)),
                'changed' => count(array_filter(array_intersect_key($new, $old), fn ($hash, $key) => $hash !== $old[$key], ARRAY_FILTER_USE_BOTH)),
                'removed' => count(array_diff_key($old, $new)),
                'matching' => count(array_intersect_assoc($new, $old)),
                'key_mode' => $after[$table]['key_mode'] ?? $before[$table]['key_mode'],
            ];
        }
        $blockers = [];
        foreach (['product', 'category', 'product_to_category', 'customer', 'customer_group', 'address', 'order', 'order_product', 'order_total', 'order_history'] as $table) {
            if (! isset($before[$table], $after[$table])) {
                $blockers[] = 'incomplete_core_snapshot';
            }
        }
        if (array_diff_key($before, $after) !== []) {
            $blockers[] = 'source_tables_removed_review_required';
        }
        foreach (['product', 'customer', 'order'] as $entity) {
            if (($before[$entity]['rows'] ?? []) !== [] && ($after[$entity]['rows'] ?? []) === []) {
                $blockers[] = 'empty_core_source_table';
            }
        }
        if (($deltas['customer']['removed'] ?? 0) > 0) {
            // The generic B2B removal path does not retire B2C-only identities.
            $blockers[] = 'removed_customer_identity_requires_review';
        }
        $baselineMismatches = $this->baselineMismatches($baseline, $prefix);
        if ($baselineMismatches > 0) {
            $blockers[] = 'baseline_does_not_match_imported_core';
        }
        $missingTargets = 0;
        foreach (self::ENTITY_TABLES as $entity => $table) {
            $missingTargets += $target->table('herrera_import_maps as map')->where('map.source', 'herrera-opencart')->where('map.entity', $entity)
                ->leftJoin($table.' as native', 'native.id', '=', 'map.target_id')->whereNull('native.id')->count();
        }
        if ($missingTargets > 0) {
            $blockers[] = 'mapped_native_targets_missing';
        }
        $identityRisks = $this->identityRisks($fresh, $prefix);
        if ($identityRisks['duplicate_customer_emails'] > 0 || $identityRisks['duplicate_source_ids'] > 0) {
            $blockers[] = 'ambiguous_source_identities';
        }
        if ($identityRisks['mapped_customer_is_staff'] > 0 || $identityRisks['mapping_target_aliases'] > 0 || $identityRisks['mapped_source_identity_mismatches'] > 0) {
            $blockers[] = 'unsafe_native_identity_mapping';
        }
        if ($identityRisks['native_customer_email_collisions'] > 0) {
            $blockers[] = 'native_customer_identity_collision';
        }
        $domains = [
            'staff' => ['user', 'user_group', 'customer_to_user'],
            'promotions' => ['product_special', 'product_discount', 'anchor_price'],
            'supplement' => ['review', 'blog_comment', 'testimonial', 'testimonial_description', 'newsletter', 'tf_filter', 'tf_filter_value', 'tf_filter_value_to_product', 'customer_reward', 'order_option', 'blog_related_products', 'manufacturer_description', 'question'],
            'legacy_sales' => ['coupon', 'coupon_history', 'coupon_product', 'coupon_category', 'voucher', 'voucher_history', 'return', 'return_history', 'order_voucher'],
        ];
        $pending = [];
        foreach ($domains as $domain => $tables) {
            $counts = ['new' => 0, 'changed' => 0, 'removed' => 0];
            foreach ($tables as $table) {
                foreach (array_keys($counts) as $key) {
                    $counts[$key] += $deltas[$table][$key] ?? 0;
                }
            }
            $pending[$domain] = $counts + ['requires_review' => array_sum($counts) > 0];
        }
        // Fixed-amount imported offers depend on the source base as well as the special row.
        $pending['promotions']['dependent_product_changes'] = ($deltas['product']['new'] ?? 0) + ($deltas['product']['changed'] ?? 0) + ($deltas['product']['removed'] ?? 0);
        $pending['promotions']['requires_review'] = $pending['promotions']['requires_review'] || $pending['promotions']['dependent_product_changes'] > 0;
        $pending['supplement']['dependent_customer_changes'] = ($deltas['customer']['new'] ?? 0) + ($deltas['customer']['changed'] ?? 0);
        $pending['supplement']['requires_review'] = $pending['supplement']['requires_review'] || $pending['supplement']['dependent_customer_changes'] > 0;

        return [
            'version' => 1, 'status' => $blockers === [] ? 'planned' : 'blocked', 'mode' => 'read-only-plan',
            'created_at' => now()->toIso8601String(), 'ready_for_live' => false, 'production_modified' => false,
            'rehearsal_completed' => false, 'baseline_date' => $baselineDate, 'source_date' => $sourceDate,
            'source_fingerprints' => ['baseline' => $this->fingerprints($before), 'fresh' => $this->fingerprints($after)],
            'table_deltas' => $deltas, 'pending_domains' => $pending,
            'baseline_core_mismatches' => $baselineMismatches, 'missing_native_targets' => $missingTargets,
            'identity_risks' => $identityRisks,
            'blockers' => array_values(array_unique($blockers)),
            'remaining_gates' => $this->remainingGates(),
        ];
    }

    public function rehearse(string $baselineConnection, string $sourceConnection, string $prefix, string $baselineDate, string $sourceDate): array
    {
        $report = $this->plan($baselineConnection, $sourceConnection, $prefix, $baselineDate, $sourceDate);
        if ($report['status'] === 'blocked') {
            return $report;
        }
        $originalDefault = DB::getDefaultConnection();
        $original = DB::connection();
        $originalState = $this->fingerprints($this->snapshot($original, ''));
        $run = now()->format('Ymd_His').'_'.bin2hex(random_bytes(6));
        $directory = storage_path('app/private/watchline/final-sync/'.$run);
        if (! mkdir($directory, 0700, true)) {
            throw new RuntimeException('A new private rehearsal directory could not be created.');
        }
        $candidate = $directory.'/target.sqlite';
        $copySource = new SQLite3($original->getDatabaseName(), SQLITE3_OPEN_READONLY);
        $copyTarget = new SQLite3($candidate, SQLITE3_OPEN_READWRITE | SQLITE3_OPEN_CREATE);
        try {
            if (! $copySource->backup($copyTarget)) {
                throw new RuntimeException('The consistent SQLite rehearsal copy failed.');
            }
        } finally {
            $copyTarget->close();
            $copySource->close();
        }
        chmod($candidate, 0600);
        $proof = WatchlineFinalSyncSafety::signCandidate($candidate, $original->getDatabaseName());
        $candidateConfig = HerreraCutoverSafety::isolatedConnectionConfig($original->getConfig());
        config(['database.connections.watchline_final_candidate' => array_replace($candidateConfig, [
            'database' => $candidate, 'watchline_candidate_proof' => $proof,
        ])]);
        DB::purge('watchline_final_candidate');
        $report += ['run_id' => $run, 'candidate' => 'watchline/final-sync/'.$run.'/target.sqlite'];
        $report['mode'] = 'isolated-core-rehearsal';
        $oldSafety = config('commerce.local_safe_mode');
        try {
            DB::setDefaultConnection('watchline_final_candidate');
            config(['database.default' => 'watchline_final_candidate', 'commerce.local_safe_mode' => true]);
            Schema::clearResolvedInstance('db.schema');
            WatchlineFinalSyncSafety::assertCandidate(DB::connection()->getConfig());
            $protected = $this->protectedSelection();
            $protected['derived_order_overrides'] = $this->derivedOrderOverrides(DB::connection($baselineConnection), $prefix);
            $report['native_derived_order_override_fields'] = array_sum(array_map('count', $protected['derived_order_overrides']));
            $protectedBefore = $this->protectedState($protected);
            $report['baseline_import'] = $this->coreImport($baselineConnection, $prefix);
            $this->restoreDerivedOrderOverrides($protected['derived_order_overrides']);
            $this->restoreProfilePreferences($protected['profile_preferences']);
            $report['refresh_import'] = $this->coreImport($sourceConnection, $prefix);
            $this->restoreDerivedOrderOverrides($protected['derived_order_overrides']);
            $this->restoreProfilePreferences($protected['profile_preferences']);
            $once = $this->reconciliationState();
            $report['repeat_import'] = $this->coreImport($sourceConnection, $prefix);
            $this->restoreDerivedOrderOverrides($protected['derived_order_overrides']);
            $this->restoreProfilePreferences($protected['profile_preferences']);
            $report['repeatable'] = $once === $this->reconciliationState();
            $report['protected_data_preserved'] = $protectedBefore === $this->protectedState($protected);
            $report['reconciliation'] = $this->reconcile(DB::connection($sourceConnection), $prefix);
            $report['core_conflicts'] = array_sum(array_map(fn ($key) => (int) ($report[$key]['conflicts'] ?? 0), ['baseline_import', 'refresh_import', 'repeat_import']));
            $report['core_rehearsal_passed'] = $report['repeatable'] && $report['protected_data_preserved']
                && $report['core_conflicts'] === 0 && ! in_array(false, $report['reconciliation'], true);
            $report['rehearsal_completed'] = true;
            $report['status'] = $report['core_rehearsal_passed'] ? 'core_rehearsed_review_required' : 'rehearsal_blocked';
        } catch (Throwable $exception) {
            // Exception messages/query bindings may contain private source records.
            $report['status'] = 'rehearsal_failed';
            $report['error_class'] = get_class($exception);
        } finally {
            DB::disconnect('watchline_final_candidate');
            DB::setDefaultConnection($originalDefault);
            config(['database.default' => $originalDefault, 'commerce.local_safe_mode' => $oldSafety]);
            Schema::clearResolvedInstance('db.schema');
        }
        $report['original_unchanged'] = $originalState === $this->fingerprints($this->snapshot($original, ''));
        $report['sources_unchanged'] = $report['source_fingerprints'] === [
            'baseline' => $this->fingerprints($this->snapshot(DB::connection($baselineConnection), $prefix)),
            'fresh' => $this->fingerprints($this->snapshot(DB::connection($sourceConnection), $prefix)),
        ];
        if (! $report['original_unchanged'] || ! $report['sources_unchanged']) {
            $report['status'] = 'rehearsal_blocked';
            $report['core_rehearsal_passed'] = false;
        }

        return $report;
    }

    private function snapshot(ConnectionInterface $database, string $prefix): array
    {
        $result = [];
        foreach ($database->getSchemaBuilder()->getTables() as $table) {
            $name = $table['name'];
            if (! str_starts_with($name, $prefix) || ! preg_match('/\A[a-zA-Z0-9_]+\z/', $name)) {
                continue;
            }
            $logical = substr($name, strlen($prefix));
            $columns = $database->getSchemaBuilder()->getColumnListing($name);
            $keys = self::CORE_KEYS[$logical] ?? collect($database->getSchemaBuilder()->getIndexes($name))->firstWhere('primary', true)['columns'] ?? [];
            $keys = array_diff($keys, $columns) === [] ? $keys : [];
            $rows = [];
            foreach ($database->table($name)->cursor() as $row) {
                $data = (array) $row;
                ksort($data);
                $hash = hash('sha256', serialize($data));
                $key = $keys !== [] ? json_encode(array_intersect_key($data, array_flip($keys)), JSON_THROW_ON_ERROR) : $hash;
                // Preserve duplicate relationship rows instead of silently collapsing them.
                for ($suffix = 0; isset($rows[$key.':'.$suffix]); $suffix++) {
                }
                $rows[$key.':'.$suffix] = $hash;
            }
            ksort($rows);
            $result[$logical] = ['rows' => $rows, 'key_mode' => $keys !== [] ? 'stable-source-id' : 'row-multiset',
                'hash' => hash('sha256', json_encode([$columns, $rows], JSON_THROW_ON_ERROR))];
        }
        ksort($result);

        return $result;
    }

    private function configureFreshAlias(ConnectionInterface $baseline, string $name): void
    {
        if ($name !== 'watchline_fresh_source' || is_array(config('database.connections.'.$name))) {
            return;
        }
        $database = (string) config('watchline_final_sync.fresh_database', '');
        if (! in_array($baseline->getDriverName(), ['mysql', 'mariadb'], true)
            || ! preg_match('/\Awatchline_snapshot_[0-9]{8}(?:_[a-zA-Z0-9_]+)?\z/', $database)) {
            throw new RuntimeException('Configure WATCHLINE_FINAL_SOURCE_DATABASE as a separately restored local frozen snapshot.');
        }
        $config = HerreraCutoverSafety::isolatedConnectionConfig($baseline->getConfig());
        config(['database.connections.'.$name => array_replace($config, ['database' => $database])]);
        DB::purge($name);
    }

    private function fingerprints(array $snapshot): array
    {
        return array_map(fn ($table) => ['rows' => count($table['rows']), 'sha256' => $table['hash']], $snapshot);
    }

    private function baselineMismatches(ConnectionInterface $baseline, string $prefix): int
    {
        $mismatches = 0;
        foreach (['product', 'category', 'manufacturer', 'customer', 'order'] as $entity) {
            if (! $baseline->getSchemaBuilder()->hasTable($prefix.$entity)) {
                continue;
            }
            $expected = [];
            foreach ($baseline->table($prefix.$entity)->cursor() as $row) {
                $data = (array) $row;
                if ($entity === 'customer') {
                    $data = array_diff_key($data, array_flip(['password', 'salt', 'token', 'code', 'cart', 'wishlist', 'ip']));
                }
                $expected[(string) $row->{$entity.'_id'}] = hash('sha256', json_encode($data, JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE | JSON_UNESCAPED_UNICODE));
            }
            $mapped = [];
            foreach (DB::table('herrera_import_maps')->where('source', 'herrera-opencart')->where('entity', $entity)->cursor() as $map) {
                $mapped[(string) $map->source_id] = true;
                if (! isset($expected[$map->source_id]) || ! hash_equals($expected[$map->source_id], $map->checksum)) {
                    $mismatches++;
                }
            }
            $mismatches += count(array_diff_key($expected, $mapped));
        }

        return $mismatches;
    }

    private function identityRisks(ConnectionInterface $source, string $prefix): array
    {
        $counts = ['duplicate_source_ids' => 0, 'duplicate_product_identifiers' => 0, 'duplicate_customer_emails' => 0,
            'mapped_customer_is_staff' => 0, 'mapping_target_aliases' => 0,
            'mapped_source_identity_mismatches' => 0, 'native_customer_email_collisions' => 0];
        $customerMaps = DB::table('herrera_import_maps')->where('source', 'herrera-opencart')->where('entity', 'customer')->pluck('target_id', 'source_id');
        foreach (['product', 'customer', 'order'] as $entity) {
            if (! $source->getSchemaBuilder()->hasTable($prefix.$entity)) {
                continue;
            }
            $seenIds = $seenIdentifiers = [];
            foreach ($source->table($prefix.$entity)->cursor() as $row) {
                $id = (string) $row->{$entity.'_id'};
                $counts['duplicate_source_ids'] += (int) isset($seenIds[$id]);
                $seenIds[$id] = true;
                $identifier = match ($entity) {
                    'product' => strtolower(trim((string) (($row->sku ?? '') ?: ($row->model ?? '')))),
                    'customer' => strtolower(trim((string) $row->email)),
                    default => '',
                };
                if ($identifier !== '' && isset($seenIdentifiers[$identifier])) {
                    $counts[$entity === 'product' ? 'duplicate_product_identifiers' : 'duplicate_customer_emails']++;
                }
                $seenIdentifiers[$identifier] = true;
                if ($entity === 'customer' && $identifier !== '' && DB::table('users')->where('account_type', 'customer')
                    ->whereRaw('LOWER(email) = ?', [$identifier])
                    ->when($customerMaps->has($id), fn ($query) => $query->where('id', '!=', $customerMaps[$id]))->exists()) {
                    $counts['native_customer_email_collisions']++;
                }
            }
            $counts['mapping_target_aliases'] += DB::query()->fromSub(DB::table('herrera_import_maps')->select('target_id')
                ->where('source', 'herrera-opencart')->where('entity', $entity)->groupBy('target_id')->havingRaw('COUNT(*) > 1'), 'aliases')->count();
        }
        $counts['mapped_customer_is_staff'] = DB::table('herrera_import_maps as map')->join('users', 'users.id', '=', 'map.target_id')
            ->where('map.source', 'herrera-opencart')->where('map.entity', 'customer')->where('users.account_type', '!=', 'customer')->count();
        foreach (['product', 'category', 'manufacturer', 'order', 'order_product', 'order_total', 'order_history', 'profile'] as $entity) {
            foreach (DB::table('herrera_import_maps')->where('source', 'herrera-opencart')->where('entity', $entity)->cursor() as $map) {
                $native = DB::table(self::ENTITY_TABLES[$entity])->where('id', $map->target_id)->first(['payload']);
                $sourceId = data_get(json_decode($native?->payload ?: '{}', true), 'opencart.'.($entity === 'profile' ? 'customer' : $entity).'_id');
                // History stores its id in a reduced payload; all these identities are explicit source IDs.
                if ((string) $sourceId !== (string) $map->source_id) {
                    $counts['mapped_source_identity_mismatches']++;
                }
            }
        }

        return $counts;
    }

    private function coreImport(string $connection, string $prefix): array
    {
        $summary = app(HerreraOpenCartImportService::class)->import($connection, $prefix, false, self::STAGES, finalSync: true);

        return ['conflicts' => (int) ($summary['final_sync_conflicts'] ?? 0),
            'written' => collect($summary)->filter(fn ($value, $key) => str_ends_with($key, '_written'))->sum(),
            'products_written' => (int) ($summary['product_written'] ?? 0),
            'new_customers' => count($summary['final_sync_new_customer_source_ids'] ?? []),
            'removed_records' => (int) ($summary['final_sync_removed_records'] ?? 0)];
    }

    private function protectedSelection(): array
    {
        $selection = [];
        foreach (['system_settings', 'content_blocks', 'content_block_items', 'content_block_slots', 'content_block_translations', 'media', 'roles', 'abilities', 'assigned_roles', 'permissions', 'admin_customer_assignments', 'newsletter_signups'] as $table) {
            if (DB::getSchemaBuilder()->hasTable($table)) {
                $selection[$table] = DB::table($table)->pluck('id')->all();
            }
        }
        $selection['users'] = DB::table('users')->pluck('id')->all();
        $selection['native_orders'] = DB::table('orders')->where('source', '!=', 'opencart_import')->orWhereNull('source')->pluck('id')->all();
        $selection['products'] = DB::table('products')->pluck('id')->all();
        // Never turn a native opt-out into opt-in; a fresh source opt-out may still win.
        $selection['profile_preferences'] = DB::table('user_profiles')->where('newsletter_opt_in', false)->pluck('newsletter_opt_in', 'id')->all();

        return $selection;
    }

    private function protectedState(array $selection): string
    {
        $state = [];
        foreach ($selection as $table => $ids) {
            if ($table === 'profile_preferences') {
                $state[$table] = DB::table('user_profiles')->whereIn('id', array_keys($ids))->orderBy('id')->pluck('newsletter_opt_in', 'id')->all();
            } elseif ($table === 'derived_order_overrides') {
                foreach ($ids as $id => $fields) {
                    $state[$table][$id] = (array) DB::table('orders')->where('id', $id)->first(array_keys($fields));
                }
            } elseif ($table === 'native_orders') {
                foreach (['orders', 'order_items', 'order_totals', 'order_history'] as $orderTable) {
                    $state[$orderTable] = DB::table($orderTable)->whereIn($orderTable === 'orders' ? 'id' : 'order_id', $ids)->orderBy('id')->get()->all();
                }
            } elseif ($table === 'users') {
                $state[$table] = DB::table($table)->whereIn('id', $ids)->orderBy('id')->get(['id', 'password', 'remember_token', 'account_type', 'admin_username', 'admin_login_enabled', 'email_verified_at'])->all();
            } elseif ($table === 'products') {
                $state[$table] = [];
                foreach (DB::table($table)->whereIn('id', $ids)->orderBy('id')->get(['id', 'payload']) as $product) {
                    $payload = json_decode($product->payload ?: '{}', true, 512, JSON_THROW_ON_ERROR);
                    unset($payload['opencart'], $payload['related_product_ids']);
                    $state[$table][$product->id] = $payload;
                }
            } else {
                $state[$table] = DB::table($table)->whereIn('id', $ids)->orderBy('id')->get()->all();
            }
        }

        return hash('sha256', json_encode($state, JSON_THROW_ON_ERROR));
    }

    private function reconciliationState(): string
    {
        $tables = [];
        foreach (['products', 'product_translations', 'users', 'user_profiles', 'user_addresses', 'orders', 'order_items', 'order_totals', 'order_history', 'category_product', 'catalog_attribute_product', 'catalog_option_product', 'catalog_product_option_values'] as $table) {
            $rows = [];
            foreach (DB::table($table)->cursor() as $row) {
                $data = (array) $row;
                unset($data['updated_at']);
                ksort($data);
                $rows[] = hash('sha256', json_encode($data, JSON_THROW_ON_ERROR));
            }
            sort($rows);
            $tables[$table] = $rows;
        }

        return hash('sha256', json_encode($tables, JSON_THROW_ON_ERROR));
    }

    private function reconcile(ConnectionInterface $source, string $prefix): array
    {
        $checks = [];
        $comparison = new HerreraFinalSyncOwnership('watchline-reconciliation', static function (): void {});
        $allMaps = DB::table('herrera_import_maps')->where('source', 'herrera-opencart')->get()->groupBy('entity')
            ->map(fn ($rows) => $rows->pluck('target_id', 'source_id')->all())->all();
        $mappedId = fn (string $entity, $id) => $allMaps[$entity][(string) $id] ?? null;
        foreach (['product', 'customer', 'order', 'order_product', 'order_total', 'order_history'] as $entity) {
            $sourceIds = $source->table($prefix.$entity)->pluck($entity.'_id')->map(fn ($id) => (string) $id)->all();
            $mapped = DB::table('herrera_import_maps')->where('source', 'herrera-opencart')->where('entity', $entity)
                ->whereIn('source_id', $sourceIds)->pluck('target_id')->all();
            $checks[$entity.'_identities'] = count($sourceIds) === count($mapped)
                && DB::table(self::ENTITY_TABLES[$entity])->whereIn('id', $mapped)->count() === count($mapped);
            $checks[$entity.'_values_and_parents'] = true;
            $nativeRows = DB::table(self::ENTITY_TABLES[$entity])->whereIn('id', $mapped)->get()->keyBy('id');
            foreach ($source->table($prefix.$entity)->cursor() as $row) {
                $native = $nativeRows->get($mappedId($entity, $row->{$entity.'_id'}));
                $expected = match ($entity) {
                    'product' => ['base_price' => $row->price, 'stock_qty' => (int) $row->quantity, 'is_active' => (bool) $row->status],
                    'customer' => ['account_type' => 'customer', 'email' => strtolower(trim($row->email))],
                    'order' => ['order_number' => 'OC-WATCHLINE-'.$row->order_id, 'grand_total' => $row->total,
                        'currency_code' => $row->currency_code ?: 'EUR', 'user_id' => $mappedId('customer', $row->customer_id), 'status_id' => $mappedId('order_status', $row->order_status_id)],
                    'order_product' => ['order_id' => $mappedId('order', $row->order_id), 'product_id' => $mappedId('product', $row->product_id),
                        'quantity' => max(1, $row->quantity), 'unit_price' => $row->price, 'line_total' => $row->total, 'tax_amount' => (float) $row->tax * max(1, $row->quantity)],
                    'order_total' => ['order_id' => $mappedId('order', $row->order_id), 'code' => $row->code, 'value' => $row->value],
                    'order_history' => ['order_id' => $mappedId('order', $row->order_id), 'to_status_id' => $mappedId('order_status', $row->order_status_id), 'comment' => $row->comment],
                };
                foreach ($expected as $field => $value) {
                    $actual = $native->{$field} ?? null;
                    if (! $native || ! $comparison->same($actual, $value, $field)) {
                        $checks[$entity.'_values_and_parents'] = false;
                    }
                }
            }
        }
        $checks['historical_order_derived_totals'] = $this->derivedOrderOverrides($source, $prefix) === [];

        return $checks;
    }

    /** Detect native changes to the historical aggregate fields recomputed by the generic importer. */
    private function derivedOrderOverrides(ConnectionInterface $source, string $prefix): array
    {
        $totals = $source->table($prefix.'order_total')->get()->groupBy('order_id');
        $quantities = $source->table($prefix.'order_product')->get()->groupBy('order_id');
        $sourceOrders = $source->table($prefix.'order')->pluck('order_id')->flip();
        $overrides = [];
        foreach (DB::table('herrera_import_maps')->where('source', 'herrera-opencart')->where('entity', 'order')->cursor() as $map) {
            $order = DB::table('orders')->where('id', $map->target_id)->first();
            if (! $order || ! $sourceOrders->has($map->source_id)) {
                continue;
            }
            $rows = $totals->get($map->source_id, collect())->groupBy('code');
            $value = fn ($code) => (float) ($rows->get($code)?->sum('value') ?? 0);
            $expected = ['subtotal' => $value('sub_total'), 'shipping_total' => $value('shipping'), 'tax_total' => $value('tax'),
                'discount_total' => abs($value('coupon')) + abs($value('voucher')),
                'item_qty' => $quantities->get($map->source_id)?->sum(fn ($row) => max(1, $row->quantity)) ?? 0];
            foreach ($expected as $field => $amount) {
                if (! \Brick\Math\BigDecimal::of((string) $amount)->isEqualTo((string) $order->{$field})) {
                    $overrides[(int) $map->target_id][$field] = $order->{$field};
                }
            }
        }

        return $overrides;
    }

    private function restoreDerivedOrderOverrides(array $overrides): void
    {
        foreach ($overrides as $id => $fields) {
            DB::table('orders')->where('id', $id)->update($fields);
        }
    }

    private function restoreProfilePreferences(array $preferences): void
    {
        foreach ($preferences as $id => $optIn) {
            DB::table('user_profiles')->where('id', $id)->where('newsletter_opt_in', '!=', $optIn)->update(['newsletter_opt_in' => $optIn]);
        }
    }

    private function remainingGates(): array
    {
        return ['Review extension, promotion, voucher/return and staff deltas; these are not refreshed by the core merge.',
            'Verify every active public price and resolve ownership/identity conflicts on the candidate.',
            'Reconcile original media, native edits, customer logins and historical order amounts/statuses.',
            'Capture a final frozen snapshot after stopping old-shop writes/cron; repeat the approved rehearsal.',
            'Prepare a reviewed candidate promotion and rollback plan; this command never activates production.'];
    }
}
