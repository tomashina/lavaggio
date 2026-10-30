@extends('front.desktop.layouts.store')

@section('title', str_contains(strtolower((string) ($storeSettings['branding']['store_name'] ?? config('app.name'))), 'herrera') ? __('herrera.page_title') : (string) ($storeSettings['branding']['store_name'] ?? config('app.name', 'AG Shop')))
@section('main_class', 'mx-auto w-full max-w-7xl px-6 pt-0 pb-0'.(\App\Support\StorefrontTheme::usesHerreraLayout((string) ($storeSettings['branding']['store_name'] ?? config('app.name'))) ? ' herrera-home-main' : ''))

@section('content')
    @php
        $resolver = app(\App\Services\Content\ContentBlockResolver::class);
        $locale = app()->getLocale();

        $homeHeroBlocks = $resolver->forPlacement('home.hero', $locale, null, null, 'desktop');
        $homeHeroBenefitsBlocks = $resolver->forPlacement('home.hero_benefits', $locale, null, null, 'desktop');
        $homeBeforeProductsBlocks = $resolver->forPlacement('home.before_products', $locale, null, null, 'desktop');
        $homeCategoriesBlocks = $resolver->forPlacement('home.categories', $locale, null, null, 'desktop');
        $homeAfterProductsBlocks = $resolver->forPlacement('home.after_products', $locale, null, null, 'desktop');
        $homeBottomBlocks = $resolver->forPlacement('home.bottom', $locale, null, null, 'desktop');
        $homeBottomIsInstagramOnly = $homeBottomBlocks->isNotEmpty()
            && $homeBottomBlocks->every(fn ($item) => (string) data_get($item, 'block.type') === 'instagram_curated_grid');
        $isHerreraHome = \App\Support\StorefrontTheme::usesHerreraLayout((string) ($storeSettings['branding']['store_name'] ?? config('app.name')));
        $isLibermediaHome = in_array(config('storefront.home'), ['libermedia', 'watchline'], true);
        $isWatchlineHome = config('storefront.home') === 'watchline';
        $homeContentBlocks = ($isWatchlineHome || config('storefront.home') === 'lavaggio')
            ? $resolver->forPlacement('home.content', $locale, null, null, 'desktop')
            : collect();
        $homeFallbackView = match (config('storefront.home')) {
            'libermedia' => 'front.partials.libermedia-home',
            'watchline' => 'front.partials.watchline-home',
            default => 'front.partials.herrera-home',
        };
        $hasManagedHerreraHomeCategories = $isHerreraHome
            && \Illuminate\Support\Facades\Schema::hasTable('content_block_slots')
            && \Illuminate\Support\Facades\Schema::hasTable('content_blocks')
            && \App\Models\Content\ContentBlockSlot::query()
                ->where('placement', 'home.categories')
                ->whereHas('block', fn ($query) => $query->where('type', 'featured_categories'))
                ->exists();

        $viewer = auth()->user();
        $canPreviewBlock = $viewer && ($viewer->isA('superadmin') || $viewer->can('content.blocks'));
        $previewBlockId = $canPreviewBlock ? (int) request()->query('preview_block', 0) : 0;
        $requestedPreviewPlacement = $canPreviewBlock ? (string) request()->query('preview_placement', '') : '';

        if ($previewBlockId > 0) {
            $previewBlock = \App\Models\Content\ContentBlock::query()
                ->with([
                    'translations' => fn ($q) => $q->whereIn('locale', [$locale, config('app.locale')]),
                    'slots' => fn ($q) => $q->orderBy('sort_order')->orderBy('id'),
                ])
                ->find($previewBlockId);

            if ($previewBlock) {
                $previewPlacement = $requestedPreviewPlacement !== ''
                    ? $requestedPreviewPlacement
                    : (string) ($previewBlock->slots->first()?->placement ?? 'home.hero');

                $previewTranslation = $previewBlock->translations->firstWhere('locale', $locale)
                    ?? $previewBlock->translations->firstWhere('locale', config('app.locale'));

                $previewSlot = $previewBlock->slots->firstWhere('placement', $previewPlacement)
                    ?? new \App\Models\Content\ContentBlockSlot(['placement' => $previewPlacement]);

                $previewItem = collect([[
                    'slot' => $previewSlot,
                    'block' => $previewBlock,
                    'translation' => $previewTranslation,
                ]]);

                if ($previewPlacement === 'home.content') {
                    $homeContentBlocks = $previewItem;
                } elseif ($previewPlacement === 'home.hero') {
                    $homeHeroBlocks = $previewItem;
                } elseif ($previewPlacement === 'home.hero_benefits') {
                    $homeHeroBenefitsBlocks = $previewItem;
                } elseif ($previewPlacement === 'home.before_products') {
                    $homeBeforeProductsBlocks = $previewItem;
                } elseif ($previewPlacement === 'home.categories') {
                    $homeCategoriesBlocks = $previewItem;
                } elseif ($previewPlacement === 'home.after_products') {
                    $homeAfterProductsBlocks = $previewItem;
                } elseif ($previewPlacement === 'home.bottom') {
                    $homeBottomBlocks = $previewItem;
                    $homeBottomIsInstagramOnly = (string) $previewBlock->type === 'instagram_curated_grid';
                }
            }
        }

        $useHerreraHomeFallback = $isHerreraHome && ($isWatchlineHome || $homeHeroBlocks->isEmpty());
        $visibleHomePlacements = $isWatchlineHome
            ? [$homeBeforeProductsBlocks, $homeAfterProductsBlocks, $homeBottomBlocks]
            : [$homeHeroBlocks, $homeHeroBenefitsBlocks, $homeCategoriesBlocks, $homeBeforeProductsBlocks, $homeAfterProductsBlocks, $homeBottomBlocks];
        $hasHomeBrandsBlock = collect($visibleHomePlacements)
            ->flatten(1)
            ->contains(fn ($item) => (string) data_get($item, 'block.type') === 'popular_brands');
    @endphp

    @if (config('storefront.home') === 'lavaggio')
        @include('front.partials.lavaggio-home')
    @elseif ($isWatchlineHome)
        <section class="wl-home-opening" data-watchline-home-widgets>
            @include('front.partials.herrera-home-placement', ['items' => $homeContentBlocks])
        </section>
    @else

    @if (! $isWatchlineHome && $homeHeroBlocks->isNotEmpty())
        <section class="-mt-px" data-herrera-home-hero-placement>
            @include('front.partials.herrera-home-placement', ['items' => $homeHeroBlocks])
        </section>
    @elseif (! $isWatchlineHome && $useHerreraHomeFallback)
        @include($homeFallbackView, ['herreraHomeSection' => 'hero'])
    @endif

    @if ($homeHeroBenefitsBlocks->isNotEmpty())
        @if ($isLibermediaHome)
            @section('home-footer-benefits')
                <div class="lm-footer-benefits">
                    @include('front.partials.herrera-home-placement', ['items' => $homeHeroBenefitsBlocks])
                </div>
            @endsection
        @else
            <section class="mt-8">
                @include('front.partials.herrera-home-placement', ['items' => $homeHeroBenefitsBlocks])
            </section>
        @endif
    @endif

    @if (! $isWatchlineHome && $isHerreraHome && $homeCategoriesBlocks->isNotEmpty() && $homeCategoriesBlocks->every(fn ($item) => (string) data_get($item, 'block.type') === 'featured_categories'))
        @include('front.partials.herrera-home-placement', ['items' => $homeCategoriesBlocks])
    @elseif (! $isWatchlineHome && $homeCategoriesBlocks->isNotEmpty())
        <section class="mt-8">
            @include('front.partials.herrera-home-placement', ['items' => $homeCategoriesBlocks])
        </section>
    @elseif (! $isWatchlineHome && $useHerreraHomeFallback && ! $hasManagedHerreraHomeCategories)
        @include($homeFallbackView, ['herreraHomeSection' => 'categories'])
    @endif

    @if ($homeBeforeProductsBlocks->isNotEmpty())
        <section class="{{ $isWatchlineHome ? 'wl-home-opening' : 'mt-8' }}">
            @include('front.partials.herrera-home-placement', ['items' => $homeBeforeProductsBlocks])
        </section>
    @endif

    @if ($useHerreraHomeFallback)
        @include($homeFallbackView, ['herreraHomeSection' => 'products'])
    @endif

    @if ($homeAfterProductsBlocks->isNotEmpty())
        <section class="mt-8">
            @include('front.partials.herrera-home-placement', ['items' => $homeAfterProductsBlocks])
        </section>
    @endif

    @if ($useHerreraHomeFallback && ! $hasHomeBrandsBlock)
        @include($homeFallbackView, ['herreraHomeSection' => 'brands'])
        @unless ($isLibermediaHome || $isWatchlineHome)
            @include('front.partials.herrera-popular-products')
        @endunless
    @endif

    @if ($isHerreraHome && ! $isLibermediaHome && ! $isWatchlineHome && ! $hasHomeBrandsBlock)
        @include('front.partials.herrera-popular-products')
    @endif

    @if ($homeBottomBlocks->isNotEmpty())
        <section class="{{ $homeBottomIsInstagramOnly ? 'mt-0' : 'mt-8' }}">
            @include('front.partials.herrera-home-placement', ['items' => $homeBottomBlocks])
        </section>
    @endif

    @if ($isLibermediaHome)
        @include('front.partials.libermedia-home-reviews')
        @include($isWatchlineHome ? 'front.partials.watchline-home-blog' : 'front.partials.libermedia-home-blog')
    @endif

    @if (
        $homeHeroBlocks->isEmpty()
        && $homeHeroBenefitsBlocks->isEmpty()
        && $homeBeforeProductsBlocks->isEmpty()
        && $homeCategoriesBlocks->isEmpty()
        && $homeAfterProductsBlocks->isEmpty()
        && $homeBottomBlocks->isEmpty()
        && ! $isHerreraHome
    )
        <section class="border border-slate-200 bg-white p-10">
            <h1 class="text-3xl font-extrabold tracking-tight text-slate-900">Home</h1>
            <p class="mt-3 text-slate-600">Nema aktivnih content blokova za desktop home.</p>
        </section>
    @endif
    @endif
@endsection
