@php
    $homeBlogLocale = (string) app()->getLocale();
    $homeBlogFallbackLocale = (string) config('app.fallback_locale', 'hr');
    $homeHasManagedBlog = collect([
        $homeHeroBlocks, $homeHeroBenefitsBlocks, $homeCategoriesBlocks,
        $homeBeforeProductsBlocks, $homeAfterProductsBlocks, $homeBottomBlocks,
    ])->flatten(1)->contains(fn ($item) => in_array((string) data_get($item, 'block.type'), ['blogs_carousel', 'blog_grid_3', 'blogs'], true));
    $homeBlogPosts = $homeHasManagedBlog ? collect() : ($homeBlogPosts ?? collect());
    $homeBlogCount = $homeBlogPosts->count();
    $homeBlogCarouselOptions = [
        'type' => 'slide',
        'perPage' => 3,
        'perMove' => 1,
        'gap' => '24px',
        'arrows' => $homeBlogCount > 3,
        'destroy' => $homeBlogCount <= 3,
        'pagination' => false,
        'drag' => $homeBlogCount > 1,
        'speed' => 420,
        'reducedMotion' => ['speed' => 0, 'rewindSpeed' => 0],
        'i18n' => [
            'prev' => 'Prethodni članci', 'next' => 'Sljedeći članci',
            'first' => 'Prvi članak', 'last' => 'Posljednji članak',
            'carousel' => 'klizač članaka', 'slide' => 'članak',
            'slideX' => 'Idi na članak %s', 'slideLabel' => '%s od %s',
        ],
        'breakpoints' => [
            1023 => ['perPage' => 2, 'gap' => '16px', 'destroy' => $homeBlogCount <= 2, 'arrows' => $homeBlogCount > 2],
            767 => ['perPage' => 1, 'gap' => '16px', 'destroy' => $homeBlogCount <= 1, 'arrows' => $homeBlogCount > 1],
        ],
    ];
@endphp

@if ($homeBlogPosts->isNotEmpty())
    @include('front.partials.splide-assets')
    @once('herrera-home-b2b-styles')
        @push('styles')
            <link rel="stylesheet" href="{{ asset('front-theme/styles/herrera-home-b2b.css') }}?v={{ filemtime(public_path('front-theme/styles/herrera-home-b2b.css')) }}">
        @endpush
    @endonce
    @once('libermedia-home-blog-styles')
        @push('styles')
            <link rel="stylesheet" href="{{ asset('front-theme/styles/herrera-home-blog.css') }}?v={{ filemtime(public_path('front-theme/styles/herrera-home-blog.css')) }}">
            <link rel="stylesheet" href="{{ asset('front-theme/styles/libermedia-home-blog.css') }}?v={{ filemtime(public_path('front-theme/styles/libermedia-home-blog.css')) }}">
        @endpush
    @endonce

    <section class="splide herrera-home-section herrera-b2b-products herrera-home-blog-widget libermedia-home-blog libermedia-home-blog-carousel" aria-labelledby="libermedia-home-blog-title" data-libermedia-home-blog data-libermedia-home-blog-carousel data-herrera-home-carousel data-post-count="{{ $homeBlogCount }}" data-splide='@json($homeBlogCarouselOptions)'>
        <div class="herrera-home-heading">
            <div>
                <p class="herrera-home-section-eyebrow">{{ __('LIBER MEDIA BLOG') }}</p>
                <h2 id="libermedia-home-blog-title">{{ __('Ideje za igru i učenje') }}</h2>
            </div>
            <div class="libermedia-home-blog-actions">
                <a class="herrera-home-products-link" href="{{ route('blog.index') }}">{{ __('Vidi sve') }} <x-fa-icon name="arrow-right" /></a>
                @if ($homeBlogCount > 1)
                    <div class="splide__arrows">
                        <button class="splide__arrow splide__arrow--prev" type="button" aria-label="Prethodni članci" disabled><x-fa-icon name="chevron-right" /></button>
                        <button class="splide__arrow splide__arrow--next" type="button" aria-label="Sljedeći članci" disabled><x-fa-icon name="chevron-right" /></button>
                    </div>
                @endif
            </div>
        </div>
        <div class="splide__track">
        <ul class="splide__list libermedia-home-blog-grid">
            @foreach ($homeBlogPosts as $homeBlogPost)
                @php
                    $homeBlogTranslation = $homeBlogPost->translations->firstWhere('locale', $homeBlogLocale)
                        ?? $homeBlogPost->translations->firstWhere('locale', $homeBlogFallbackLocale);
                @endphp
                <li class="splide__slide">
                    @include('front.partials.herrera-blog-card', ['blogCardPost' => $homeBlogPost, 'blogCardLocale' => $homeBlogLocale, 'blogCardFallbackLocale' => $homeBlogFallbackLocale])
                    <a class="libermedia-home-blog-read" href="{{ route('blog.show', ['slug' => $homeBlogTranslation->slug]) }}">{{ __('Pročitaj članak') }} <x-fa-icon name="arrow-right" /></a>
                </li>
            @endforeach
        </ul>
        </div>
    </section>
    @include('front.partials.herrera-home-carousel-script')
@endif
