@php
    $settings = \App\Support\WatchlineWidgetSettings::forBlock($block, $translation);
    $widgetProducts = app(\App\Services\Content\WatchlineWidgetProducts::class)->forBlock($block, $translation, $products);
    $widgetCount = $widgetProducts->count();
    $headingId = 'watchline-products-'.$block->id;
    $previousLabel = __('pagination.previous');
    $nextLabel = __('pagination.next');
    $options = [
        'type' => $widgetCount > 1 ? 'loop' : 'slide', 'rewind' => false,
        'perPage' => 6, 'perMove' => 1, 'gap' => '0rem', 'pagination' => false,
        'destroy' => $widgetCount <= 6, 'arrows' => $widgetCount > 6,
        'drag' => $widgetCount > 1, 'snap' => true, 'updateOnMove' => true, 'speed' => 420,
        'i18n' => ['prev' => $previousLabel, 'next' => $nextLabel, 'first' => $nextLabel, 'last' => $previousLabel],
        'breakpoints' => [
            1279 => ['perPage' => 4, 'destroy' => $widgetCount <= 4, 'arrows' => $widgetCount > 4],
            767 => ['perPage' => 2, 'destroy' => $widgetCount <= 2, 'arrows' => $widgetCount > 2],
        ],
    ];
@endphp
@if ($widgetProducts->isNotEmpty())
    @include('front.partials.splide-assets')
    @once('herrera-home-b2b-styles')
        @push('styles')
            <link rel="stylesheet" href="{{ asset('front-theme/styles/herrera-home-b2b.css') }}?v={{ filemtime(public_path('front-theme/styles/herrera-home-b2b.css')) }}">
        @endpush
    @endonce
    <section class="herrera-home-section herrera-b2b-products" aria-labelledby="{{ $headingId }}"
        @if ($block->code === 'watchline-home-cluse') data-watchline-cluse-products data-herrera-home-products
        @elseif ($block->code === 'watchline-home-guess') data-watchline-guess-products
        @elseif ($block->code === 'watchline-home-promotions') data-libermedia-promotions
        @else data-libermedia-creative-products @endif>
        <div class="splide herrera-home-product-carousel wl-product-carousel" data-herrera-home-carousel data-continuous-card-carousel data-splide='@json($options)' aria-labelledby="{{ $headingId }}">
            <div class="herrera-home-heading">
                <div>
                    @if (filled($settings['eyebrow'] ?? ''))<p class="herrera-home-section-eyebrow">{{ $settings['eyebrow'] }}</p>@endif
                    <h2 id="{{ $headingId }}">{{ $translation?->title ?? $block->name }}</h2>
                    @if (filled($translation?->subtitle))<p class="wl-widget-subtitle">{{ $translation->subtitle }}</p>@endif
                </div>
                <div class="herrera-home-product-actions">
                    @if ($translation?->cta_label && $translation?->cta_url)
                        <a class="herrera-home-products-link" href="{{ $translation->cta_url }}">{{ $translation->cta_label }} <x-fa-icon name="arrow-right" /></a>
                    @endif
                    @if ($widgetCount > 2)
                        <div class="splide__arrows">
                            <button class="splide__arrow splide__arrow--prev" type="button" aria-label="{{ $previousLabel }}" disabled><x-fa-icon name="chevron-right" style="solid" /></button>
                            <button class="splide__arrow splide__arrow--next" type="button" aria-label="{{ $nextLabel }}" disabled><x-fa-icon name="chevron-right" style="solid" /></button>
                        </div>
                    @endif
                </div>
            </div>
            <div class="splide__track"><div class="splide__list">
                @foreach ($widgetProducts as $widgetProduct)
                    <div class="splide__slide"><x-front.desktop.product-card :product="$widgetProduct" :locale="$locale" :fallback-locale="$fallbackLocale" :lined="true" :flat="true" /></div>
                @endforeach
            </div></div>
        </div>
    </section>
    @include('front.partials.herrera-home-carousel-script')
@endif
