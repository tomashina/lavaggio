@once('herrera-home-b2b-styles')
    @push('styles')
        <link rel="stylesheet" href="{{ asset('front-theme/styles/herrera-home-b2b.css') }}?v={{ filemtime(public_path('front-theme/styles/herrera-home-b2b.css')) }}">
    @endpush
@endonce

@php
    $herreraHomeSection = $herreraHomeSection ?? 'all';
    $herreraLocale = (string) app()->getLocale();
    $herreraFallbackLocale = (string) config('app.locale');
@endphp

@if (in_array($herreraHomeSection, ['all', 'hero'], true))
    <section class="herrera-hero herrera-b2b-hero" aria-labelledby="libermedia-home-title" data-libermedia-home-hero>
        <div class="herrera-hero-copy">
            <p class="herrera-eyebrow">{{ __('WATCHLINE · SATOVI I NAKIT.') }}</p>
            <h1 id="libermedia-home-title" class="sr-only">{{ __('Satovi i nakit - Watchline') }}</h1>
            <h2 class="libermedia-hero-headline"><span class="herrera-hero-headline-line">{{ __('Vrijeme za stil.') }} </span><span class="herrera-hero-headline-line">{{ __('Za svaki trenutak.') }}</span></h2>
            <p class="herrera-hero-description">{{ __('Istražite našu ponudu satova, nakita i dodatne opreme.') }}</p>
            <div class="herrera-hero-actions">
                <a class="herrera-hero-primary-action" href="{{ route('categories.index') }}">{{ __('Istražite ponudu') }} <x-fa-icon name="arrow-right" /></a>
                <a class="herrera-hero-secondary-action" href="{{ route('shop.promotions') }}"><x-fa-icon name="tag" style="light" /> {{ __('Akcije i popusti') }}</a>
            </div>
        </div>
        <aside class="herrera-hero-panel" aria-labelledby="libermedia-home-tools-title">
            <h2 id="libermedia-home-tools-title">{{ __('Pronađite svoj novi omiljeni detalj.') }}</h2>
            <nav class="herrera-b2b-tools" aria-label="{{ __('Istražite Watchline') }}">
                <a href="{{ route('categories.index') }}"><x-fa-icon name="shapes" /><span>{{ __('Istražite sve kategorije') }}</span><x-fa-icon name="arrow-right" /></a>
                <a href="{{ route('manufacturers.index') }}"><x-fa-icon name="gift" /><span>{{ __('Pronađite svoj brand') }}</span><x-fa-icon name="arrow-right" /></a>
                <a href="{{ route('contact.create') }}"><x-fa-icon name="ticket" /><span>{{ __('Kontakt i savjeti') }}</span><x-fa-icon name="arrow-right" /></a>
            </nav>
            <p class="herrera-b2b-tools-note">{{ __('Watchline · Satovi i nakit.') }}</p>
        </aside>
    </section>
@endif

@if (in_array($herreraHomeSection, ['all', 'categories'], true))
    @include('front.partials.herrera-home-categories', ['title' => __('Istraži kategorije'), 'showCategorySupport' => false])
@endif

@if (in_array($herreraHomeSection, ['all', 'products'], true))
    @php
        // Use only this shop's active products, with a selection across brands.
        $herreraCuratedCodes = [];
        $herreraProductQuery = fn () => \App\Models\Catalog\Product\Product::query()->where('is_active', true)->where('stock_qty', '>', 0);
        $herreraProductIds = $herreraProductQuery()->whereIn('code', $herreraCuratedCodes)->pluck('id', 'code');
        $herreraSelectedProductIds = collect($herreraCuratedCodes)->map(fn ($code) => $herreraProductIds->get($code))->filter()->values();

        // Keep the selection varied if a curated product has been removed or deactivated.
        if ($herreraSelectedProductIds->count() < 8) {
            $herreraSelectedManufacturerIds = $herreraProductQuery()->whereIn('id', $herreraSelectedProductIds)->whereNotNull('manufacturer_id')->pluck('manufacturer_id');
            $herreraRepresentativeIds = $herreraProductQuery()
                ->whereNotIn('id', $herreraSelectedProductIds)
                ->where(fn ($query) => $query->whereNull('manufacturer_id')->orWhereNotIn('manufacturer_id', $herreraSelectedManufacturerIds))
                ->selectRaw('MIN(id) as id')
                ->groupBy('manufacturer_id')
                ->orderBy('id')
                ->limit(8 - $herreraSelectedProductIds->count())
                ->pluck('id');
            $herreraSelectedProductIds = $herreraSelectedProductIds->concat($herreraRepresentativeIds)->values();
        }

        if ($herreraSelectedProductIds->count() < 8) {
            $herreraSelectedProductIds = $herreraSelectedProductIds->concat(
                $herreraProductQuery()->whereNotIn('id', $herreraSelectedProductIds)->orderBy('id')->limit(8 - $herreraSelectedProductIds->count())->pluck('id')
            )->values();
        }

        $herreraProductsById = $herreraProductQuery()
            ->whereIn('id', $herreraSelectedProductIds)
            ->withStorefrontEnergyData()
            ->withApprovedCommentSummary([$herreraLocale, $herreraFallbackLocale])
            ->with([
                'translations', 'media', 'taxRate', 'manufacturer.translations', 'categories.translations',
                'attributes' => \App\Support\ProductMaterialLabel::eagerLoadAttributes($herreraLocale, $herreraFallbackLocale),
                'optionValues' => fn ($q) => $q
                    ->where('is_active', true)
                    ->orderBy('sort_order')
                    ->orderBy('id')
                    ->with([
                        'optionValue.option:id,payload',
                        'optionValue.translations' => fn ($q) => $q->whereIn('locale', [$herreraLocale, $herreraFallbackLocale]),
                        'parentOptionValue.option:id,payload',
                        'parentOptionValue.translations' => fn ($q) => $q->whereIn('locale', [$herreraLocale, $herreraFallbackLocale]),
                    ]),
            ])
            ->get()->keyBy('id');
        $herreraProducts = $herreraSelectedProductIds->map(fn ($id) => $herreraProductsById->get($id))->filter();
        $herreraProductCount = $herreraProducts->count();
        $herreraProductPreviousLabel = trim(str_replace(['&laquo;', '&raquo;'], '', __('pagination.previous')));
        $herreraProductNextLabel = trim(str_replace(['&laquo;', '&raquo;'], '', __('pagination.next')));
        $herreraProductCarouselOptions = [
            'type' => $herreraProductCount > 1 ? 'loop' : 'slide',
            'rewind' => false,
            'perPage' => 6,
            'destroy' => $herreraProductCount <= 6,
            'perMove' => 1,
            'gap' => '0rem',
            'drag' => $herreraProductCount > 1,
            'snap' => true,
            'pagination' => false,
            'arrows' => $herreraProductCount > 6,
            'updateOnMove' => true,
            'speed' => 420,
            'i18n' => ['prev' => $herreraProductPreviousLabel, 'next' => $herreraProductNextLabel, 'first' => $herreraProductNextLabel, 'last' => $herreraProductPreviousLabel],
            'breakpoints' => [
                1279 => ['perPage' => 4, 'destroy' => $herreraProductCount <= 4, 'arrows' => $herreraProductCount > 4],
                767 => ['perPage' => 2, 'destroy' => $herreraProductCount <= 2, 'arrows' => $herreraProductCount > 2],
            ],
        ];
    @endphp

    @if ($herreraProducts->isNotEmpty())
        <section class="herrera-home-section herrera-b2b-products" aria-labelledby="herrera-home-products-title" data-herrera-home-products>
            @include('front.partials.splide-assets')
            <div class="splide herrera-home-product-carousel" data-herrera-home-products-splide data-herrera-home-carousel data-continuous-card-carousel data-splide='@json($herreraProductCarouselOptions)' aria-labelledby="herrera-home-products-title" style="--herrera-product-columns: {{ $herreraProductCarouselOptions['perPage'] }}; --herrera-product-tablet-columns: {{ $herreraProductCarouselOptions['breakpoints'][1279]['perPage'] }}; --herrera-product-mobile-columns: {{ $herreraProductCarouselOptions['breakpoints'][767]['perPage'] }};">
                <div class="herrera-home-heading">
                    <div>
                        <p class="herrera-home-section-eyebrow">{{ __('IZ WATCHLINE PONUDE') }}</p>
                        <h2 id="herrera-home-products-title">{{ __('Izdvojeni proizvodi') }}</h2>
                    </div>
                    <div class="herrera-home-product-actions">
                        <a class="herrera-home-products-link" href="{{ route('categories.index') }}">{{ __('herrera.all_products') }} <x-fa-icon name="arrow-right" /></a>
                        @if ($herreraProductCount > 2)
                            <div class="splide__arrows">
                                <button class="splide__arrow splide__arrow--prev" type="button" aria-label="{{ $herreraProductPreviousLabel }}" disabled><x-fa-icon name="chevron-right" style="solid" /></button>
                                <button class="splide__arrow splide__arrow--next" type="button" aria-label="{{ $herreraProductNextLabel }}" disabled><x-fa-icon name="chevron-right" style="solid" /></button>
                            </div>
                        @endif
                    </div>
                </div>
                <div class="splide__track">
                    <div class="splide__list">
                        @foreach ($herreraProducts as $herreraProduct)
                            <div class="splide__slide">
                                <x-front.desktop.product-card :product="$herreraProduct" :locale="$herreraLocale" :fallback-locale="$herreraFallbackLocale" :lined="true" :flat="true" />
                            </div>
                        @endforeach
                    </div>
                </div>
            </div>
        </section>

        @include('front.partials.herrera-home-carousel-script')
    @endif
@endif

@if (in_array($herreraHomeSection, ['all', 'brands'], true))
    @include('front.partials.herrera-brand-strip', ['brandStripTitle' => __('Brandovi iz naše ponude'), 'brandStripVisibleCount' => 6])
@endif
