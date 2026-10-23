@php
    $blogWidgetSettings = \App\Support\WatchlineWidgetSettings::forBlock($block, $translation);
    $blogWidgetPayload = array_merge((array) ($block->payload ?? []), (array) ($translation?->payload ?? []));
    $blogWidgetLimit = max(1, min(50, (int) ($blogWidgetPayload['items_limit'] ?? 5)));
    $blogWidgetLocale = (string) app()->getLocale();
    $blogWidgetFallbackLocale = (string) config('app.fallback_locale', 'hr');
    $blogWidgetLocales = array_values(array_unique([$blogWidgetLocale, $blogWidgetFallbackLocale]));
    $blogWidgetTitle = trim((string) ($translation?->title ?: $block->name));
    $blogWidgetSubtitle = trim((string) ($translation?->subtitle ?? ''));
    $blogWidgetCtaLabel = trim((string) ($translation?->cta_label ?? ''));
    $blogWidgetCtaUrl = trim((string) ($blogWidgetSettings['cta_url'] ?? ''));
    $blogWidgetEyebrow = trim((string) ($blogWidgetSettings['eyebrow'] ?? ''));
    $blogWidgetHeadingId = 'watchline-blog-title-'.$block->id;
    $blogWidgetItems = $blockItems ?? $block->items;
    $blogWidgetSelectedIds = collect($blogWidgetItems)->where('item_type', 'blog')->pluck('item_id')->map(fn ($id) => (int) $id)->unique()->values();
    if ($blogWidgetSelectedIds->isEmpty() && ($blogs ?? collect())->isNotEmpty()) {
        $blogWidgetSelectedIds = $blogs->pluck('id')->map(fn ($id) => (int) $id)->unique()->values();
    }
    $blogWidgetQuery = \App\Models\Content\Blog\BlogPost::query()
        ->where('is_active', true)->where('published_at', '<=', now())
        ->whereHas('translations', fn ($query) => $query->whereIn('locale', $blogWidgetLocales)->whereRaw("TRIM(COALESCE(slug, '')) <> ''"))
        ->with(['translations' => fn ($query) => $query->whereIn('locale', $blogWidgetLocales)->whereRaw("TRIM(COALESCE(slug, '')) <> ''"), 'media']);
    if ($blogWidgetSelectedIds->isNotEmpty()) {
        $blogWidgetPosts = $blogWidgetQuery->whereIn('id', $blogWidgetSelectedIds)->get()
            ->sortBy(fn ($post) => $blogWidgetSelectedIds->search((int) $post->id))->take($blogWidgetLimit)->values();
    } else {
        $blogWidgetPosts = $blogWidgetQuery
            ->when(($blogWidgetPayload['blog_source'] ?? 'latest') === 'featured', fn ($query) => $query->where('is_featured', true))
            ->orderByDesc('published_at')->orderByDesc('id')->limit($blogWidgetLimit)->get();
    }
    $blogWidgetCount = $blogWidgetPosts->count();
    $blogWidgetOptions = [
        'type' => 'slide', 'perPage' => 3, 'perMove' => 1, 'gap' => '24px',
        'arrows' => $blogWidgetCount > 3, 'destroy' => $blogWidgetCount <= 3,
        'pagination' => false, 'drag' => $blogWidgetCount > 1, 'speed' => 420,
        'reducedMotion' => ['speed' => 0, 'rewindSpeed' => 0],
        'i18n' => [
            'prev' => 'Prethodni članci', 'next' => 'Sljedeći članci', 'first' => 'Prvi članak', 'last' => 'Posljednji članak',
            'carousel' => 'klizač članaka', 'slide' => 'članak', 'slideX' => 'Idi na članak %s', 'slideLabel' => '%s od %s',
        ],
        'breakpoints' => [
            1023 => ['perPage' => 2, 'gap' => '16px', 'destroy' => $blogWidgetCount <= 2, 'arrows' => $blogWidgetCount > 2],
            767 => ['perPage' => 1, 'gap' => '16px', 'destroy' => $blogWidgetCount <= 1, 'arrows' => $blogWidgetCount > 1],
        ],
    ];
@endphp

@if ($blogWidgetPosts->isNotEmpty())
    @include('front.partials.splide-assets')
    @once('herrera-home-b2b-styles')
        @push('styles')
            <link rel="stylesheet" href="{{ asset('front-theme/styles/herrera-home-b2b.css') }}?v={{ filemtime(public_path('front-theme/styles/herrera-home-b2b.css')) }}">
        @endpush
    @endonce
    @once('libermedia-home-blog-styles')
        @push('styles')
            <link rel="stylesheet" href="{{ asset('front-theme/styles/herrera-home-blog.css') }}?v={{ filemtime(public_path('front-theme/styles/herrera-home-blog.css')) }}">
            <link rel="stylesheet" href="{{ asset('front-theme/styles/libermedia-home-blog.css') }}?v={{ filemtime(public_path('front-theme/styles/libermedia-home-blog.css')) }}">
        @endpush
    @endonce
    <section class="splide herrera-home-section herrera-b2b-products herrera-home-blog-widget libermedia-home-blog libermedia-home-blog-carousel" aria-labelledby="{{ $blogWidgetHeadingId }}" data-watchline-blog-widget="{{ $block->id }}" data-libermedia-home-blog data-libermedia-home-blog-carousel data-herrera-home-carousel data-post-count="{{ $blogWidgetCount }}" data-splide='@json($blogWidgetOptions)'>
        <div class="herrera-home-heading">
            <div>
                @if ($blogWidgetEyebrow !== '')<p class="herrera-home-section-eyebrow">{{ $blogWidgetEyebrow }}</p>@endif
                <h2 id="{{ $blogWidgetHeadingId }}">{{ $blogWidgetTitle }}</h2>
                @if ($blogWidgetSubtitle !== '')<p class="herrera-home-section-subtitle">{{ $blogWidgetSubtitle }}</p>@endif
            </div>
            <div class="libermedia-home-blog-actions">
                @if ($blogWidgetCtaLabel !== '' && $blogWidgetCtaUrl !== '')
                    <a class="herrera-home-products-link" href="{{ $blogWidgetCtaUrl }}">{{ $blogWidgetCtaLabel }} <x-fa-icon name="arrow-right" /></a>
                @endif
                @if ($blogWidgetCount > 1)
                    <div class="splide__arrows">
                        <button class="splide__arrow splide__arrow--prev" type="button" aria-label="Prethodni članci" disabled><x-fa-icon name="chevron-right" /></button>
                        <button class="splide__arrow splide__arrow--next" type="button" aria-label="Sljedeći članci" disabled><x-fa-icon name="chevron-right" /></button>
                    </div>
                @endif
            </div>
        </div>
        <div class="splide__track"><ul class="splide__list libermedia-home-blog-grid">
            @foreach ($blogWidgetPosts as $blogWidgetPost)
                @php($blogWidgetPostTranslation = $blogWidgetPost->translations->firstWhere('locale', $blogWidgetLocale) ?? $blogWidgetPost->translations->firstWhere('locale', $blogWidgetFallbackLocale))
                <li class="splide__slide" data-watchline-blog-id="{{ $blogWidgetPost->id }}">
                    @include('front.partials.herrera-blog-card', ['blogCardPost' => $blogWidgetPost, 'blogCardLocale' => $blogWidgetLocale, 'blogCardFallbackLocale' => $blogWidgetFallbackLocale])
                    <a class="libermedia-home-blog-read" href="{{ route('blog.show', ['slug' => $blogWidgetPostTranslation->slug]) }}">{{ __('Pročitaj članak') }} <x-fa-icon name="arrow-right" /></a>
                </li>
            @endforeach
        </ul></div>
    </section>
    @include('front.partials.herrera-home-carousel-script')
@endif
