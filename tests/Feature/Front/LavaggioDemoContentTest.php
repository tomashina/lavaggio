<?php

namespace Tests\Feature\Front;

use App\Models\Catalog\Action\CatalogAction;
use App\Models\Catalog\Product\Product;
use App\Models\Content\Support\Comment;
use App\Services\Pricing\ProductPricePresentationService;
use Database\Seeders\LavaggioDemoContentSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\TestCase;

class LavaggioDemoContentTest extends TestCase
{
    use RefreshDatabase;

    public function test_demo_content_preserves_existing_records_and_is_repeatable(): void
    {
        $this->app->detectEnvironment(fn () => 'local');
        config(['storefront.home' => 'lavaggio', 'commerce.b2c_only' => true]);
        $barcodes = array_unique([...array_column(LavaggioDemoContentSeeder::REVIEWS, 0), ...array_keys(LavaggioDemoContentSeeder::PROMOTIONS)]);
        foreach ($barcodes as $barcode) {
            $product = Product::query()->create([
                'code' => 'lavaggio-'.$barcode, 'barcode' => $barcode,
                'is_active' => true, 'base_price' => 20, 'stock_qty' => 10,
                'payload' => ['lavaggio_import' => ['local_demo' => true]],
            ]);
            $product->translations()->create(['locale' => 'hr', 'name' => 'Proizvod '.$barcode, 'slug' => 'proizvod-'.$barcode]);
        }
        $product = Product::query()->firstOrFail();
        $existing = $product->comments()->create([
            'author_name' => 'Existing reviewer', 'body' => 'Existing review', 'rating' => 4,
            'status' => Comment::STATUS_PENDING, 'locale' => 'hr',
        ]);
        $existingAction = CatalogAction::query()->create([
            'code' => 'existing-promotion', 'scope' => CatalogAction::SCOPE_PRODUCT,
            'type' => CatalogAction::TYPE_PERCENTAGE, 'discount_value' => 5,
            'target_type' => CatalogAction::TARGET_PRODUCT, 'audience_type' => CatalogAction::AUDIENCE_ALL,
            'is_active' => true,
        ]);
        $existingAction->targets()->create(['target_type' => CatalogAction::TARGET_PRODUCT, 'target_id' => $product->id]);

        app(LavaggioDemoContentSeeder::class)->run();

        $this->assertSame(31, Comment::query()->count());
        $this->assertSame(9, CatalogAction::query()->count());
        $demoReviews = Comment::query()->where('payload->demo_source', LavaggioDemoContentSeeder::SOURCE)->get();
        $this->assertCount(30, $demoReviews);
        $this->assertSame(30, $demoReviews->pluck('commentable_id')->unique()->count());
        $this->assertTrue($demoReviews->every(fn ($review) => $review->status === Comment::STATUS_APPROVED
            && $review->is_featured && $review->user_id === null && $review->reviewed_by === null
            && $review->payload['local_demo'] === true && ! str_contains($review->author_name, '(demo)')));
        $this->assertSame(array_column(LavaggioDemoContentSeeder::REVIEWS, 1), $demoReviews->pluck('author_name')->all());
        foreach (LavaggioDemoContentSeeder::PROMOTIONS as $barcode => $discount) {
            $price = app(ProductPricePresentationService::class)->forProduct(Product::query()->where('barcode', $barcode)->firstOrFail());
            $this->assertTrue($price['has_promotional_discount']);
            $this->assertEqualsWithDelta($price['old_gross'] * (1 - $discount / 100), $price['current_gross'], 0.01);
        }

        $demoReviews->first()->update(['author_name' => 'Uređeno ime', 'body' => 'Edited example', 'is_featured' => false]);
        $demoReviews->last()->delete();
        $demoAction = CatalogAction::query()->where('payload->demo_source', LavaggioDemoContentSeeder::SOURCE)->firstOrFail();
        $demoAction->update(['discount_value' => 7, 'is_active' => false]);
        app(LavaggioDemoContentSeeder::class)->run();

        $this->assertSame(31, Comment::withTrashed()->count());
        $this->assertSame(29, Comment::query()->where('payload->demo_source', LavaggioDemoContentSeeder::SOURCE)->count());
        $this->assertSame('Edited example', $demoReviews->first()->fresh()->body);
        $this->assertSame('Uređeno ime', $demoReviews->first()->fresh()->author_name);
        $this->assertFalse($demoReviews->first()->fresh()->is_featured);
        $this->assertSame('7.00', $demoAction->fresh()->discount_value);
        $this->assertFalse($demoAction->fresh()->is_active);
        $this->assertSame(9, CatalogAction::query()->count());
        $this->assertSame('Existing review', $existing->fresh()->body);
        $this->assertSame(Comment::STATUS_PENDING, $existing->fresh()->status);
        $this->assertSame('5.00', $existingAction->fresh()->discount_value);
    }

    public function test_demo_content_refuses_nonlocal_environments(): void
    {
        config(['storefront.home' => 'lavaggio']);
        $this->expectException(RuntimeException::class);
        app(LavaggioDemoContentSeeder::class)->run();
    }

    public function test_command_refuses_a_database_other_than_the_local_preview_file(): void
    {
        $this->app->detectEnvironment(fn () => 'local');
        config(['storefront.home' => 'lavaggio']);
        $this->artisan('lavaggio:seed-demo-content')->assertFailed();
        $this->assertSame(0, Comment::query()->count());
        $this->assertSame(0, CatalogAction::query()->count());
    }

    public function test_missing_demo_products_are_detected_before_any_content_is_written(): void
    {
        $this->app->detectEnvironment(fn () => 'local');
        config(['storefront.home' => 'lavaggio']);
        try {
            app(LavaggioDemoContentSeeder::class)->run();
            $this->fail('Expected missing demo products to stop seeding.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('Missing active local demo product', $exception->getMessage());
        }
        $this->assertSame(0, Comment::query()->count());
        $this->assertSame(0, CatalogAction::query()->count());
    }
}
