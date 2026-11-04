<?php

namespace App\Console\Commands;

use App\Services\Content\ContentBlockResolver;
use App\Services\Front\GuestStorefrontCache;
use App\Services\Import\LavaggioFeedbackUpdateService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use RuntimeException;

class LavaggioApplyNovemberFeedback extends Command
{
    protected $signature = 'lavaggio:apply-november-feedback {--apply : Apply the displayed narrow updates to the verified local preview}';

    protected $description = 'Preview/apply the approved November 2026 laundry content and Croatian-link updates';

    public function handle(LavaggioFeedbackUpdateService $service): int
    {
        try {
            $this->guardDestination();
            $plan = $service->plan();
            foreach ($plan['actions'] as $action) {
                $this->line($action['description']);
            }
            foreach ($plan['skipped'] as $message) {
                $this->warn($message);
            }
            $this->info(count($plan['actions']).' uskih izmjena. Iznosi cjenika ostaju isti; datum: 10.09.2026.');
            if (! $this->option('apply') || $plan['actions'] === []) {
                $this->info('Pregled je završen. Bez --apply nisu napravljene promjene.');

                return self::SUCCESS;
            }
            if (config('database.connections.sqlite.database') !== ':memory:') {
                $directory = storage_path('app/private/lavaggio-feedback-backups');
                File::ensureDirectoryExists($directory, 0700, true);
                $backup = $directory.'/before-20261104-'.now()->format('Ymd-His').'-'.bin2hex(random_bytes(3)).'.sqlite';
                DB::statement('VACUUM INTO ?', [$backup]);
                chmod($backup, 0600);
                $this->info('Privatna sigurnosna kopija lokalne baze je spremljena.');
            }
            DB::transaction(fn () => $service->apply($plan));
            ContentBlockResolver::bumpCacheVersion();
            app(GuestStorefrontCache::class)->invalidate();
            $this->info('Odobrene lokalne izmjene su primijenjene. Poslužitelj nije mijenjan.');

            return self::SUCCESS;
        } catch (\Throwable $exception) {
            $this->error($exception instanceof RuntimeException ? $exception->getMessage() : 'Ažuriranje je zaustavljeno: '.get_class($exception));

            return self::FAILURE;
        }
    }

    private function guardDestination(): void
    {
        if (! app()->environment(['local', 'testing']) || config('storefront.home') !== 'lavaggio' || config('database.default') !== 'sqlite' || config('database.connections.sqlite.url')) {
            throw new RuntimeException('Dopušteno samo za lokalni Lavaggio SQLite pregled.');
        }
        $configured = config('database.connections.sqlite.database');
        if (app()->environment('testing') && $configured === ':memory:') {
            return;
        }
        $expected = realpath(database_path('database.sqlite'));
        if (! app()->environment('local') || ! $expected || $configured !== $expected || $expected !== realpath(base_path()).'/database/database.sqlite' || is_link(database_path()) || is_link(database_path('database.sqlite'))) {
            throw new RuntimeException('Aktivna baza nije provjerena lokalna database/database.sqlite.');
        }
        $active = collect(DB::connection()->select('PRAGMA database_list'))->firstWhere('name', 'main');
        if (! $active || $active->file !== $expected) {
            throw new RuntimeException('SQLite veza ne pokazuje na provjerenu lokalnu bazu.');
        }
    }
}
