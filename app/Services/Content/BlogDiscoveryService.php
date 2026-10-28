<?php

namespace App\Services\Content;

use App\Models\Catalog\Category\Category;
use App\Models\Content\Blog\BlogPost;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

class BlogDiscoveryService
{
    public function related(BlogPost $post, array $locales, int $limit = 3): Collection
    {
        $categoryIds = $post->categories->filter(fn (Category $category): bool => $category->scope === Category::SCOPE_BLOG
            && $category->isCurrentlyVisible())->modelKeys();
        $tags = $this->tags($post);
        $tagPostIds = [];
        if ($tags !== []) {
            // Tags are preserved as original comma-separated OpenCart metadata.
            // Compare complete normalized tags, never substrings of article text.
            $tagPostIds = $this->query($post, $locales)->get(['id'])->filter(
                fn (BlogPost $candidate): bool => array_intersect($tags, $this->tags($candidate)) !== []
            )->modelKeys();
        }
        if ($categoryIds === [] && $tagPostIds === []) {
            return collect();
        }

        return $this->query($post, $locales)
            ->where(function (Builder $query) use ($categoryIds, $tagPostIds): void {
                if ($categoryIds !== []) {
                    $query->whereHas('categories', fn (Builder $categories) => $categories->whereIn('categories.id', $categoryIds));
                }
                if ($tagPostIds !== []) {
                    $query->orWhereIn('id', $tagPostIds);
                }
            })
            ->orderByDesc('published_at')->orderByDesc('id')->limit($limit)->get();
    }

    public function popular(BlogPost $post, array $locales, int $limit = 3): Collection
    {
        return $this->query($post, $locales)->mostRead()
            ->orderByDesc('published_at')->orderByDesc('id')->limit($limit)->get();
    }

    private function query(BlogPost $post, array $locales): Builder
    {
        return BlogPost::query()->visibleOnBlog($locales)->whereKeyNot($post->getKey())
            ->with([
                'translations' => fn ($translation) => $translation->whereIn('locale', $locales)
                    ->whereRaw("TRIM(COALESCE(title, '')) <> ''")->whereRaw("TRIM(COALESCE(slug, '')) <> ''"),
                'media',
                'categories' => fn ($categories) => $categories->where('scope', Category::SCOPE_BLOG)->currentlyVisible()
                    ->with(['translations' => fn ($translation) => $translation->whereIn('locale', $locales)]),
            ]);
    }

    private function tags(BlogPost $post): array
    {
        return $post->translations->flatMap(function ($translation): array {
            $tags = data_get($translation->payload, 'tags', data_get($translation->payload, 'opencart.tags', []));

            return is_array($tags) ? $tags : preg_split('/[,;]+/u', (string) $tags);
        })->filter(fn ($tag): bool => is_string($tag) && trim($tag) !== '')
            ->map(fn (string $tag): string => mb_strtolower(trim(preg_replace('/\s+/u', ' ', html_entity_decode($tag, ENT_QUOTES | ENT_HTML5, 'UTF-8')))))
            ->unique()->values()->all();
    }
}
