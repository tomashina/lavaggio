@php
    $hasApprovedReviews = \App\Models\Content\Support\Comment::query()
        ->status(\App\Models\Content\Support\Comment::STATUS_APPROVED)
        ->whereNull('parent_id')->whereBetween('rating', [1, 5])
        ->whereIn('locale', array_unique([app()->getLocale(), (string) config('app.fallback_locale', 'hr')]))
        ->when((bool) data_get($translation?->payload, 'reviews_featured_only', data_get($block->payload, 'reviews_featured_only', false)), fn ($query) => $query->where('is_featured', true))
        ->whereHasMorph('commentable', [\App\Models\Catalog\Product\Product::class], fn ($query) => $query->where('is_active', true)
            ->whereHas('translations', fn ($translations) => $translations
                ->whereIn('locale', array_unique([app()->getLocale(), (string) config('app.fallback_locale', 'hr')]))
                ->whereRaw("TRIM(COALESCE(slug, '')) <> ''")))->exists();
    $settings = \App\Support\WatchlineWidgetSettings::forBlock($block, $translation);
@endphp
@once('lavaggio-home-reviews-styles')
    @push('styles')
        <link rel="stylesheet" href="{{ asset('front-theme/styles/lavaggio-home-reviews.css') }}?v={{ filemtime(public_path('front-theme/styles/lavaggio-home-reviews.css')) }}">
    @endpush
@endonce
<div class="lavaggio-review-band" data-lavaggio-home-reviews>
@if($hasApprovedReviews)
    @include('front.content-blocks.types.watchline_reviews')
@else
    <section class="lavaggio-widget-section lavaggio-widget-reviews-empty" aria-labelledby="lavaggio-reviews-{{ $block->id }}">
        <x-fa-icon name="comments" style="duotone" />
        <div><p class="lavaggio-widget-eyebrow">{{ $settings['eyebrow'] }}</p><h2 id="lavaggio-reviews-{{ $block->id }}">{{ $translation?->title }}</h2><p>{{ $settings['empty_message'] }}</p></div>
    </section>
@endif
</div>
