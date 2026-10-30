@php
    $compactProductSpecifications = $compactProductSpecifications ?? app(\App\Support\ProductSpecificationPresenter::class)
        ->compact($product, $locale ?? app()->getLocale(), $fallbackLocale ?? config('app.locale'), (string) ($translation?->description ?? ''));
    $isWatchlineSpecifications = config('storefront.home') === 'watchline';
    $showAllSpecifications = config('storefront.home') === 'lavaggio'
        || (! $isWatchlineSpecifications && \App\Support\StorefrontTheme::usesLibermediaBrand());
    $allSpecifications = $compactProductSpecifications['primary']->concat($compactProductSpecifications['secondary']);
    $visibleSpecifications = $isWatchlineSpecifications
        ? $allSpecifications->take(6)
        : ($showAllSpecifications ? $allSpecifications : $compactProductSpecifications['primary']);
    $remainingSpecifications = $isWatchlineSpecifications
        ? $allSpecifications->skip(6)
        : $compactProductSpecifications['secondary'];
@endphp

@if ($compactProductSpecifications['primary']->isNotEmpty() || $compactProductSpecifications['secondary']->isNotEmpty())
    @once('herrera-product-facts-styles')
        @push('styles')
            <link rel="stylesheet" href="{{ asset('front-theme/styles/herrera-product-facts.css') }}?v={{ filemtime(public_path('front-theme/styles/herrera-product-facts.css')) }}">
        @endpush
    @endonce
    <section class="herrera-product-specifications" aria-labelledby="herrera-product-specifications-title" data-herrera-product-specifications>
        <h2 id="herrera-product-specifications-title">{{ __('ui.product.specifications') }}</h2>
        @if ($visibleSpecifications->isNotEmpty())
            <dl class="product-information-list" data-product-primary-specifications>
                @foreach ($visibleSpecifications as $fact)
                    <div class="product-information-row">
                        <dt>{{ $fact['label'] }}</dt>
                        <dd>{{ $fact['value'] }}</dd>
                    </div>
                @endforeach
            </dl>
        @endif
        @if (! $showAllSpecifications && $remainingSpecifications->isNotEmpty())
            <details class="product-information-panel herrera-product-more-specifications" data-product-secondary-specifications>
                <summary class="product-information-summary">
                    <span class="product-information-summary-label">{{ app()->getLocale() === 'hr' ? 'Ostale specifikacije' : 'More specifications' }}</span>
                    <span class="product-information-summary-toggle" aria-hidden="true">
                        <x-fa-icon name="plus" class="product-information-icon-plus" />
                        <x-fa-icon name="minus" class="product-information-icon-minus" />
                    </span>
                </summary>
                <dl class="product-information-list product-information-content">
                    @foreach ($remainingSpecifications as $fact)
                        <div class="product-information-row">
                            <dt>{{ $fact['label'] }}</dt>
                            <dd>{{ $fact['value'] }}</dd>
                        </div>
                    @endforeach
                </dl>
            </details>
        @endif
    </section>
@endif
