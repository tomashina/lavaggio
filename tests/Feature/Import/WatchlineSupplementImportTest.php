<?php

namespace Tests\Feature\Import;

use App\Models\Catalog\Product\Product;
use App\Models\Content\Blog\BlogPost;
use App\Models\User;
use App\Services\Import\WatchlineSupplementImportService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class WatchlineSupplementImportTest extends TestCase
{
    use RefreshDatabase;

    private string $sourceFile;

    private int $product;

    private int $blog;

    private int $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->sourceFile = tempnam(sys_get_temp_dir(), 'watchline-supplement-');
        config(['database.connections.watchline_test_source' => ['driver' => 'sqlite', 'database' => $this->sourceFile, 'prefix' => '']]);
        DB::purge('watchline_test_source');
        $this->product = DB::table('products')->insertGetId(['code' => 'test-watchline-product']);
        $this->blog = DB::table('content_blog_posts')->insertGetId(['code' => 'test-watchline-blog']);
        $this->user = User::factory()->create(['account_type' => 'customer', 'email' => 'buyer@example.test'])->id;
        $this->map('product', 10, $this->product);
        $this->map('blog', 20, $this->blog);
        $this->map('customer', 30, $this->user);
    }

    protected function tearDown(): void
    {
        DB::disconnect('watchline_test_source');
        unlink($this->sourceFile);
        parent::tearDown();
    }

    public function test_reviews_blog_comments_and_testimonials_are_native_and_preserve_moderation_on_replay(): void
    {
        $this->source('review', [
            ['review_id' => 1, 'product_id' => 10, 'customer_id' => 30, 'author' => 'Kupac', 'text' => 'Odličan &amp; precizan', 'rating' => 5, 'status' => 1, 'date_added' => '2021-01-02 10:11:12', 'date_modified' => '2021-01-03 10:11:12'],
            ['review_id' => 2, 'product_id' => 999, 'customer_id' => 0, 'author' => 'Gost', 'text' => 'Nedostajući proizvod', 'rating' => 3, 'status' => 1, 'date_added' => '2021-01-02 10:11:12', 'date_modified' => '2021-01-03 10:11:12'],
        ]);
        $this->source('blog_comment', [['blog_comment_id' => 2, 'blog_id' => 20, 'name' => 'Čitatelj', 'email' => 'reader@example.test', 'comment' => 'Koristan članak', 'date_added' => '2021-03-04 12:00:00', 'status' => 0]]);
        $this->source('testimonial', [['testimonial_id' => 3, 'name' => 'Kupac trgovine', 'image' => '', 'status' => 1]]);
        $this->source('testimonial_description', [['testimonial_id' => 3, 'language_id' => 2, 'description' => 'Brza dostava']]);

        $service = app(WatchlineSupplementImportService::class);
        $dry = $service->import('watchline_test_source', true);
        $this->assertSame(2, $dry['source_review']);
        $this->assertDatabaseCount('content_comments', 0);
        $summary = $service->import('watchline_test_source');
        $this->assertSame(1, $summary['review_missing_target']);
        $this->assertDatabaseCount('content_comments', 3);
        $this->assertDatabaseHas('content_comments', ['commentable_type' => (new Product)->getMorphClass(), 'commentable_id' => $this->product, 'user_id' => $this->user, 'rating' => 5, 'body' => 'Odličan & precizan', 'created_at' => '2021-01-02 10:11:12']);
        $this->assertDatabaseHas('content_comments', ['commentable_type' => (new BlogPost)->getMorphClass(), 'commentable_id' => $this->blog, 'body' => 'Koristan članak', 'status' => 'pending']);
        $this->assertDatabaseHas('content_comments', ['commentable_type' => null, 'body' => 'Brza dostava']);
        DB::table('content_comments')->where('rating', 5)->update(['status' => 'rejected', 'body' => 'Moderirano']);
        $service->import('watchline_test_source');
        $this->assertDatabaseCount('content_comments', 3);
        $this->assertDatabaseHas('content_comments', ['rating' => 5, 'status' => 'rejected', 'body' => 'Moderirano']);
        $this->assertDatabaseHas('herrera_source_records', ['source_table' => 'review', 'source_key' => '2']);
        $this->assertSame(2, DB::connection('watchline_test_source')->table('oc_review')->count());
    }

    public function test_newsletters_keep_source_consent_without_sending_or_overwriting_local_preferences(): void
    {
        Mail::fake();
        Queue::fake();
        $this->source('newsletter', [['id' => 1, 'email' => 'BUYER@example.test'], ['id' => 2, 'email' => 'guest@example.test'], ['id' => 3, 'email' => 'invalid-email']]);
        $this->source('customer', [['customer_id' => 30, 'email' => 'buyer@example.test', 'newsletter' => 1, 'date_added' => '2022-01-02 10:11:12']]);

        $service = app(WatchlineSupplementImportService::class);
        $summary = $service->import('watchline_test_source');
        $this->assertSame(1, $summary['newsletter_invalid']);
        $this->assertDatabaseCount('newsletter_signups', 2);
        $this->assertDatabaseHas('newsletter_signups', ['email' => 'buyer@example.test', 'user_id' => $this->user, 'consent_accepted' => true, 'provider' => 'database', 'sync_status' => 'skipped']);
        $this->assertDatabaseHas('newsletter_signups', ['email' => 'guest@example.test', 'consent_accepted' => false, 'subscribed_at' => null]);
        DB::table('newsletter_signups')->where('email', 'buyer@example.test')->update(['consent_accepted' => false]);
        $service->import('watchline_test_source');
        $this->assertDatabaseCount('newsletter_signups', 2);
        $this->assertDatabaseHas('newsletter_signups', ['email' => 'buyer@example.test', 'consent_accepted' => false]);
        Mail::assertNothingSent();
        Queue::assertNothingPushed();
    }

    public function test_native_filters_and_original_blog_product_links_preserve_local_changes_on_repeat(): void
    {
        $this->source('tf_filter', [['filter_id' => 1, 'status' => 1, 'sort_order' => 2]]);
        $this->source('tf_filter_description', [['filter_id' => 1, 'language_id' => 2, 'name' => 'Mehanizam']]);
        $this->source('tf_filter_value', [['value_id' => 2, 'filter_id' => 1, 'status' => 1, 'sort_order' => 0, 'value' => 'Automatski']]);
        $this->source('tf_filter_value_description', [['value_id' => 2, 'language_id' => 2, 'name' => 'Automatski']]);
        $this->source('tf_filter_value_to_product', [['value_id' => 2, 'product_id' => 10, 'trash' => 0], ['value_id' => 2, 'product_id' => 999, 'trash' => 0]]);
        $this->source('blog_related_products', [['blog_id' => 20, 'related_id' => 10]]);

        $service = app(WatchlineSupplementImportService::class);
        $service->import('watchline_test_source');
        $this->assertDatabaseHas('catalog_attribute_group_translations', ['locale' => 'hr', 'name' => 'Mehanizam']);
        $attribute = DB::table('catalog_attributes')->where('code', 'watchline-filter-value-2')->value('id');
        $this->assertDatabaseHas('catalog_attribute_product', ['attribute_id' => $attribute, 'product_id' => $this->product]);
        $payload = json_decode(DB::table('content_blog_posts')->where('id', $this->blog)->value('payload'), true);
        $this->assertSame([$this->product], $payload['related_product_ids']);
        $payload['related_product_ids'] = [];
        DB::table('content_blog_posts')->where('id', $this->blog)->update(['payload' => json_encode($payload)]);
        DB::table('catalog_attribute_product')->delete();
        $service->import('watchline_test_source');
        $this->assertDatabaseCount('catalog_attributes', 1);
        $this->assertDatabaseCount('catalog_attribute_product', 0);
        $this->assertSame([], json_decode(DB::table('content_blog_posts')->where('id', $this->blog)->value('payload'), true)['related_product_ids']);
    }

    public function test_rewards_and_historical_order_options_keep_their_original_records(): void
    {
        $order = DB::table('orders')->insertGetId(['order_number' => 'OC-WATCHLINE-100', 'customer_name' => 'Kupac', 'customer_email' => 'buyer@example.test']);
        $item = DB::table('order_items')->insertGetId(['order_id' => $order, 'product_id' => $this->product, 'name' => 'Sat']);
        $profile = DB::table('user_profiles')->insertGetId(['user_id' => $this->user]);
        $this->map('order', 100, $order);
        $this->map('order_product', 110, $item);
        $this->map('profile', 30, $profile);
        $this->source('customer_reward', [['customer_reward_id' => 1, 'customer_id' => 30, 'order_id' => 100, 'description' => 'Naslijeđeni bodovi', 'points' => 17, 'date_added' => '2020-01-02 10:00:00']]);
        $this->source('order_option', [['order_option_id' => 1, 'order_product_id' => 110, 'name' => 'Boja', 'value' => 'Plava', 'type' => 'select']]);
        $this->source('customer_history', [['customer_history_id' => 1, 'customer_id' => 30, 'comment' => 'Napomena', 'date_added' => '2020-02-03 12:00:00']]);

        $service = app(WatchlineSupplementImportService::class);
        $service->import('watchline_test_source');
        $service->import('watchline_test_source');
        $this->assertDatabaseCount('loyalty_transactions', 1);
        $this->assertDatabaseHas('loyalty_transactions', ['user_id' => $this->user, 'order_id' => $order, 'points' => 17, 'created_at' => '2020-01-02 10:00:00']);
        $this->assertSame('Plava', json_decode(DB::table('order_items')->where('id', $item)->value('payload'), true)['options'][0]['value']);
        $this->assertSame('Napomena', json_decode(DB::table('user_profiles')->where('id', $profile)->value('payload'), true)['opencart_customer_history'][0]['comment']);
    }

    public function test_product_questions_and_existing_answers_keep_their_parent_relationship(): void
    {
        $this->source('question', [['question_id' => 1, 'product_id' => 10, 'customer_id' => 30, 'author' => 'Kupac', 'email' => 'buyer@example.test', 'text' => 'Ima li jamstvo?', 'answer' => 'Da.', 'status' => 1, 'date_added' => '2022-01-02 10:00:00']]);

        $service = app(WatchlineSupplementImportService::class);
        $service->import('watchline_test_source');
        $service->import('watchline_test_source');

        $question = DB::table('content_comments')->where('body', 'Ima li jamstvo?')->first();
        $this->assertDatabaseCount('content_comments', 2);
        $this->assertDatabaseHas('content_comments', ['body' => 'Da.', 'parent_id' => $question->id, 'author_name' => 'Watchline']);
        $this->assertNull($question->rating);
    }

    public function test_anchor_prices_keep_the_confirmed_dated_reference_and_preserve_local_edits(): void
    {
        $this->source('anchor_price', [
            ['anchor_price_id' => 1, 'product_id' => 10, 'store_id' => 0, 'price' => '100.0000', 'gross_price' => '100.0000', 'currency_code' => 'EUR', 'reference_date' => '2025-12-01', 'rule_code' => 'reference_date', 'source' => 'manual', 'verification_status' => 'confirmed'],
            ['anchor_price_id' => 2, 'product_id' => 10, 'store_id' => 0, 'price' => '110.0000', 'gross_price' => '110.0000', 'currency_code' => 'EUR', 'reference_date' => '2026-01-01', 'rule_code' => 'reference_date', 'source' => 'manual', 'verification_status' => 'confirmed'],
            ['anchor_price_id' => 3, 'product_id' => 10, 'store_id' => 0, 'price' => '120.0000', 'gross_price' => '120.0000', 'currency_code' => 'EUR', 'reference_date' => '2026-02-01', 'rule_code' => 'reference_date', 'source' => 'manual', 'verification_status' => 'pending'],
        ]);

        $service = app(WatchlineSupplementImportService::class);
        $service->import('watchline_test_source');
        $payload = json_decode(DB::table('products')->where('id', $this->product)->value('payload'), true);
        $this->assertSame('2026-01-01', $payload['watchline_anchor_price']['reference_date']);
        $this->assertSame('110.0000', $payload['watchline_anchor_price']['gross_price']);
        $this->assertArrayNotHasKey('product_id', $payload['watchline_anchor_price']);
        $payload['watchline_anchor_price']['gross_price'] = '105.0000';
        DB::table('products')->where('id', $this->product)->update(['payload' => json_encode($payload)]);
        $service->import('watchline_test_source');
        $this->assertSame('105.0000', json_decode(DB::table('products')->where('id', $this->product)->value('payload'), true)['watchline_anchor_price']['gross_price']);
    }

    public function test_guard_rejects_the_destination_as_source(): void
    {
        $this->expectException(\RuntimeException::class);
        app(WatchlineSupplementImportService::class)->import(config('database.default'), true);
    }

    private function source(string $table, array $rows): void
    {
        Schema::connection('watchline_test_source')->create('oc_'.$table, function (Blueprint $schema) use ($rows): void {
            foreach (array_keys($rows[0]) as $column) {
                $schema->text($column)->nullable();
            }
        });
        DB::connection('watchline_test_source')->table('oc_'.$table)->insert($rows);
    }

    private function map(string $entity, int $sourceId, int $targetId): void
    {
        DB::table('herrera_import_maps')->insert(['source' => 'herrera-opencart', 'entity' => $entity, 'source_id' => $sourceId, 'target_id' => $targetId, 'checksum' => str_repeat('a', 64)]);
    }
}
