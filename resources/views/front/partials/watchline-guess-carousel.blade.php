@php
    $guessProducts = $watchlineGuessProducts ?? collect();
    $guessCount = $guessProducts->count();
    $guessPreviousLabel = trim(str_replace(['&laquo;', '&raquo;'], '', __('pagination.previous')));
    $guessNextLabel = trim(str_replace(['&laquo;', '&raquo;'], '', __('pagination.next')));
    $guessCarouselOptions = [
        'type' => $guessCount > 1 ? 'loop' : 'slide',
        'rewind' => false,
        'perPage' => 6,
        'destroy' => $guessCount <= 6,
        'perMove' => 1,
        'gap' => '0rem',
        'drag' => $guessCount > 1,
        'snap' => true,
        'pagination' => false,
        'arrows' => $guessCount > 6,
        'updateOnMove' => true,
        'speed' => 420,
        'i18n' => ['prev' => $guessPreviousLabel, 'next' => $guessNextLabel, 'first' => $guessNextLabel, 'last' => $guessPreviousLabel],
        'breakpoints' => [
            1279 => ['perPage' => 4, 'destroy' => $guessCount <= 4, 'arrows' => $guessCount > 4],
            767 => ['perPage' => 2, 'destroy' => $guessCount <= 2, 'arrows' => $guessCount > 2],
        ],
    ];
@endphp

@if ($guessProducts->isNotEmpty())
    <section class="herrera-home-section herrera-b2b-products" aria-labelledby="watchline-guess-title" data-watchline-guess-products>
        @include('front.partials.splide-assets')
        <div class="splide herrera-home-product-carousel wl-product-carousel" data-herrera-home-carousel data-continuous-card-carousel data-splide='@json($guessCarouselOptions)' aria-labelledby="watchline-guess-title">
            <div class="herrera-home-heading">
                <div>
                    <p class="herrera-home-section-eyebrow">Novo u ponudi</p>
                    <h2 id="watchline-guess-title">GUESS satovi</h2>
                </div>
                <div class="herrera-home-product-actions">
                    <a class="herrera-home-products-link" href="{{ app(\App\Support\WatchlineHomeContent::class)->guessCategoryUrl() }}">Svi GUESS satovi <x-fa-icon name="arrow-right" /></a>
                    @if ($guessCount > 2)
                        <div class="splide__arrows">
                            <button class="splide__arrow splide__arrow--prev" type="button" aria-label="{{ $guessPreviousLabel }}" disabled><x-fa-icon name="chevron-right" style="solid" /></button>
                            <button class="splide__arrow splide__arrow--next" type="button" aria-label="{{ $guessNextLabel }}" disabled><x-fa-icon name="chevron-right" style="solid" /></button>
                        </div>
                    @endif
                </div>
            </div>
            <div class="splide__track">
                <div class="splide__list">
                    @foreach ($guessProducts as $guessProduct)
                        <div class="splide__slide">
                            <x-front.desktop.product-card :product="$guessProduct" :locale="app()->getLocale()" :fallback-locale="config('app.fallback_locale', 'hr')" :lined="true" :flat="true" />
                        </div>
                    @endforeach
                </div>
            </div>
        </div>
    </section>

    @include('front.partials.herrera-home-carousel-script')
@endif
