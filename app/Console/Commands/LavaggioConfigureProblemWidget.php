<?php

namespace App\Console\Commands;

use App\Models\Content\ContentBlock;
use App\Services\Content\ContentBlockResolver;
use App\Services\Front\GuestStorefrontCache;
use App\Support\WatchlineWidgetSettings;
use Illuminate\Console\Command;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use RuntimeException;

class LavaggioConfigureProblemWidget extends Command
{
    protected $signature = 'lavaggio:configure-problem-widget {--apply : Add the new block; never overwrite existing blocks}';

    protected $description = 'Preview or add the editable Lavaggio problem cards without resetting homepage content';

    public function handle(): int
    {
        try {
            $this->guardDestination();
        } catch (RuntimeException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }
        if (ContentBlock::query()->where('code', 'lavaggio-home-problems')->exists()) {
            $this->info('Problem widget already exists. Its content, visibility and placement are preserved.');

            return self::SUCCESS;
        }
        $this->info('Add one editable problem-cards block to home.content at position 15. Existing blocks will not change.');
        if (! $this->option('apply')) {
            $this->info('Preview only. Use --apply to create the block.');

            return self::SUCCESS;
        }
        if (config('database.connections.sqlite.database') !== ':memory:') {
            $directory = storage_path('app/private/lavaggio-feedback-backups');
            File::ensureDirectoryExists($directory, 0700, true);
            $backup = $directory.'/before-problem-widget-'.now()->format('Ymd-His').'-'.bin2hex(random_bytes(3)).'.sqlite';
            DB::statement('VACUUM INTO ?', [$backup]);
            chmod($backup, 0600);
            $this->info('Privatna sigurnosna kopija lokalne baze je spremljena.');
        }
        DB::transaction(function (): void {
            $block = ContentBlock::create(['code' => 'lavaggio-home-problems', 'name' => 'Početna — odabir prema problemu',
                'type' => 'lavaggio_problems', 'is_active' => true]);
            $defaults = WatchlineWidgetSettings::defaults('lavaggio_problems');
            $block->translations()->create(['locale' => 'hr', 'title' => $defaults['title'], 'subtitle' => $defaults['subtitle'],
                'payload' => ['watchline' => Arr::except($defaults, ['title', 'subtitle', 'cta_label', 'cta_url'])]]);
            $block->slots()->create(['placement' => 'home.content', 'frontend_variant' => 'all', 'is_active' => true, 'sort_order' => 15]);
        });
        ContentBlockResolver::bumpCacheVersion();
        app(GuestStorefrontCache::class)->invalidate();
        $this->info('Problem widget created.');

        return self::SUCCESS;
    }

    private function guardDestination(): void
    {
        if (! app()->environment(['local', 'testing']) || config('storefront.home') !== 'lavaggio'
            || config('database.default') !== 'sqlite' || config('database.connections.sqlite.url')
            || DB::connection()->getDriverName() !== 'sqlite') {
            throw new RuntimeException('Dopušteno samo za lokalni Lavaggio SQLite pregled.');
        }
        $configured = config('database.connections.sqlite.database');
        if (app()->environment('testing') && $configured === ':memory:') {
            return;
        }
        $expected = realpath(database_path('database.sqlite'));
        if (! app()->environment('local') || ! $expected || $configured !== $expected
            || $expected !== realpath(base_path()).'/database/database.sqlite'
            || is_link(database_path()) || is_link(database_path('database.sqlite'))) {
            throw new RuntimeException('Aktivna baza nije provjerena lokalna database/database.sqlite.');
        }
        $active = collect(DB::connection()->select('PRAGMA database_list'))->firstWhere('name', 'main');
        if (! $active || $active->file !== $expected) {
            throw new RuntimeException('SQLite veza ne pokazuje na provjerenu lokalnu bazu.');
        }
    }
}
