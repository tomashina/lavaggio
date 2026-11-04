<?php

namespace Tests\Feature\Import;

use App\Models\Content\ContentBlock;
use App\Services\Content\ContentBlockResolver;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

class LavaggioHomeProblemsMigrationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'app.name' => 'Lavaggio', 'app.locale' => 'hr', 'app.fallback_locale' => 'hr',
            'storefront.home' => 'lavaggio', 'storefront.theme' => 'herrera', 'storefront.brand' => 'libermedia',
            'storefront_cache.enabled' => false,
        ]);
        app()->setLocale('hr');
    }

    public function test_real_migration_inserts_three_records_between_widgets_and_renders_the_opening_pair(): void
    {
        $campaign = $this->widget('campaign', 'lavaggio_campaigns', 10);
        $following = $this->widget('following', 'lavaggio_categories', 20);
        $before = $this->snapshot();
        // Deployment defaults are frozen, even if application defaults change later.
        config(['lavaggio_widgets.lavaggio_problems.fields.card_1_title.default' => 'Budući zadani tekst']);

        $this->migration()->up();

        $block = ContentBlock::query()->where('code', 'lavaggio-home-problems')->sole();
        $translation = $block->translations()->sole();
        $slot = $block->slots()->sole();
        $this->assertTrue($block->is_active);
        $this->assertSame('lavaggio_problems', $block->type);
        $this->assertSame('hr', $translation->locale);
        $this->assertSame('Odaberi deterdžent za problem', $translation->title);
        $this->assertSame('home.content', $slot->placement);
        $this->assertSame('all', $slot->frontend_variant);
        $this->assertSame(15, (int) $slot->sort_order);
        $this->assertTrue($slot->is_active);
        $this->assertNull($slot->starts_at);
        $this->assertNull($slot->ends_at);
        foreach (['content_blocks', 'content_block_translations', 'content_block_slots'] as $table) {
            $this->assertDatabaseCount($table, 3);
            $this->assertSame($before[$table], $this->snapshot($before)[$table]);
        }
        $this->assertSame([$campaign->id, $block->id, $following->id], $this->orderedBlockIds());
        $expected = ['Neugodni mirisi', 'Sivo i požutjelo rublje', 'Tvrdokorne mrlje', 'Mrlje od krema i masnoća', 'Osjetljive tkanine', 'Održavanje perilice rublja'];
        foreach ($expected as $index => $title) {
            $settings = $translation->payload['watchline'];
            $this->assertSame($title, $settings['card_'.($index + 1).'_title']);
            $this->assertTrue($settings['card_'.($index + 1).'_enabled']);
        }
        $this->get(route('home'))->assertOk()->assertSee('data-lavaggio-home-intro', false)
            ->assertSee('data-lavaggio-problems', false)->assertSeeInOrder($expected);
    }

    public function test_up_is_idempotent_and_down_preserves_later_editorial_changes(): void
    {
        $this->widget('campaign', 'lavaggio_campaigns', 10);
        $migration = $this->migration();
        $migration->up();
        $block = ContentBlock::query()->where('code', 'lavaggio-home-problems')->sole();
        $block->update(['name' => 'Urednički odabir', 'is_active' => false]);
        $block->translations()->update(['title' => 'Vlastiti naslov', 'payload' => json_encode(['watchline' => ['card_1_title' => 'Vlastita kartica']])]);
        $block->slots()->update(['sort_order' => 85, 'is_active' => false, 'starts_at' => '2027-01-01 08:00:00']);
        $before = $this->snapshot();

        $migration->up();
        $migration->down();
        $migration->down();
        $migration->up();

        $this->assertSame($before, $this->snapshot());
    }

    #[DataProvider('existingProblems')]
    public function test_existing_canonical_or_custom_home_problem_blocks_are_preserved(string $code, bool $active): void
    {
        $this->widget('campaign', 'lavaggio_campaigns', 10);
        $block = $this->widget($code, 'lavaggio_problems', 77, false);
        $block->update(['code' => $code, 'name' => 'Prilagođeni blok', 'is_active' => $active]);
        $block->translations()->update(['title' => 'Klijentov naslov']);
        $before = $this->snapshot();

        $this->migration()->up();

        $this->assertSame($before, $this->snapshot());
    }

    public function test_an_existing_canonical_code_without_a_home_slot_is_not_repurposed(): void
    {
        $this->widget('campaign', 'lavaggio_campaigns', 10);
        ContentBlock::create(['code' => 'lavaggio-home-problems', 'name' => 'Sačuvaj me', 'type' => 'rich_text', 'is_active' => false]);
        $before = $this->snapshot();
        $this->migration()->up();
        $this->assertSame($before, $this->snapshot());
    }

    public function test_fresh_lavaggio_installation_without_an_existing_promo_home_slot_is_untouched(): void
    {
        $this->widget('following', 'lavaggio_categories', 20);
        $before = $this->snapshot();
        $this->migration()->up();
        $this->assertSame($before, $this->snapshot());
    }

    public function test_other_storefront_is_untouched_even_when_it_contains_a_lavaggio_promo_fixture(): void
    {
        $this->widget('campaign', 'lavaggio_campaigns', 10);
        config(['storefront.home' => 'watchline']);
        $before = $this->snapshot();
        $this->migration()->up();
        $this->assertSame($before, $this->snapshot());
    }

    public function test_adjacent_integer_orders_use_the_campaign_order_without_moving_other_slots(): void
    {
        $preceding = $this->widget('preceding', 'lavaggio_categories', 10);
        $campaign = $this->widget('campaign', 'lavaggio_campaigns', 10);
        $following = $this->widget('following', 'lavaggio_blog', 11);
        $before = $this->snapshot();
        $this->migration()->up();
        $block = ContentBlock::query()->where('code', 'lavaggio-home-problems')->sole();
        $this->assertSame(10, (int) $block->slots()->sole()->sort_order);
        $this->assertSame([$preceding->id, $campaign->id, $block->id, $following->id], $this->orderedBlockIds());
        $this->assertSame($before, $this->snapshot($before));
    }

    public function test_an_ambiguous_tie_stops_before_any_insert_without_reordering_existing_content(): void
    {
        $this->widget('campaign', 'lavaggio_campaigns', 10);
        $this->widget('following', 'lavaggio_categories', 10);
        $before = $this->snapshot();
        try {
            $this->migration()->up();
            $this->fail('An ambiguous tie must require an explicit editorial ordering decision.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('share sort_order 10', $exception->getMessage());
        }
        $this->assertSame($before, $this->snapshot());
    }

    public function test_ordering_uses_the_same_global_slot_scope_as_the_frontend_even_with_a_leftover_target_ref(): void
    {
        $campaign = $this->widget('campaign', 'lavaggio_campaigns', 10);
        $campaign->slots()->update(['target_ref' => '']);
        $following = $this->widget('following', 'lavaggio_categories', 20);
        $following->slots()->update(['target_ref' => 'legacy-unused-reference']);
        $before = $this->snapshot();

        $this->migration()->up();

        $block = ContentBlock::query()->where('code', 'lavaggio-home-problems')->sole();
        $this->assertSame([$campaign->id, $block->id, $following->id], $this->orderedBlockIds());
        $this->assertSame(15, (int) $block->slots()->sole()->sort_order);
        $this->assertSame($before, $this->snapshot($before));
    }

    public function test_a_last_promo_widget_gets_a_following_problem_slot_without_being_modified(): void
    {
        $campaign = $this->widget('campaign', 'lavaggio_campaigns', 47);
        $before = $this->snapshot();
        $this->migration()->up();
        $block = ContentBlock::query()->where('code', 'lavaggio-home-problems')->sole();
        $this->assertSame(48, (int) $block->slots()->sole()->sort_order);
        $this->assertSame([$campaign->id, $block->id], $this->orderedBlockIds());
        $this->assertSame($before, $this->snapshot($before));
    }

    public function test_a_translation_insert_failure_rolls_back_the_whole_three_record_operation(): void
    {
        $this->widget('campaign', 'lavaggio_campaigns', 10);
        $before = $this->snapshot();
        DB::unprepared("CREATE TEMP TRIGGER reject_problem_translation BEFORE INSERT ON content_block_translations WHEN NEW.title = 'Odaberi deterdžent za problem' BEGIN SELECT RAISE(ABORT, 'problem migration atomicity test'); END");
        try {
            $this->migration()->up();
            $this->fail('The translation failure must abort the migration.');
        } catch (QueryException $exception) {
            $this->assertStringContainsString('problem migration atomicity test', $exception->getMessage());
        } finally {
            DB::unprepared('DROP TRIGGER reject_problem_translation');
        }
        $this->assertSame($before, $this->snapshot());
    }

    public static function existingProblems(): array
    {
        return [['lavaggio-home-problems', true], ['lavaggio-home-problems', false], ['custom-problems', true], ['custom-hidden-problems', false]];
    }

    private function migration(): Migration
    {
        return require database_path('migrations/2026_11_04_113000_add_lavaggio_home_problems_widget.php');
    }

    private function widget(string $code, string $type, int $order, bool $slotActive = true): ContentBlock
    {
        $block = ContentBlock::create(['code' => 'migration-'.$code, 'name' => $code, 'type' => $type, 'is_active' => true]);
        $block->translations()->create(['locale' => 'hr', 'title' => $type === 'lavaggio_campaigns' ? 'Sačuvana promocija' : $code]);
        $block->slots()->create(['placement' => 'home.content', 'frontend_variant' => 'all', 'sort_order' => $order, 'is_active' => $slotActive]);

        return $block;
    }

    private function orderedBlockIds(): array
    {
        return app(ContentBlockResolver::class)->forPlacement('home.content', 'hr', null, null, 'desktop')
            ->map(fn ($item) => $item['block']->id)->all();
    }

    private function snapshot(?array $existing = null): array
    {
        $snapshot = [];
        foreach (['content_blocks', 'content_block_translations', 'content_block_slots'] as $table) {
            $query = DB::table($table)->orderBy('id');
            if ($existing !== null) {
                $query->whereIn('id', array_column($existing[$table], 'id'));
            }
            $snapshot[$table] = $query->get()->map(fn ($row) => (array) $row)->all();
        }

        return $snapshot;
    }
}
