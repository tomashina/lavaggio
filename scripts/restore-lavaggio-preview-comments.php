<?php

// Restore the previously used fictional comments for the authorised layout presentation.
// Demo provenance remains in metadata; this script never fabricates customer/purchase links.
require __DIR__.'/../vendor/autoload.php';
$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\Catalog\Product\Product;
use App\Models\Content\Support\Comment;
use App\Services\Content\ContentBlockResolver;
use App\Services\Front\GuestStorefrontCache;
use Illuminate\Support\Facades\DB;

$local = app()->environment('local') && DB::getDriverName() === 'sqlite'
    && realpath(DB::getDatabaseName()) === realpath(database_path('database.sqlite'));
$hosted = app()->environment('staging') && realpath(base_path()) === '/home/baredahr/shop.lavaggio.hr'
    && DB::getDriverName() === 'mysql' && DB::getDatabaseName() === 'baredahr_lavaggioshop';
if (config('storefront.home') !== 'lavaggio' || (! $local && ! $hosted)) {
    throw new RuntimeException('Only the local or hosted Lavaggio presentation environment is allowed.');
}

// Original comment number => new SKU. Select examples suitable for the product type.
$assignments = [22 => '200004', 3 => '200009', 4 => '200018', 7 => '200047', 9 => '200040',
    14 => '200007', 16 => '200003', 26 => '200029', 1 => '200005', 6 => '200048'];
$source = Database\Seeders\LavaggioDemoContentSeeder::REVIEWS;
$products = Product::query()->where('is_active', true)->whereIn('sku', array_values($assignments))->get()->keyBy('sku');
if ($products->count() !== 10) {
    throw new RuntimeException('Expected all ten presentation products.');
}
if (! in_array('--execute', $argv, true)) {
    echo json_encode(['environment' => app()->environment(), 'comments' => 10, 'assignments' => $assignments]).PHP_EOL;
    exit(0);
}

$backup = storage_path('app/private/backups/comments-before-presentation-'.date('Ymd-His').'-'.bin2hex(random_bytes(3)).'.json');
$file = fopen($backup, 'x');
if (! $file) {
    throw new RuntimeException('Cannot save the existing comments.');
}
chmod($backup, 0600);
fwrite($file, json_encode(DB::table('content_comments')->get(), JSON_THROW_ON_ERROR));
fclose($file);

$added = 0;
DB::transaction(function () use ($assignments, $source, $products, &$added): void {
    foreach ($assignments as $number => $sku) {
        $key = Database\Seeders\LavaggioDemoContentSeeder::SOURCE.'-review-'.str_pad((string) $number, 2, '0', STR_PAD_LEFT);
        if (Comment::withTrashed()->where('payload->lavaggio_demo_review', $key)->exists()) {
            continue;
        }
        [$oldEan, $name, $rating, $body] = $source[$number - 1];
        // Preserve the original example dates verified in the pre-replacement backup.
        $reviewedAt = \Illuminate\Support\Carbon::parse('2026-10-29 19:00:37')->subHours($number * 9);
        $comment = new Comment([
            'commentable_type' => (new Product)->getMorphClass(), 'commentable_id' => $products[$sku]->id,
            'author_name' => $name, 'author_email' => 'review-'.$number.'@lavaggio.example.test',
            'locale' => 'hr', 'body' => $body, 'rating' => $rating, 'status' => Comment::STATUS_APPROVED,
            'is_featured' => true, 'reviewed_at' => $reviewedAt,
            'payload' => ['local_demo' => true, 'demo_source' => Database\Seeders\LavaggioDemoContentSeeder::SOURCE,
                'lavaggio_demo_review' => $key, 'presentation_assignment' => 'catalog-20261113',
                'original_product_ean' => $oldEan, 'assigned_sku' => $sku],
        ]);
        $comment->created_at = $reviewedAt;
        $comment->updated_at = $reviewedAt;
        $comment->save();
        $added++;
    }
});
ContentBlockResolver::bumpCacheVersion();
app(GuestStorefrontCache::class)->invalidate();
echo json_encode(['added' => $added, 'presentation_comments' => Comment::query()->where('payload->presentation_assignment', 'catalog-20261113')->count(),
    'products_with_comments' => Comment::query()->where('payload->presentation_assignment', 'catalog-20261113')->distinct()->count('commentable_id')]).PHP_EOL;
