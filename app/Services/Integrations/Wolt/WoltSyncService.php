<?php

namespace App\Services\Integrations\Wolt;

use App\Services\Settings\SystemSettingsService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

class WoltSyncService
{
    public function __construct(
        private readonly WoltSettingsService $settings,
        private readonly WoltPayloadService $payloads,
        private readonly SystemSettingsService $systemSettings,
    ) {}

    public function preview(string $job): array
    {
        return $this->prepare($job)['result'];
    }

    public function run(string $job, bool $dryRun = true): array
    {
        $prepared = $this->prepare($job);
        if ($dryRun) {
            return $prepared['result'];
        }
        $result = $prepared['result'];
        $result['dry_run'] = false;
        if ($result['blocked']) {
            $result['status'] = 'blocked';

            return $result;
        }
        if ($prepared['data'] === []) {
            $result['status'] = 'unchanged';

            return $result;
        }

        $lock = Cache::lock('watchline:wolt:'.$prepared['context'], 120);
        if (! $lock->get()) {
            $result['blocked'] = true;
            $result['status'] = 'blocked';
            $result['reasons'][] = 'Wolt obrada za ovo prodajno mjesto već je u tijeku.';

            return $result;
        }

        try {
            // A second request may have finished while this request was building its preview.
            $this->systemSettings->clearRuntimeCache();
            $prepared = $this->prepare($job);
            $result = $prepared['result'];
            $result['dry_run'] = false;
            if ($result['blocked']) {
                $result['status'] = 'blocked';

                return $result;
            }
            if ($prepared['data'] === []) {
                $result['status'] = 'unchanged';

                return $result;
            }

            $credentials = $this->settings->credentials();
            $options = $this->settings->options();
            $request = Http::acceptJson()->asJson()->connectTimeout(10)->timeout(45)
                ->withOptions(['allow_redirects' => false]);
            $request = $options['auth_type'] === 'bearer'
                ? $request->withToken((string) ($credentials['access_token'] ?? ''))
                : $request->withBasicAuth((string) ($credentials['username'] ?? ''), (string) ($credentials['password'] ?? ''));

            // Record attempts before transport: an uncertain timeout must not trigger immediate duplicate sends.
            $state = $prepared['state'];
            $state['next_run_at'] = now()->addMinutes($prepared['minutes'])->toIso8601String();
            $this->systemSettings->put($prepared['state_key'], $state);
            $result['metadata']['next_run_at'] = $state['next_run_at'];
            $response = $request->patch($this->settings->endpoint($prepared['operation']), ['data' => $prepared['data']]);
            $result['metadata']['http_status'] = $response->status();
            $result['metadata']['next_run_at'] = $state['next_run_at'];

            if ($response->status() === 202) {
                foreach ($prepared['data'] as $row) {
                    $state['accepted'][(string) $row['sku']] = $this->rowHash($row);
                }
                $state['last_accepted_at'] = now()->toIso8601String();
                $this->systemSettings->put($prepared['state_key'], $state);
                $result['status'] = 'accepted';
                $result['counts']['sent'] = count($prepared['data']);
            } else {
                $result['status'] = 'failed';
                $result['reasons'][] = $response->status() === 429
                    ? 'Wolt je privremeno ograničio broj poziva. Obradu ponovite nakon isteka ograničenja.'
                    : 'Wolt nije prihvatio ažuriranje. Provjerite pristupne podatke i potvrđeno mapiranje.';
                if ($response->status() === 429 && ctype_digit((string) $response->header('Retry-After'))) {
                    $retryAt = now()->addSeconds(min(86400, (int) $response->header('Retry-After')));
                    if ($retryAt->gt(CarbonImmutable::parse($state['next_run_at']))) {
                        $state['next_run_at'] = $retryAt->toIso8601String();
                        $this->systemSettings->put($prepared['state_key'], $state);
                        $result['metadata']['next_run_at'] = $state['next_run_at'];
                    }
                }
            }

            return $result;
        } catch (\Throwable) {
            // Provider bodies and transport exception messages may contain authorization details.
            $result['status'] = 'failed';
            $result['reasons'][] = 'Spoj s Woltom nije uspio. Rezultat nije potvrđen; prije ponavljanja pričekajte ograničenje poziva.';

            return $result;
        } finally {
            $lock->release();
        }
    }

    private function prepare(string $job): array
    {
        $operation = match ($job) {
            'wolt_catalog' => 'catalog',
            'wolt_inventory' => 'inventory',
            default => abort(404),
        };
        $preview = $this->payloads->preview($operation);
        $options = $this->settings->options();
        $context = hash('sha256', json_encode([$options['environment'], $options[$operation.'_venue_id'], $operation]));
        $stateKey = 'watchline_wolt_state_'.$context;
        $state = (array) $this->systemSettings->get($stateKey, []);
        $accepted = (array) ($state['accepted'] ?? []);
        $changed = array_values(array_filter($preview['payload']['data'], fn (array $row): bool => ($accepted[(string) $row['sku']] ?? null) !== $this->rowHash($row)));
        $minutes = $operation === 'catalog' ? 15 : 5;
        $blockingReasons = array_merge($preview['blockers'], $preview['errors']);
        if ($preview['item_count'] === 0 && $preview['skipped_conflicts'] > 0) {
            $blockingReasons[] = 'Nema valjanih Wolt stavki za slanje. Sve odabrane šifre imaju konflikt.';
        }
        $nextRunAt = null;
        try {
            if (! empty($state['next_run_at'])) {
                $nextRunAt = CarbonImmutable::parse($state['next_run_at']);
            }
        } catch (\Throwable) {
            $blockingReasons[] = 'Stanje prethodne Wolt obrade nije valjano. Treba provjeriti povijest obrade.';
        }
        if ($changed !== [] && $nextRunAt?->isFuture()) {
            $blockingReasons[] = 'Wolt ograničenje poziva još traje. Sljedeće ažuriranje moguće je nakon '.$nextRunAt->utc()->format('d.m.Y. H:i:s').' UTC.';
        }
        $result = [
            'job' => $job,
            'status' => 'preview',
            'dry_run' => true,
            'blocked' => $blockingReasons !== [],
            'reasons' => array_merge($blockingReasons, $preview['warnings']),
            'counts' => [
                'selected' => $preview['selected_count'], 'valid' => $preview['item_count'],
                'changed' => count($changed), 'unchanged' => $preview['item_count'] - count($changed),
                'invalid' => count($preview['errors']), 'sent' => 0,
                'deduplicated' => $preview['deduplicated_count'],
                'skipped_conflicts' => $preview['skipped_conflicts'],
            ],
            'metadata' => [
                'operation' => $operation, 'environment' => $options['environment'],
                'rate_limit_minutes' => $minutes, 'next_run_at' => $nextRunAt?->toIso8601String(),
                'preview_count' => min(25, count($changed)),
            ],
            'preview' => ['data' => array_slice($changed, 0, 25)],
        ];

        return ['result' => $result, 'operation' => $operation, 'data' => $changed, 'state' => $state, 'state_key' => $stateKey, 'context' => $context, 'minutes' => $minutes];
    }

    private function rowHash(array $row): string
    {
        return hash('sha256', json_encode($row, JSON_THROW_ON_ERROR));
    }
}
