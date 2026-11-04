<?php

namespace Tests\Feature\Import;

use App\Models\Content\ContentBlock;
use App\Services\Front\GuestStorefrontCache;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

class LavaggioDeployContentUpgradesTest extends TestCase
{
    use RefreshDatabase;

    private const MIGRATION = '2026_11_04_113000_add_lavaggio_home_problems_widget';

    private const PATH = 'database/migrations/'.self::MIGRATION.'.php';

    private string $backupRoot;

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'storefront.home' => 'lavaggio', 'storefront.theme' => 'herrera',
            'app.locale' => 'hr', 'app.fallback_locale' => 'hr',
            'storefront_cache.enabled' => false, 'storefront_cache.store' => 'array',
        ]);
        app()->setLocale('hr');
        DB::table('migrations')->where('migration', self::MIGRATION)->delete();
        $directory = sys_get_temp_dir().'/lavaggio-content-upgrade-tests-'.bin2hex(random_bytes(8));
        mkdir($directory, 0700);
        $this->backupRoot = realpath($directory);
        chmod($this->backupRoot, 0700);
    }

    protected function tearDown(): void
    {
        try {
            if (isset($this->backupRoot)) {
                File::deleteDirectory($this->backupRoot);
            }
        } finally {
            parent::tearDown();
        }
    }

    public function test_preview_needs_no_backup_directory_and_performs_no_content_ledger_or_cache_changes(): void
    {
        $this->promo();
        $before = $this->snapshot();
        $revision = app(GuestStorefrontCache::class)->revision();
        $version = Cache::get(config('content_blocks.cache.version_key'), 1);
        $this->assertSame(0, Artisan::call('lavaggio:deploy-content-upgrades'));
        $this->assertStringContainsString('Preview only', Artisan::output());
        $this->assertSame($before, $this->snapshot());
        $this->assertSame($revision, app(GuestStorefrontCache::class)->revision());
        $this->assertSame($version, Cache::get(config('content_blocks.cache.version_key'), 1));
        $this->assertSame(0, Artisan::call('lavaggio:deploy-content-upgrades', ['--backup-dir' => $this->backupRoot]));
        $this->assertSame([], File::files($this->backupRoot));
        $this->assertSame($before, $this->snapshot());
    }

    public function test_apply_creates_a_private_complete_json_snapshot_then_publishes_the_widget_and_invalidates_caches(): void
    {
        $promo = $this->promo();
        $promo->slots()->create(['placement' => 'page.top', 'frontend_variant' => 'desktop', 'sort_order' => 80, 'is_active' => false]);
        $before = $this->snapshot();
        $this->get('/')->assertOk()->assertDontSee('data-lavaggio-problems', false);
        $revision = app(GuestStorefrontCache::class)->revision();
        $version = Cache::get(config('content_blocks.cache.version_key'), 1);

        $this->assertSame(0, Artisan::call('lavaggio:deploy-content-upgrades', ['--apply' => true, '--backup-dir' => $this->backupRoot]), Artisan::output());
        $files = File::files($this->backupRoot);
        $this->assertCount(1, $files);
        $this->assertSame(0700, fileperms($this->backupRoot) & 0777);
        $this->assertSame(0600, fileperms($files[0]->getPathname()) & 0777);
        if (function_exists('posix_geteuid')) {
            $this->assertSame(posix_geteuid(), fileowner($files[0]->getPathname()));
        }
        $backup = json_decode(File::get($files[0]->getPathname()), true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame([self::PATH], $backup['upgrade']);
        $this->assertSame(':memory:', $backup['database']);
        $this->assertNotEmpty($backup['captured_at']);
        foreach (['content_blocks', 'content_block_translations', 'content_block_slots', 'migrations'] as $table) {
            $this->assertSame($before[$table], $backup[$table], $table);
        }
        $this->assertDatabaseHas('migrations', ['migration' => self::MIGRATION]);
        $problems = ContentBlock::query()->where('code', 'lavaggio-home-problems')->sole();
        $this->assertSame('lavaggio_problems', $problems->type);
        $this->assertSame('home.content', $problems->slots()->sole()->placement);
        $this->assertNotSame($revision, app(GuestStorefrontCache::class)->revision());
        $this->assertGreaterThan($version, Cache::get(config('content_blocks.cache.version_key'), 1));
        $this->get('/')->assertOk()->assertSee('data-lavaggio-problems', false)->assertSee('Neugodni mirisi');
    }

    public function test_an_unreviewed_pending_migration_stops_before_backup_or_content_writes(): void
    {
        $this->promo();
        $unreviewed = DB::table('migrations')->where('migration', '!=', self::MIGRATION)->orderBy('id')->value('migration');
        $this->assertNotNull($unreviewed);
        DB::table('migrations')->where('migration', $unreviewed)->delete();
        $before = $this->snapshot();
        $this->assertSame(1, Artisan::call('lavaggio:deploy-content-upgrades', ['--apply' => true, '--backup-dir' => $this->backupRoot]));
        $this->assertStringContainsString('Unreviewed pending migrations', Artisan::output());
        $this->assertSame([], File::files($this->backupRoot));
        $this->assertSame($before, $this->snapshot());
    }

    public function test_existing_edited_hidden_problem_content_and_placements_are_never_reset(): void
    {
        $this->promo();
        $problems = ContentBlock::create(['code' => 'lavaggio-home-problems', 'name' => 'Urednički naziv',
            'type' => 'lavaggio_problems', 'is_active' => false, 'payload' => ['editor' => 'preserved']]);
        $problems->translations()->create(['locale' => 'hr', 'title' => 'Naš odabrani naslov',
            'payload' => ['watchline' => ['card_1_title' => 'Naša kartica', 'card_1_url' => '/trgovina?izbor=urednicki']]]);
        $problems->slots()->create(['placement' => 'home.content', 'frontend_variant' => 'mobile', 'sort_order' => 92, 'is_active' => false]);
        $before = $this->contentSnapshot();
        $this->assertSame(0, Artisan::call('lavaggio:deploy-content-upgrades', ['--apply' => true, '--backup-dir' => $this->backupRoot]), Artisan::output());
        $this->assertSame($before, $this->contentSnapshot());
        $this->assertDatabaseHas('migrations', ['migration' => self::MIGRATION]);
        $firstRun = $this->snapshot();
        $files = count(File::files($this->backupRoot));
        $this->assertSame(0, Artisan::call('lavaggio:deploy-content-upgrades', ['--apply' => true]));
        $this->assertSame($firstRun, $this->snapshot());
        $this->assertCount($files, File::files($this->backupRoot));
    }

    public function test_an_already_recorded_upgrade_preserves_a_later_editorial_deletion_and_only_refreshes_cache(): void
    {
        $this->promo();
        DB::table('migrations')->insert(['migration' => self::MIGRATION, 'batch' => DB::table('migrations')->max('batch') + 1]);
        $before = $this->snapshot();
        $revision = app(GuestStorefrontCache::class)->revision();
        $this->assertSame(0, Artisan::call('lavaggio:deploy-content-upgrades', ['--apply' => true]));
        $this->assertSame($before, $this->snapshot());
        $this->assertDatabaseMissing('content_blocks', ['code' => 'lavaggio-home-problems']);
        $this->assertSame([], File::files($this->backupRoot));
        $this->assertNotSame($revision, app(GuestStorefrontCache::class)->revision());
    }

    public function test_a_pending_upgrade_without_existing_promo_or_problem_content_is_refused_before_backup(): void
    {
        $before = $this->snapshot();
        $this->assertSame(1, Artisan::call('lavaggio:deploy-content-upgrades', ['--apply' => true, '--backup-dir' => $this->backupRoot]));
        $this->assertStringContainsString('homepage promo block is required', Artisan::output());
        $this->assertSame($before, $this->snapshot());
        $this->assertSame([], File::files($this->backupRoot));
    }

    public function test_missing_nonprivate_and_symlink_backup_directories_are_refused_without_writes(): void
    {
        $this->promo();
        $insecure = $this->backupRoot.'/insecure';
        mkdir($insecure, 0755);
        chmod($insecure, 0755);
        $link = $this->backupRoot.'/linked';
        symlink($this->backupRoot, $link);
        $before = $this->snapshot();
        foreach (['', $this->backupRoot.'/missing', $insecure, $link, $this->backupRoot.'/.'] as $directory) {
            $this->assertSame(1, Artisan::call('lavaggio:deploy-content-upgrades', ['--apply' => true, '--backup-dir' => $directory]), $directory);
            $this->assertSame($before, $this->snapshot());
        }
        $this->assertSame([], File::files($this->backupRoot));
        $this->assertSame([], File::files($insecure));
    }

    public function test_wrong_theme_and_database_configuration_are_refused_without_touching_the_test_database(): void
    {
        $this->promo();
        $before = $this->snapshot();
        config(['storefront.home' => 'watchline']);
        $this->assertSame(1, Artisan::call('lavaggio:deploy-content-upgrades', ['--apply' => true, '--backup-dir' => $this->backupRoot]));
        $this->assertStringContainsString('Lavaggio storefront', Artisan::output());
        config(['storefront.home' => 'lavaggio']);
        app()->instance('env', 'production');
        try {
            $this->assertSame(1, Artisan::call('lavaggio:deploy-content-upgrades', ['--apply' => true, '--backup-dir' => $this->backupRoot]));
        } finally {
            app()->instance('env', 'testing');
        }
        $originalMysql = config('database.connections.mysql');
        try {
            config(['database.default' => 'mysql', 'database.connections.mysql.database' => 'different_shop',
                'database.connections.mysql.url' => null, 'database.connections.mysql.host' => '127.0.0.1',
                'database.connections.mysql.port' => 1, 'database.connections.mysql.username' => 'test-only',
                'database.connections.mysql.password' => '']);
            $this->assertSame(1, Artisan::call('lavaggio:deploy-content-upgrades', ['--apply' => true, '--backup-dir' => $this->backupRoot]));
            $this->assertStringContainsString('dedicated Lavaggio shop database', Artisan::output());
        } finally {
            config(['database.default' => 'sqlite', 'database.connections.mysql' => $originalMysql]);
            DB::purge('mysql');
        }
        $this->assertSame($before, $this->snapshot());
        $this->assertSame([], File::files($this->backupRoot));
    }

    public function test_backup_directories_under_either_public_web_root_are_rejected_even_with_private_permissions(): void
    {
        $this->promo();
        $before = $this->snapshot();
        $public = $this->backupRoot.'/public-root';
        $storage = $this->backupRoot.'/storage-root';
        File::ensureDirectoryExists($public.'/backups', 0700, true);
        File::ensureDirectoryExists($storage.'/app/public/backups', 0700, true);
        $originalPublic = public_path();
        $originalStorage = storage_path();
        app()->usePublicPath($public);
        app()->useStoragePath($storage);
        try {
            foreach ([$public.'/backups', $storage.'/app/public/backups'] as $directory) {
                $this->assertSame(1, Artisan::call('lavaggio:deploy-content-upgrades', ['--apply' => true, '--backup-dir' => $directory]));
                $this->assertStringContainsString('private backup directory', Artisan::output());
                $this->assertSame($before, $this->snapshot());
            }
        } finally {
            app()->usePublicPath($originalPublic);
            app()->useStoragePath($originalStorage);
        }
        $this->assertSame([], File::allFiles($this->backupRoot));
    }

    private function promo(): ContentBlock
    {
        $promo = ContentBlock::create(['code' => 'existing-authored-promo', 'name' => 'Postojeća promocija', 'type' => 'lavaggio_campaigns',
            'is_active' => true, 'payload' => ['editor_note' => 'keep-existing-content']]);
        $promo->translations()->create(['locale' => 'hr', 'title' => 'Njega prema našem izboru', 'subtitle' => 'Postojeći uređeni opis.',
            'cta_label' => 'Otvori ponudu', 'cta_url' => '/trgovina', 'payload' => ['watchline' => ['shipping_notice' => false]]]);
        $promo->slots()->create(['placement' => 'home.content', 'frontend_variant' => 'all', 'sort_order' => 10, 'is_active' => true]);

        return $promo;
    }

    private function contentSnapshot(): array
    {
        $snapshot = [];
        foreach (['content_blocks', 'content_block_translations', 'content_block_slots'] as $table) {
            $snapshot[$table] = DB::table($table)->orderBy('id')->get()->map(fn ($row) => (array) $row)->all();
        }

        return $snapshot;
    }

    private function snapshot(): array
    {
        return $this->contentSnapshot() + ['migrations' => DB::table('migrations')->orderBy('id')->get()->map(fn ($row) => (array) $row)->all()];
    }
}
