@if (($cartRecommendations['products'] ?? collect())->isNotEmpty())
    @php
        $recommendationCount = $cartRecommendations['products']->count();
        $recommendationCarouselOptions = [
            'type' => $recommendationCount > 1 ? 'loop' : 'slide',
            'perPage' => 6,
            'perMove' => 1,
            'gap' => '0rem',
            'destroy' => $recommendationCount <= 6,
            'arrows' => $recommendationCount > 6,
            'pagination' => false,
            'drag' => $recommendationCount > 1,
            'snap' => true,
            'updateOnMove' => true,
            'speed' => 420,
            'reducedMotion' => ['speed' => 0, 'rewindSpeed' => 0, 'autoplay' => 'pause'],
            'i18n' => [
                'prev' => 'Prethodni proizvodi', 'next' => 'Sljedeći proizvodi',
                'first' => 'Prijeđi na prvi proizvod', 'last' => 'Prijeđi na posljednji proizvod',
                'carousel' => 'Preporučeni proizvodi', 'slide' => 'Proizvod', 'slideLabel' => '%s od %s',
            ],
            'breakpoints' => [
                1279 => ['perPage' => 4, 'destroy' => $recommendationCount <= 4, 'arrows' => $recommendationCount > 4],
                767 => ['perPage' => 2, 'destroy' => $recommendationCount <= 2, 'arrows' => $recommendationCount > 2],
            ],
        ];
    @endphp
    @once('cart-recommendations-carousel-assets')
        @push('styles')
            <link rel="stylesheet" href="{{ asset('front-theme/styles/herrera-product-related.css') }}?v={{ filemtime(public_path('front-theme/styles/herrera-product-related.css')) }}">
        @endpush
    @endonce
    @include('front.partials.splide-assets')
    <section class="lm-cart-recommendations" aria-labelledby="cart-recommendations-heading" data-cart-recommendations data-cart-native-submits data-recommendation-source="{{ $cartRecommendations['source'] }}">
        <div class="splide herrera-product-carousel herrera-home-product-carousel lm-cart-recommendations-carousel" data-cart-recommendations-carousel data-herrera-home-carousel data-continuous-card-carousel data-product-count="{{ $recommendationCount }}" data-splide='@json($recommendationCarouselOptions)' aria-labelledby="cart-recommendations-heading">
            <div class="lm-cart-recommendations-heading">
                <div>
                    <p class="lm-cart-recommendations-eyebrow">{{ config('storefront.home') === 'watchline' ? 'Odabrano za vas' : 'Za još više igre' }}</p>
                    <h2 id="cart-recommendations-heading">{{ $cartRecommendations['source'] === 'co_purchase' ? 'Kupci su uz ove artikle kupili i' : 'Možda će vam se svidjeti' }}</h2>
                </div>
                <div class="lm-cart-recommendations-actions">
                    <a href="{{ route('shop.index') }}">Istražite ponudu <span aria-hidden="true">→</span></a>
                    @if ($recommendationCount > 2)
                        <div class="splide__arrows">
                            <button class="splide__arrow splide__arrow--prev" type="button" aria-label="Prethodni proizvodi" disabled><x-fa-icon name="chevron-right" /></button>
                            <button class="splide__arrow splide__arrow--next" type="button" aria-label="Sljedeći proizvodi" disabled><x-fa-icon name="chevron-right" /></button>
                        </div>
                    @endif
                </div>
            </div>
            <div class="splide__track">
                <ul class="splide__list">
                    @foreach ($cartRecommendations['products'] as $recommendedProduct)
                        <li class="splide__slide">
                            <x-front.desktop.product-card :product="$recommendedProduct" :locale="$locale ?? app()->getLocale()" :fallback-locale="$fallbackLocale ?? config('app.locale')" :lined="true" :flat="true" />
                        </li>
                    @endforeach
                </ul>
            </div>
        </div>
    </section>
    @include('front.partials.herrera-home-carousel-script')
@endif
