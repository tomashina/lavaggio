<?php

namespace App\Http\Controllers\Front;

use App\Http\Controllers\Controller;
use App\Models\Catalog\Product\Product;
use App\Models\Content\Blog\BlogPost;
use App\Models\Content\Support\Comment;
use App\Services\Catalog\CatalogFeatureService;
use App\Services\Front\StoreSettingsService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\View\View;

class StorefrontController extends Controller
{
    public function __construct(
        private readonly StoreSettingsService $storeSettingsService
    ) {}

    public function home(): View
    {
        $featuredReviews = collect();
        $homeBlogPosts = collect();
        if (config('storefront.home') === 'libermedia') {
            $locales = array_unique([app()->getLocale(), (string) config('app.fallback_locale', 'hr')]);
            $featuredReviews = Comment::query()
                ->when(config('storefront.home') !== 'watchline', fn (Builder $query) => $query->where('is_featured', true))
                ->status(Comment::STATUS_APPROVED)
                ->whereNull('parent_id')
                ->whereBetween('rating', [1, 5])
                ->whereIn('locale', $locales)
                ->whereHasMorph('commentable', [Product::class], function (Builder $query) use ($locales): void {
                    $query->where('is_active', true)
                        ->whereHas('translations', fn (Builder $translations) => $translations
                            ->whereIn('locale', $locales)->whereNotNull('slug')->where('slug', '!=', ''));
                })
                ->with([
                    'user:id,name',
                    'commentable.media',
                    'commentable.translations' => fn ($query) => $query
                        ->whereIn('locale', $locales)->whereNotNull('slug')->where('slug', '!=', ''),
                ])
                ->orderByDesc('reviewed_at')
                ->orderByDesc('id')
                ->get();

            if (app(CatalogFeatureService::class)->useBlog()) {
                $homeBlogPosts = BlogPost::query()
                    ->where('is_active', true)
                    ->where('published_at', '<=', now())
                    ->whereHas('translations', fn (Builder $translations) => $translations
                        ->whereIn('locale', $locales)->whereNotNull('slug')->where('slug', '!=', ''))
                    ->with([
                        'translations' => fn ($translations) => $translations
                            ->whereIn('locale', $locales)->whereNotNull('slug')->where('slug', '!=', ''),
                        'media',
                    ])
                    ->orderByDesc('published_at')
                    ->orderByDesc('id')
                    ->limit(5)
                    ->get();
            }
        }

        return view('front.desktop.home.index', [
            'storeSettings' => $this->storeSettingsService->all(),
            'featuredReviews' => $featuredReviews,
            'homeBlogPosts' => $homeBlogPosts,
        ]);
    }
}
