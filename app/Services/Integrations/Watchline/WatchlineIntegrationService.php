<?php

namespace App\Services\Integrations\Watchline;

use App\Models\Integrations\WatchlineIntegrationRun;
use App\Services\Integrations\Wolt\WoltSyncService;
use Carbon\CarbonImmutable;
use Cron\CronExpression;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Cache;
use Illuminate\Validation\ValidationException;
use Throwable;

class WatchlineIntegrationService
{
    public function __construct(private readonly WatchlineIntegrationSettings $settings) {}

    public function run(string $job, bool $dryRun = true, string $trigger = 'manual', ?int $actorId = null, ?string $expectedGathChecksum = null): array
    {
        abort_unless(config('storefront.home') === 'watchline' && isset(WatchlineIntegrationSettings::JOBS[$job]), 404);
        abort_unless(in_array($trigger, ['manual', 'cron', 'cli'], true), 422);
        $lock = Cache::lock('watchline:integrations:'.$job, 600);
        if (! $lock->get()) {
            return ['job' => $job, 'status' => 'blocked', 'dry_run' => $dryRun, 'reasons' => ['Obrada je već u tijeku.']];
        }
        $run = null;
        try {
            $run = WatchlineIntegrationRun::query()->create([
                'job' => $job, 'trigger' => $trigger, 'dry_run' => $dryRun,
                'actor_id' => $actorId, 'status' => 'running', 'started_at' => now(),
            ]);
            if (! $dryRun && ($trigger === 'cron' || $job !== 'gath') && ! $this->settings->liveReady()) {
                $report = ['status' => 'blocked', 'reasons' => ['Automatsko izvršavanje i vanjsko slanje dostupni su tek na produkciji nakon uključivanja integracija.']];
            } else {
                $options = $this->settings->options();
                $report = match ($job) {
                    'gath' => $dryRun
                        ? app(GathSyncService::class)->preview($options['gath_csv_path'])
                        : app(GathSyncService::class)->apply($options['gath_csv_path'], (bool) $options['gath_reset_missing'], $actorId, $expectedGathChecksum),
                    'wolt_catalog', 'wolt_inventory' => app(WoltSyncService::class)->run($job, $dryRun),
                    'reviews', 'abandoned' => app(WatchlineMessageAutomationService::class)->run($job, $dryRun),
                };
            }
            $report = $this->publicSummary($report) + ['status' => $dryRun ? 'preview' : 'completed'];
        } catch (Throwable $exception) {
            // Provider exceptions can contain URLs, customer data or authentication headers.
            $report = ['status' => 'failed', 'reasons' => [$exception instanceof ValidationException
                ? 'Postavke nisu valjane. Provjerite izvor i obvezna polja.'
                : 'Obrada nije uspjela. Provjerite izvor i postavke povezivanja.']];
        } finally {
            $lock->release();
        }
        $report += ['job' => $job, 'dry_run' => $dryRun];
        $run?->update(['status' => $report['status'], 'summary' => $report, 'completed_at' => now()]);

        return $report + ['integration_run_id' => $run?->id];
    }

    public function runDue(?CarbonImmutable $at = null): array
    {
        if (! $this->settings->liveReady()) {
            return [];
        }
        $at = ($at ?? CarbonImmutable::now())->setTimezone('Europe/Zagreb');
        $reports = [];
        foreach ($this->settings->options()['schedules'] as $job => $schedule) {
            if (! isset(WatchlineIntegrationSettings::JOBS[$job]) || ! $schedule['enabled']
                || ! CronExpression::isValidExpression($schedule['expression'])
                || ! (new CronExpression($schedule['expression']))->isDue($at)) {
                continue;
            }
            // A second scheduler process must not run a due job twice in the same minute.
            if (! Cache::add('watchline:scheduled:'.$job.':'.$at->format('YmdHi'), true, 120)) {
                continue;
            }
            $reports[$job] = $this->run($job, false, 'cron');
        }

        return $reports;
    }

    private function publicSummary(array $report): array
    {
        $keys = ['status', 'blocked', 'reasons', 'counts', 'can_apply', 'fetched_count', 'matched_count',
            'matched_identifiers', 'updated_count', 'unchanged_count', 'unmatched_count', 'reset_candidate_count',
            'price_changed_count', 'stock_changed_count', 'reactivated_count', 'reset_count', 'checksum',
            'eligible', 'sent', 'skipped', 'run_id', 'draft_base_entry_updated_count'];
        $summary = Arr::only($report, $keys);
        if (isset($summary['counts'])) {
            $summary['counts'] = Arr::only($summary['counts'], ['selected', 'valid', 'changed', 'unchanged', 'invalid', 'sent', 'skipped_conflicts']);
        }
        if (isset($report['examples'])) {
            $summary['examples'] = array_map(fn (array $example): array => Arr::only($example, [
                'product_id', 'sku', 'old_quantity', 'new_quantity', 'old_price', 'new_price', 'reactivate', 'reset',
            ]), array_slice($report['examples'], 0, 25));
        }
        if (isset($report['metadata'])) {
            $summary['metadata'] = Arr::only($report['metadata'], ['operation', 'environment', 'rate_limit_minutes', 'next_run_at']);
        }

        return $summary;
    }
}
