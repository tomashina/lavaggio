@php
    $watchlineHeroSlides = $watchlineHeroSlides ?? collect();
    $watchlineHeroOptions = [
        'type' => 'fade', 'rewind' => true, 'perPage' => 1, 'perMove' => 1,
        'autoplay' => $watchlineHeroSlides->count() > 1, 'interval' => 7000,
        'pauseOnHover' => true, 'pauseOnFocus' => true, 'pagination' => false, 'arrows' => false,
        'drag' => true, 'keyboard' => 'focused', 'speed' => 500,
        'focusableNodes' => 'a:not([aria-hidden="true"]), button, textarea, input, select, iframe',
        'reducedMotion' => ['speed' => 0, 'rewindSpeed' => 0, 'autoplay' => 'pause'],
        'i18n' => ['carousel' => 'najprodavaniji CLUSE ženski satovi', 'slide' => 'sat', 'slideLabel' => '%s od %s'],
    ];
@endphp

<section class="wl-hero {{ $watchlineHeroSlides->isEmpty() ? 'wl-hero--empty' : '' }}" aria-labelledby="watchline-home-title" data-watchline-hero>
    <h1 id="watchline-home-title" class="sr-only">{{ __('Satovi i nakit — Watchline') }}</h1>
    @if ($watchlineHeroSlides->isNotEmpty())
        @include('front.partials.splide-assets')
        <div class="splide wl-hero-carousel" data-watchline-hero-carousel data-splide='@json($watchlineHeroOptions)' aria-label="{{ __('CLUSE ženski satovi po izboru naših kupaca') }}">
            <div class="splide__track">
                <ul class="splide__list">
                    @foreach ($watchlineHeroSlides as $heroSlide)
                        <li class="splide__slide wl-hero-slide" data-watchline-hero-product="{{ $heroSlide['product']->id }}">
                            <div class="wl-hero-copy">
                                <p class="wl-hero-eyebrow"><span aria-hidden="true"></span>{{ __('NAJPRODAVANIJI CLUSE SATOVI') }}</p>
                                <h2 class="wl-hero-title"><span class="wl-hero-brand">{{ $heroSlide['brand'] }}</span><strong class="wl-hero-model">{{ $heroSlide['title'] }}</strong></h2>
                                @if ($heroSlide['rating_count'] > 0 && $heroSlide['rating_average'] !== null)
                                    @php
                                        $heroRatingDisplay = rtrim(rtrim(number_format($heroSlide['rating_average'], 2, ',', ''), '0'), ',');
                                        $heroStarRating = round($heroSlide['rating_average'] * 2) / 2;
                                        $heroRatingCountLabel = trans_choice('{1} :count recenzija|[2,4] :count recenzije|[5,*] :count recenzija', $heroSlide['rating_count'], ['count' => $heroSlide['rating_count']]);
                                    @endphp
                                    <a class="wl-hero-rating" href="{{ $heroSlide['url'] }}#product-comments" aria-label="{{ __('Prosječna ocjena :rating od 5; :count', ['rating' => $heroRatingDisplay, 'count' => $heroRatingCountLabel]) }}">
                                        <span class="wl-hero-stars" aria-hidden="true">
                                            @for ($heroStar = 1; $heroStar <= 5; $heroStar++)
                                                <span class="wl-hero-star {{ $heroStarRating >= $heroStar ? 'is-full' : ($heroStarRating >= $heroStar - 0.5 ? 'is-half' : 'is-empty') }}">★</span>
                                            @endfor
                                        </span>
                                        <span class="wl-hero-rating-count">({{ $heroRatingCountLabel }})</span>
                                    </a>
                                @endif
                                @if ($heroSlide['price'])
                                    <div class="wl-hero-price">
                                        <span class="wl-hero-price-current">{{ $heroSlide['price'] }}</span>
                                        @if ($heroSlide['old_price'])
                                            <del class="wl-hero-price-original"><span class="sr-only">{{ __('Redovna cijena') }}: </span>{{ $heroSlide['old_price'] }}</del>
                                        @endif
                                    </div>
                                @endif
                                <div class="wl-hero-actions">
                                    <a href="{{ $heroSlide['url'] }}" class="wl-hero-cta">{{ __('Pogledajte sat') }} <x-fa-icon name="arrow-right" /></a>
                                </div>
                            </div>
                            <div class="wl-hero-visual">
                                <span class="wl-hero-visual-mark" aria-hidden="true">CLUSE</span>
                                <a href="{{ $heroSlide['url'] }}" class="wl-hero-image-link" tabindex="-1" aria-hidden="true">
                                    <img class="wl-hero-product-image" src="{{ $heroSlide['image'] }}" alt="{{ $heroSlide['product']->translations->firstWhere('locale', app()->getLocale())?->name ?? $heroSlide['title'] }}" width="1000" height="1000" loading="{{ $loop->first ? 'eager' : 'lazy' }}" fetchpriority="{{ $loop->first ? 'high' : 'auto' }}" decoding="async">
                                </a>
                                <span class="wl-hero-model-reference">{{ $heroSlide['model'] }}</span>
                            </div>
                        </li>
                    @endforeach
                </ul>
            </div>
        </div>
        @if ($watchlineHeroSlides->count() > 1)
            <div class="wl-hero-controls" data-watchline-hero-controls hidden>
                <button class="wl-hero-arrow wl-hero-arrow--prev" type="button" data-watchline-hero-prev aria-label="{{ __('Prethodni sat') }}"><x-fa-icon name="arrow-left" /></button>
                <span class="wl-hero-count" aria-hidden="true"><span data-watchline-hero-current>01</span><span class="wl-hero-count-divider">/</span>{{ str_pad((string) $watchlineHeroSlides->count(), 2, '0', STR_PAD_LEFT) }}</span>
                <button class="wl-hero-arrow wl-hero-arrow--next" type="button" data-watchline-hero-next aria-label="{{ __('Sljedeći sat') }}"><x-fa-icon name="arrow-right" /></button>
                <button class="wl-hero-toggle" type="button" data-watchline-hero-toggle aria-pressed="false" aria-label="{{ __('Zaustavi automatsku izmjenu satova') }}"><span data-watchline-hero-toggle-label>{{ __('Pauza') }}</span><span data-watchline-hero-toggle-icon aria-hidden="true">Ⅱ</span></button>
            </div>
            <p class="sr-only" data-watchline-hero-status aria-live="polite" aria-atomic="true"></p>
        @endif
        @once('watchline-hero-script')
            @push('scripts')
                <script defer src="{{ asset('front-theme/scripts/watchline-hero.js') }}?v={{ filemtime(public_path('front-theme/scripts/watchline-hero.js')) }}"></script>
            @endpush
        @endonce
    @else
        <div class="wl-hero-slide">
            <div class="wl-hero-copy">
                <p class="wl-hero-eyebrow">{{ __('WATCHLINE · SATOVI I NAKIT') }}</p>
                <h2 class="wl-hero-title"><span class="wl-hero-brand">{{ __('Vrijeme za stil.') }}</span><strong class="wl-hero-model">{{ __('Za svaki trenutak.') }}</strong></h2>
                <a href="{{ route('shop.index') }}" class="wl-hero-cta">{{ __('Istražite ponudu') }} <x-fa-icon name="arrow-right" /></a>
            </div>
        </div>
    @endif
</section>
