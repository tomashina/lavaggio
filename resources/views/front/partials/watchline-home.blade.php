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

@if (in_array($herreraHomeSection, ['all', 'products'], true))
    @php
        $herreraProducts = $watchlineCluseProducts ?? collect();
        $watchlineCluseTranslation = $herreraProducts->first()?->manufacturer?->translations->firstWhere('locale', $herreraLocale)
            ?? $herreraProducts->first()?->manufacturer?->translations->firstWhere('locale', $herreraFallbackLocale);
        $watchlineCluseBrowseUrl = $watchlineCluseTranslation?->slug ? route('manufacturers.show', ['slug' => $watchlineCluseTranslation->slug]) : route('shop.index');
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
        <section class="herrera-home-section herrera-b2b-products" aria-labelledby="herrera-home-products-title" data-herrera-home-products data-watchline-cluse-products>
            @include('front.partials.splide-assets')
            <div class="splide herrera-home-product-carousel wl-product-carousel" data-herrera-home-products-splide data-herrera-home-carousel data-continuous-card-carousel data-splide='@json($herreraProductCarouselOptions)' aria-labelledby="herrera-home-products-title">
                <div class="herrera-home-heading">
                    <div>
                        <p class="herrera-home-section-eyebrow">{{ __('ŽENSKI SATOVI') }}</p>
                        <h2 id="herrera-home-products-title">{{ __('CLUSE, po izboru naših kupaca') }}</h2>
                    </div>
                    <div class="herrera-home-product-actions">
                        <a class="herrera-home-products-link" href="{{ $watchlineCluseBrowseUrl }}">{{ __('Svi CLUSE satovi') }} <x-fa-icon name="arrow-right" /></a>
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

    @include('front.partials.watchline-guess-carousel')
@endif

@if (in_array($herreraHomeSection, ['all', 'brands'], true))
    @include('front.partials.herrera-brand-strip', ['brandStripTitle' => __('Brandovi iz naše ponude'), 'brandStripVisibleCount' => 6])
@endif
