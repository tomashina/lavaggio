<?php

namespace App\Console\Commands;

use App\Services\Import\LavaggioContentImportService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class LavaggioImportContent extends Command
{
    protected $signature = 'lavaggio:import-content {snapshot : Path to a public-content MODX JSON snapshot} {--execute : Import into the isolated local Lavaggio database}';

    protected $description = 'Preview or import public Lavaggio pages, TV studio posts, FAQs and their images';

    public function handle(LavaggioContentImportService $importer): int
    {
        try {
            $this->guardDestination();
            $path = (string) $this->argument('snapshot');
            if (! is_file($path) || ! is_readable($path)) {
                throw new RuntimeException('The content snapshot is not readable.');
            }
            $snapshot = json_decode(file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
            if (! is_array($snapshot)) {
                throw new RuntimeException('The content snapshot must be a JSON object.');
            }
            $preview = $importer->preview($snapshot);
            $this->table(['Content', 'Snapshot items'], [
                ['Pages', $preview['pages']], ['TV studio posts', $preview['posts']], ['FAQs', $preview['faqs']],
            ]);
            if (! $this->option('execute')) {
                $this->info('Preview only. Add --execute to import this content into the local Lavaggio store.');

                return self::SUCCESS;
            }
            $result = $importer->import($snapshot);
            $this->info(sprintf('Imported %d pages, %d TV studio posts and %d FAQs; %d local images.', $result['pages_imported'], $result['posts_imported'], $result['faqs_imported'], $result['images_local']));
            foreach ($result['warnings'] as $warning) {
                $this->warn($warning);
            }
            if ($result['modx_tags_removed'] > 0) {
                $this->warn($result['modx_tags_removed'].' unresolved MODX tags were removed; review the normalized snapshot content.');
            }

            return self::SUCCESS;
        } catch (\Throwable $exception) {
            $this->error($exception instanceof RuntimeException || $exception instanceof \JsonException ? $exception->getMessage() : 'Content import stopped: '.get_class($exception));

            return self::FAILURE;
        }
    }

    private function guardDestination(): void
    {
        if (config('database.default') !== 'sqlite' || config('database.connections.sqlite.driver') !== 'sqlite'
            || config('database.connections.sqlite.url')) {
            throw new RuntimeException('Content import is restricted to the local Lavaggio SQLite connection.');
        }
        $configured = config('database.connections.sqlite.database');
        if (app()->environment('testing') && $configured === ':memory:') {
            return;
        }
        $root = realpath(base_path());
        $expected = realpath(database_path('database.sqlite'));
        if (! app()->environment('local') || config('storefront.home') !== 'lavaggio' || ! $root || basename($root) !== 'lavaggioshop'
            || ! $expected || $configured !== $expected || $expected !== $root.'/database/database.sqlite'
            || is_link(database_path()) || is_link(database_path('database.sqlite'))) {
            throw new RuntimeException('Content import refused: use only the local lavaggioshop/database/database.sqlite destination.');
        }
        $active = collect(DB::connection()->select('PRAGMA database_list'))->firstWhere('name', 'main');
        if (! $active || $active->file !== $expected) {
            throw new RuntimeException('Content import refused: the active database is not the verified local destination.');
        }
    }
}
