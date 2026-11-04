@once('lavaggio-home-hero-styles')
    @push('styles')
        <link rel="stylesheet" href="{{ asset('front-theme/styles/lavaggio-home-hero.css') }}?v={{ filemtime(public_path('front-theme/styles/lavaggio-home-hero.css')) }}">
    @endpush
@endonce
@php
    $settings = \App\Support\WatchlineWidgetSettings::forBlock($block, $translation);
    $slides = \App\Support\LavaggioCampaignSlides::visible($settings);
    $hasMultipleSlides = count($slides) > 1;
    $promoId = 'lavaggio-promo-'.$block->id;
    $promoOptions = ['type' => 'slide', 'rewind' => true, 'perPage' => 1, 'perMove' => 1,
        'arrows' => $hasMultipleSlides, 'pagination' => $hasMultipleSlides, 'autoplay' => false,
        'speed' => 450, 'gap' => '20px', 'i18n' => ['prev' => 'Prethodna promocija', 'next' => 'Sljedeća promocija',
            'slideX' => 'Promocija %s', 'pageX' => 'Prikaži promociju %s', 'slideLabel' => '%s od %s']];
@endphp
@if ($slides !== [])
    @if ($hasMultipleSlides) @include('front.partials.splide-assets') @endif
    <section id="{{ $promoId }}" class="lavaggio-promo{{ $hasMultipleSlides ? ' splide' : '' }}" aria-label="Lavaggio promocije"
        data-lavaggio-promotions @if ($hasMultipleSlides) data-herrera-home-carousel data-splide='@json($promoOptions)' @endif>
        <div @class(['splide__track' => $hasMultipleSlides])>
            <div @class(['splide__list' => $hasMultipleSlides])>
                @foreach ($slides as $slide)
                    <article @class(['lavaggio-promo-slide', 'splide__slide' => $hasMultipleSlides,
                        'lavaggio-promo-slide--image' => $slide['display'] === 'image', 'lavaggio-promo-slide--no-image' => $slide['image'] === ''])>
                        @if ($slide['display'] === 'image')
                            @if ($loop->first)<h1 class="sr-only">{{ $slide['title'] ?: 'Lavaggio webshop' }}</h1>@endif
                            @if ($slide['shipping_notice'])
                                <div class="lavaggio-promo-shipping-overlay">@include('front.partials.lavaggio-promo-shipping-notice')</div>
                            @endif
                            @if ($slide['url'] !== '')<a class="lavaggio-promo-photo-link" href="{{ \App\Support\Lavaggio\PublicUrls::url($slide['url']) }}" aria-label="{{ $slide['cta_label'] ?: ($slide['title'] ?: 'Pogledajte promociju') }}">@endif
                                <img src="{{ $slide['image'] }}" alt="{{ $slide['image_alt'] }}" width="1600" height="700" @if($loop->first) fetchpriority="high" @else loading="lazy" @endif decoding="async">
                            @if ($slide['url'] !== '')</a>@endif
                        @else
                            <div class="lavaggio-promo-copy">
                                @if(filled($slide['eyebrow']))<p class="lavaggio-widget-eyebrow">{{ $slide['eyebrow'] }}</p>@endif
                                @if ($loop->first)<h1>{{ $slide['title'] }}</h1>@else<h2>{{ $slide['title'] }}</h2>@endif
                                @if(filled($slide['subtitle']))<p class="lavaggio-widget-description">{{ $slide['subtitle'] }}</p>@endif
                                @if($slide['shipping_notice'])
                                    @include('front.partials.lavaggio-promo-shipping-notice')
                                @endif
                                @if(filled($slide['cta_label']) && filled($slide['url']))<a class="lavaggio-widget-button" href="{{ \App\Support\Lavaggio\PublicUrls::url($slide['url']) }}">{{ $slide['cta_label'] }} <x-fa-icon name="arrow-right" /></a>@endif
                            </div>
                            @if(filled($slide['image']))
                                <div class="lavaggio-promo-image"><img class="lavaggio-widget-hero__product lavaggio-widget-hero__product--main" src="{{ $slide['image'] }}" alt="{{ $slide['image_alt'] }}" width="1000" height="930" @if($loop->first) fetchpriority="high" @else loading="lazy" @endif decoding="async"></div>
                            @endif
                        @endif
                    </article>
                @endforeach
            </div>
        </div>
        @if ($hasMultipleSlides)
            <div class="splide__arrows lavaggio-promo-arrows">
                <button type="button" class="splide__arrow splide__arrow--prev" aria-label="Prethodna promocija"><x-fa-icon name="chevron-right" /></button>
                <button type="button" class="splide__arrow splide__arrow--next" aria-label="Sljedeća promocija"><x-fa-icon name="chevron-right" /></button>
            </div>
        @endif
    </section>
    @if ($hasMultipleSlides) @include('front.partials.herrera-home-carousel-script') @endif
@endif
