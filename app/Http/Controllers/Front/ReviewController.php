<?php

namespace App\Http\Controllers\Front;

use App\Http\Controllers\Controller;
use App\Models\Catalog\Product\Product;
use App\Models\Content\Support\Comment;
use App\Services\Front\StoreSettingsService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\View\View;

class ReviewController extends Controller
{
    public function index(StoreSettingsService $storeSettingsService): View
    {
        $locales = array_unique([app()->getLocale(), (string) config('app.fallback_locale', 'hr')]);
        $query = Comment::query()
            ->status(Comment::STATUS_APPROVED)
            ->whereNull('parent_id')
            ->whereBetween('rating', [1, 5])
            ->whereIn('locale', $locales)
            ->whereHasMorph('commentable', [Product::class], function (Builder $products) use ($locales): void {
                $products->where('is_active', true)
                    ->whereHas('translations', fn (Builder $translations) => $translations
                        ->whereIn('locale', $locales)->whereNotNull('slug')->where('slug', '!=', ''));
            });

        $averageRating = (float) ((clone $query)->avg('rating') ?? 0);
        $reviews = $query
            ->with([
                'user:id,name',
                'commentable.media',
                'commentable.translations' => fn ($translations) => $translations
                    ->whereIn('locale', $locales)->whereNotNull('slug')->where('slug', '!=', ''),
            ])
            ->orderByDesc('reviewed_at')
            ->orderByDesc('id')
            ->paginate(30)
            ->withQueryString();
        \App\Support\WatchlineSeo::ensureValidPagination($reviews);

        return view('front.desktop.reviews.index', [
            'reviews' => $reviews,
            'reviewCount' => $reviews->total(),
            'averageRating' => $averageRating,
            'storeSettings' => $storeSettingsService->all(),
        ]);
    }
}
