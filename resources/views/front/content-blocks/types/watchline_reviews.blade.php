@php
    $reviewsWidgetSettings = \App\Support\WatchlineWidgetSettings::forBlock($block, $translation);
    $reviewsWidgetPayload = array_merge((array) ($block->payload ?? []), (array) ($translation?->payload ?? []));
    $reviewsWidgetLimit = max(1, min(50, (int) ($reviewsWidgetPayload['items_limit'] ?? 50)));
    $reviewsWidgetLocales = array_values(array_unique([app()->getLocale(), (string) config('app.fallback_locale', 'hr')]));
    $reviewsWidgetTitle = trim((string) ($translation?->title ?: $block->name));
    $reviewsWidgetSubtitle = trim((string) ($translation?->subtitle ?? ''));
    $reviewsWidgetCtaLabel = trim((string) ($translation?->cta_label ?? ''));
    $reviewsWidgetCtaUrl = trim((string) ($reviewsWidgetSettings['cta_url'] ?? ''));
    $reviewsWidgetEyebrow = trim((string) ($reviewsWidgetSettings['eyebrow'] ?? ''));
    $reviewsWidgetHeadingId = 'watchline-reviews-title-'.$block->id;
    $reviewsWidgetRows = \App\Models\Content\Support\Comment::query()
        ->status(\App\Models\Content\Support\Comment::STATUS_APPROVED)
        ->whereNull('parent_id')->whereBetween('rating', [1, 5])->whereIn('locale', $reviewsWidgetLocales)
        ->when((bool) ($reviewsWidgetPayload['reviews_featured_only'] ?? false), fn ($query) => $query->where('is_featured', true))
        ->whereHasMorph('commentable', [\App\Models\Catalog\Product\Product::class], function ($query) use ($reviewsWidgetLocales) {
            $query->where('is_active', true)->whereHas('translations', fn ($translationQuery) => $translationQuery
                ->whereIn('locale', $reviewsWidgetLocales)->whereRaw("TRIM(COALESCE(slug, '')) <> ''"));
        })
        ->with(['user:id,name', 'commentable.media', 'commentable.translations' => fn ($query) => $query
            ->whereIn('locale', $reviewsWidgetLocales)->whereRaw("TRIM(COALESCE(slug, '')) <> ''")])
        ->orderByDesc('reviewed_at')->orderByDesc('id')->limit($reviewsWidgetLimit)->get();
    $reviewsWidgetCount = $reviewsWidgetRows->count();
    $reviewsWidgetOptions = [
        'type' => 'slide', 'perPage' => 3, 'perMove' => 1, 'gap' => '24px',
        'arrows' => $reviewsWidgetCount > 3, 'destroy' => $reviewsWidgetCount <= 3,
        'pagination' => false, 'drag' => $reviewsWidgetCount > 1, 'speed' => 420,
        'reducedMotion' => ['speed' => 0, 'rewindSpeed' => 0],
        'i18n' => [
            'prev' => 'Prethodne recenzije', 'next' => 'Sljedeće recenzije', 'first' => 'Prva recenzija', 'last' => 'Posljednja recenzija',
            'carousel' => 'klizač recenzija', 'slide' => 'recenzija', 'slideX' => 'Idi na recenziju %s', 'slideLabel' => '%s od %s',
        ],
        'breakpoints' => [
            1023 => ['perPage' => 2, 'gap' => '16px', 'destroy' => $reviewsWidgetCount <= 2, 'arrows' => $reviewsWidgetCount > 2],
            767 => ['perPage' => 1, 'gap' => '16px', 'destroy' => $reviewsWidgetCount <= 1, 'arrows' => $reviewsWidgetCount > 1],
        ],
    ];
@endphp

@if ($reviewsWidgetRows->isNotEmpty())
    @include('front.partials.splide-assets')
    @once('libermedia-featured-reviews-styles')
        @push('styles')
            <link rel="stylesheet" href="{{ asset('front-theme/styles/libermedia-featured-reviews.css') }}?v={{ filemtime(public_path('front-theme/styles/libermedia-featured-reviews.css')) }}">
        @endpush
    @endonce
    <section class="splide lm-home-reviews" aria-labelledby="{{ $reviewsWidgetHeadingId }}" data-watchline-reviews-widget="{{ $block->id }}" data-libermedia-home-reviews data-libermedia-home-reviews-carousel data-herrera-home-carousel data-splide='@json($reviewsWidgetOptions)'>
        <header class="lm-home-reviews-heading">
            <div>
                @if ($reviewsWidgetEyebrow !== '')<p class="lm-home-reviews-eyebrow">{{ $reviewsWidgetEyebrow }}</p>@endif
                <h2 id="{{ $reviewsWidgetHeadingId }}">{{ $reviewsWidgetTitle }}</h2>
            </div>
            @if ($reviewsWidgetSubtitle !== '')<p class="lm-home-reviews-caption">{{ $reviewsWidgetSubtitle }}</p>@endif
            <div class="lm-home-reviews-actions">
                @if ($reviewsWidgetCtaLabel !== '' && $reviewsWidgetCtaUrl !== '')
                    <a href="{{ $reviewsWidgetCtaUrl }}" class="lm-home-reviews-all">{{ $reviewsWidgetCtaLabel }} <x-fa-icon name="arrow-right" /></a>
                @endif
                @if ($reviewsWidgetCount > 1)
                    <div class="splide__arrows">
                        <button class="splide__arrow splide__arrow--prev" type="button" aria-label="Prethodne recenzije" disabled><x-fa-icon name="chevron-right" /></button>
                        <button class="splide__arrow splide__arrow--next" type="button" aria-label="Sljedeće recenzije" disabled><x-fa-icon name="chevron-right" /></button>
                    </div>
                @endif
            </div>
        </header>
        <div class="splide__track"><ul class="splide__list lm-home-reviews-grid">
            @foreach ($reviewsWidgetRows as $featuredReview)
                <li class="splide__slide">@include('front.partials.libermedia-review-card', ['featuredReview' => $featuredReview])</li>
            @endforeach
        </ul></div>
    </section>
    @include('front.partials.herrera-home-carousel-script')
@endif
