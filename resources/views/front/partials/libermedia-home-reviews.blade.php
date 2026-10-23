@if (($featuredReviews ?? collect())->isNotEmpty())
    @php
        $reviewCount = $featuredReviews->count();
        $reviewCarouselOptions = [
            'type' => 'slide',
            'perPage' => 3,
            'perMove' => 1,
            'gap' => '24px',
            'arrows' => $reviewCount > 3,
            'destroy' => $reviewCount <= 3,
            'pagination' => false,
            'drag' => $reviewCount > 1,
            'speed' => 420,
            'reducedMotion' => ['speed' => 0, 'rewindSpeed' => 0],
            'i18n' => [
                'prev' => 'Prethodne recenzije', 'next' => 'Sljedeće recenzije',
                'first' => 'Prva recenzija', 'last' => 'Posljednja recenzija',
                'carousel' => 'klizač recenzija', 'slide' => 'recenzija',
                'slideX' => 'Idi na recenziju %s', 'slideLabel' => '%s od %s',
            ],
            'breakpoints' => [
                1023 => ['perPage' => 2, 'gap' => '16px', 'destroy' => $reviewCount <= 2, 'arrows' => $reviewCount > 2],
                767 => ['perPage' => 1, 'gap' => '16px', 'destroy' => $reviewCount <= 1, 'arrows' => $reviewCount > 1],
            ],
        ];
    @endphp
    @include('front.partials.splide-assets')
    @once('libermedia-featured-reviews-styles')
        @push('styles')
            <link rel="stylesheet" href="{{ asset('front-theme/styles/libermedia-featured-reviews.css') }}?v={{ filemtime(public_path('front-theme/styles/libermedia-featured-reviews.css')) }}">
        @endpush
    @endonce

    <section class="splide lm-home-reviews" aria-labelledby="lm-home-reviews-title" data-libermedia-home-reviews data-libermedia-home-reviews-carousel data-herrera-home-carousel data-splide='@json($reviewCarouselOptions)'>
        <header class="lm-home-reviews-heading">
            <div>
                <p class="lm-home-reviews-eyebrow">{{ __('ISKUSTVA KUPACA') }}</p>
                <h2 id="lm-home-reviews-title">{{ __('Što kažu naši kupci') }}</h2>
            </div>
            @if (config('storefront.home') !== 'watchline')
                <p class="lm-home-reviews-caption">{{ __('Iskustva iz prve ruke, za lakši odabir igre i učenja.') }}</p>
            @endif
            <div class="lm-home-reviews-actions">
                <a href="{{ route('reviews.index') }}" class="lm-home-reviews-all">Sva iskustva kupaca <x-fa-icon name="arrow-right" /></a>
                @if ($reviewCount > 1)
                    <div class="splide__arrows">
                        <button class="splide__arrow splide__arrow--prev" type="button" aria-label="Prethodne recenzije" disabled><x-fa-icon name="chevron-right" /></button>
                        <button class="splide__arrow splide__arrow--next" type="button" aria-label="Sljedeće recenzije" disabled><x-fa-icon name="chevron-right" /></button>
                    </div>
                @endif
            </div>
        </header>

        <div class="splide__track">
        <ul class="splide__list lm-home-reviews-grid">
            @foreach ($featuredReviews as $featuredReview)
                <li class="splide__slide">
                    @include('front.partials.libermedia-review-card', ['featuredReview' => $featuredReview])
                </li>
            @endforeach
        </ul>
        </div>
    </section>
    @include('front.partials.herrera-home-carousel-script')
@endif
