<?php

namespace App\Services\Import;

use App\Models\Catalog\Category\Category;
use App\Models\Content\Blog\BlogPost;
use App\Support\StorefrontTheme;
use Illuminate\Support\Facades\DB;

class LibermediaBlogCategoryService
{
    public const PROVENANCE_KEY = 'libermedia_editorial_blog_v1';

    public function apply(): int
    {
        if (! StorefrontTheme::usesLibermediaBrand()) {
            return 0;
        }

        return DB::transaction(function (): int {
            $linked = 0;
            foreach ((array) config('libermedia-blog-categories', []) as $code => $definition) {
                $posts = BlogPost::query()->whereIn('code', (array) ($definition['posts'] ?? []))->get();
                if ($posts->isEmpty()) {
                    continue;
                }
                $category = Category::query()->firstOrCreate(['scope' => Category::SCOPE_BLOG, 'code' => $code], [
                    'is_active' => true, 'show_in_menu' => false, 'sort_order' => 0,
                ]);
                $category = Category::query()->whereKey($category->id)->lockForUpdate()->firstOrFail();
                $category->translations()->firstOrCreate(['locale' => $definition['locale'] ?? 'hr'], [
                    'scope' => Category::SCOPE_BLOG, 'name' => $definition['name'], 'slug' => $definition['slug'],
                ]);
                $payload = $category->payload ?? [];
                $provenance = (array) ($payload[self::PROVENANCE_KEY] ?? []);
                foreach ($posts as $post) {
                    if (isset($provenance['posts'][$post->code])) {
                        continue;
                    }
                    $exists = $post->categories()->whereKey($category->id)->exists();
                    if (! $exists) {
                        $post->categories()->attach($category->id, ['sort_order' => 0, 'is_primary' => ! $post->categories()->exists()]);
                        $linked++;
                    }
                    // Keep provenance on the native category, outside imported
                    // article payloads. Re-imports cannot re-add a manual unlink.
                    $provenance['posts'][$post->code] = [
                        'post_id' => $post->id, 'applied_at' => now()->toIso8601String(),
                        'mode' => $exists ? 'preserved_existing' : 'editorial',
                    ];
                }
                $provenance['source'] = 'config/libermedia-blog-categories.php';
                $payload[self::PROVENANCE_KEY] = $provenance;
                $category->update(['payload' => $payload]);
            }

            return $linked;
        });
    }
}
