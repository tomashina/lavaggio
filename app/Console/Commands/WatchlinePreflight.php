<?php

namespace App\Console\Commands;

use App\Services\Import\WatchlineLaunchPreflightService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;
use Throwable;

class WatchlinePreflight extends Command
{
    protected $signature = 'watchline:preflight
        {--profile=local : Expected local or live configuration; never changes configuration}
        {--json : Print only aggregate JSON}
        {--media-report : Save validated missing catalog paths to a new private JSON report}';

    protected $description = 'Read-only Watchline environment and original-media readiness check; never enables live features';

    public function handle(WatchlineLaunchPreflightService $service): int
    {
        try {
            $report = $service->inspect((string) $this->option('profile'), (bool) $this->option('media-report'));
            if ($this->option('media-report')) {
                if (config('filesystems.disks.local.driver') !== 'local') {
                    throw new \RuntimeException('A local private report disk is required.');
                }
                $relative = 'watchline/preflight/missing-media-'.now()->format('Ymd_His').'-'.bin2hex(random_bytes(6)).'.json';
                $path = Storage::disk('local')->path($relative);
                if (! is_dir(dirname($path))) {
                    mkdir(dirname($path), 0700, true);
                }
                if (file_put_contents($path, json_encode(['generated_at' => $report['generated_at'], 'media' => $report['media']], JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE), LOCK_EX) === false) {
                    throw new \RuntimeException('The private media report could not be saved.');
                }
                chmod($path, 0600);
                unset($report['media']['missing_paths']);
                $report['private_media_report'] = $relative;
            }
            if ($this->option('json')) {
                $this->line(json_encode($report, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
            } else {
                $this->info('Watchline preflight ('.$report['profile'].'): '.($report['ready'] ? 'OK' : 'BLOKIRANO'));
                $this->table(['Provjera', 'Stanje', 'Napomena'], collect($report['checks'])->map(fn ($check) => [$check['code'], $check['status'], $check['message']])->all());
                $this->line('Lokalne izvorne slike: '.($report['media']['local_originals'] ?? 0).'; nedostaje: '.($report['media']['missing_originals'] ?? 0));
                if (isset($report['private_media_report'])) {
                    $this->line('Privatni popis slika: '.$report['private_media_report']);
                }
            }

            return $report['ready'] ? self::SUCCESS : self::FAILURE;
        } catch (Throwable $exception) {
            $this->error('Provjera je sigurno zaustavljena ('.class_basename($exception).'). Nijedna postavka ili kataloški zapis nisu promijenjeni.');

            return self::FAILURE;
        }
    }
}
