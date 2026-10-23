@php
    $categoryBrandsHeadingId = 'category-brands-'.$category->id.'-title';
    $categoryBrandsCount = $categoryBrands->count();
    $categoryBrandScopeTranslation = $category->translations->firstWhere('locale', $locale)
        ?? $category->translations->firstWhere('locale', $fallbackLocale);
    $categoryBrandScopeSlug = (string) ($categoryBrandScopeTranslation?->slug ?? request()->route('slug'));
    $categoryBrandsPrevious = trim(str_replace(['&laquo;', '&raquo;'], '', __('pagination.previous')));
    $categoryBrandsNext = trim(str_replace(['&laquo;', '&raquo;'], '', __('pagination.next')));
    $categoryBrandsOptions = [
        'type' => 'slide', 'rewind' => false, 'perPage' => min(7, $categoryBrandsCount),
        'perMove' => 1, 'gap' => '12px', 'drag' => true, 'snap' => true,
        'pagination' => false, 'arrows' => $categoryBrandsCount > 1, 'speed' => 420,
        'i18n' => ['prev' => $categoryBrandsPrevious, 'next' => $categoryBrandsNext],
        'breakpoints' => [
            1439 => ['perPage' => min(6, $categoryBrandsCount)],
            1023 => ['perPage' => min(4, $categoryBrandsCount)],
            767 => ['perPage' => min(2, $categoryBrandsCount)],
        ],
    ];
@endphp

@once('watchline-brands-widget-styles')
    @push('styles')
        <link rel="stylesheet" href="{{ asset('front-theme/styles/herrera-brand-strip.css') }}?v={{ filemtime(public_path('front-theme/styles/herrera-brand-strip.css')) }}">
    @endpush
@endonce
@once('watchline-category-brands-styles')
    @push('styles')
        <link rel="stylesheet" href="{{ asset('front-theme/styles/watchline-category-brands.css') }}?v={{ filemtime(public_path('front-theme/styles/watchline-category-brands.css')) }}">
    @endpush
@endonce
@include('front.partials.splide-assets')

<nav class="watchline-category-brands storefront-container px-3 sm:px-4 lg:px-6" aria-labelledby="{{ $categoryBrandsHeadingId }}" data-watchline-category-brands>
    <div class="splide herrera-brand-carousel" data-herrera-brand-carousel data-herrera-home-carousel data-watchline-brand-columns="{{ $categoryBrandsOptions['perPage'] }}" data-splide='@json($categoryBrandsOptions)' aria-labelledby="{{ $categoryBrandsHeadingId }}">
        <div class="herrera-brand-strip__heading">
            <h2 id="{{ $categoryBrandsHeadingId }}">{{ __('Brandovi u ovoj kategoriji') }} ({{ $categoryBrandsCount }})</h2>
            @if ($categoryBrandsCount > 1)
                <div class="splide__arrows">
                    <button class="splide__arrow splide__arrow--prev" type="button" aria-label="{{ $categoryBrandsPrevious }}" disabled><x-fa-icon name="chevron-right" style="solid" /></button>
                    <button class="splide__arrow splide__arrow--next" type="button" aria-label="{{ $categoryBrandsNext }}" disabled><x-fa-icon name="chevron-right" style="solid" /></button>
                </div>
            @endif
        </div>
        <div class="splide__track"><ul class="splide__list herrera-brand-strip__logos">
            @foreach ($categoryBrands as $categoryBrand)
                <li class="splide__slide">
                    <a class="herrera-brand-strip__brand" href="{{ route('manufacturers.show', ['slug' => $categoryBrand['slug'], 'category' => $categoryBrandScopeSlug]) }}" aria-label="{{ __('herrera.brand_browse', ['name' => $categoryBrand['name']]) }}" data-watchline-category-brand="{{ $categoryBrand['id'] }}">
                        @if ($categoryBrand['logo'])
                            <span class="herrera-brand-strip__image {{ $categoryBrand['variant'] !== '' ? 'herrera-brand-strip__image--'.$categoryBrand['variant'] : '' }}">
                                <img src="{{ $categoryBrand['logo'] }}" alt="{{ $categoryBrand['name'] }}" width="160" height="64" loading="lazy" decoding="async">
                            </span>
                        @else
                            <span class="watchline-category-brands__fallback">{{ $categoryBrand['name'] }}</span>
                        @endif
                    </a>
                </li>
            @endforeach
        </ul></div>
    </div>
</nav>
@include('front.partials.herrera-home-carousel-script')
