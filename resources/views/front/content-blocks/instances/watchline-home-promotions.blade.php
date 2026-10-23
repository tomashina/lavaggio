@php
    // Keep this offer row current when an editor-selected promotion expires.
    $lmPromotionPrices = app(\App\Services\Pricing\ProductPricePresentationService::class);
    $products = $products->filter(fn ($product) => (bool) ($lmPromotionPrices->forProduct($product, auth()->user())['has_promotional_discount'] ?? false))->values();
    $lmProductCount = $products->count();
    $lmProductHeadingId = 'libermedia-promotions-'.$block->id;
    $lmProductOptions = [
        'type' => $lmProductCount > 6 ? 'loop' : 'slide', 'perPage' => 6, 'perMove' => 1,
        'gap' => '0rem', 'pagination' => false, 'arrows' => $lmProductCount > 6,
        'destroy' => $lmProductCount <= 6, 'drag' => true, 'snap' => true, 'speed' => 420,
        'breakpoints' => [
            1279 => ['perPage' => 4, 'destroy' => $lmProductCount <= 4, 'arrows' => $lmProductCount > 4],
            767 => ['perPage' => 2, 'destroy' => $lmProductCount <= 2, 'arrows' => $lmProductCount > 2],
        ],
    ];
@endphp
@if ($products->isNotEmpty())
    @include('front.partials.splide-assets')
    <section class="herrera-home-section herrera-b2b-products lm-extra-products" aria-labelledby="{{ $lmProductHeadingId }}" data-libermedia-promotions>
        <div class="splide herrera-home-product-carousel" data-herrera-home-carousel data-continuous-card-carousel data-splide='@json($lmProductOptions)' aria-labelledby="{{ $lmProductHeadingId }}" style="--herrera-product-columns:6;--herrera-product-tablet-columns:4;--herrera-product-mobile-columns:2;">
            <div class="herrera-home-heading">
                <div>
                    <p class="herrera-home-section-eyebrow">ODABRANA AKCIJSKA PONUDA</p>
                    <h2 id="{{ $lmProductHeadingId }}">{{ $translation?->title ?? $block->name }}</h2>
                </div>
                <div class="herrera-home-product-actions">
                    @if ($translation?->cta_label && $translation?->cta_url)
                        <a class="herrera-home-products-link" href="{{ $translation->cta_url }}">{{ $translation->cta_label }} <x-fa-icon name="arrow-right" /></a>
                    @endif
                    @if ($lmProductCount > 2)
                        <div class="splide__arrows">
                            <button class="splide__arrow splide__arrow--prev" type="button" aria-label="Prethodni proizvodi" disabled><x-fa-icon name="chevron-right" /></button>
                            <button class="splide__arrow splide__arrow--next" type="button" aria-label="Sljedeći proizvodi" disabled><x-fa-icon name="chevron-right" /></button>
                        </div>
                    @endif
                </div>
            </div>
            <div class="splide__track"><div class="splide__list">
                @foreach ($products as $lmProduct)
                    <div class="splide__slide"><x-front.desktop.product-card :product="$lmProduct" :locale="$locale" :fallback-locale="$fallbackLocale" :lined="true" :flat="true" /></div>
                @endforeach
            </div></div>
        </div>
    </section>
    @include('front.partials.herrera-home-carousel-script')
@endif
