<?php

namespace App\Console\Commands;

use App\Services\Import\WatchlineFinalSyncService;
use Illuminate\Console\Command;
use Throwable;

/** There is deliberately no production --apply option. */
class WatchlineFinalSync extends Command
{
    protected $signature = 'watchline:final-sync
        {--baseline-connection=watchline_source : Original frozen source used by the initial import}
        {--source-connection=watchline_fresh_source : Separately restored fresh frozen source}
        {--prefix=oc_}
        {--baseline-date= : Actual baseline capture date, YYYY-MM-DD}
        {--source-date= : Actual fresh capture date, YYYY-MM-DD}
        {--rehearse : Create a new private SQLite copy and test the core merge twice; never apply to the current shop}';

    protected $description = 'Plan Watchline snapshot deltas, optionally rehearse only on a new isolated SQLite candidate';

    public function handle(WatchlineFinalSyncService $service): int
    {
        try {
            $arguments = [
                (string) $this->option('baseline-connection'), (string) $this->option('source-connection'),
                (string) $this->option('prefix'), (string) $this->option('baseline-date'), (string) $this->option('source-date'),
            ];
            $report = $this->option('rehearse') ? $service->rehearse(...$arguments) : $service->plan(...$arguments);
            $run = $report['run_id'] ?? 'plan-'.now()->format('Ymd_His').'-'.bin2hex(random_bytes(6));
            $relative = 'watchline/final-sync/'.$run.(isset($report['run_id']) ? '/report.json' : '.json');
            $path = storage_path('app/private/'.$relative);
            if (! is_dir(dirname($path))) {
                mkdir(dirname($path), 0700, true);
            }
            if (file_put_contents($path, json_encode($report, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT), LOCK_EX) === false) {
                throw new \RuntimeException('The private aggregate report could not be saved.');
            }
            chmod($path, 0600);
            $this->info('Watchline final-sync: '.$report['status']);
            $this->line('Private aggregate report: '.$relative);
            foreach (['product' => 'Products', 'customer' => 'Customers', 'order' => 'Historical orders'] as $table => $label) {
                $delta = $report['table_deltas'][$table] ?? [];
                $this->line($label.': new '.($delta['new'] ?? 0).', changed '.($delta['changed'] ?? 0).', removed '.($delta['removed'] ?? 0));
            }
            $this->warn('Preparation/rehearsal only. Review all preservation and reconciliation gates; production writes/activation are not implemented or authorized by this command.');

            return in_array($report['status'], ['blocked', 'rehearsal_failed', 'rehearsal_blocked'], true) ? self::FAILURE : self::SUCCESS;
        } catch (Throwable $exception) {
            // DB messages can embed emails, names, credentials and SQL bindings.
            $this->error('Watchline final-sync stopped safely ('.class_basename($exception).'). Review snapshot/configuration prerequisites; no production apply is available.');

            return self::FAILURE;
        }
    }
}
