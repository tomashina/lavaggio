@php
    $locale = (string) ($locale ?? app()->getLocale());
    $fallbackLocale = (string) ($fallbackLocale ?? config('app.locale'));
    $categories = $categories ?? \App\Models\Catalog\Category\Category::query()
        ->where('scope', \App\Models\Catalog\Category\Category::SCOPE_CATALOG)
        ->currentlyVisible()
        ->whereNull('parent_id')
        ->with(['translations', 'media'])
        ->orderBy('sort_order')
        ->orderBy('id')
        ->get();
    $categoryTitle = trim((string) ($title ?? __('herrera.categories')));
    if (\App\Support\StorefrontTheme::usesLibermediaBrand() && $categoryTitle === 'Pronađite svoju kategoriju') {
        $categoryTitle = __('Istraži kategorije');
    }
    $categorySubtitle = trim((string) ($subtitle ?? ''));
    $categoryCtaLabel = trim((string) ($ctaLabel ?? __('herrera.all_categories')));
    if (\App\Support\StorefrontTheme::usesLibermediaBrand()) {
        $categoryCtaLabel = __('Vidi sve');
    }
    $categoryCtaUrl = trim((string) ($ctaUrl ?? route('categories.index')));
    $showCategoryModuleHeading = (bool) ($showCategoryHeading ?? true);
    $showHerreraCategorySupport = (bool) ($showCategorySupport ?? request()->routeIs('home'))
        && str_contains(strtolower((string) ($storeSettings['branding']['store_name'] ?? config('app.name'))), 'herrera');
    $usesLibermediaCategories = \App\Support\StorefrontTheme::usesLibermediaBrand((string) ($storeSettings['branding']['store_name'] ?? config('app.name')));
    $isWatchlineCategories = config('storefront.home') === 'watchline';
    $showLibermediaBundleTile = $usesLibermediaCategories && ! $isWatchlineCategories && request()->routeIs('home');
    $showLibermediaGiftTile = $usesLibermediaCategories && ! $isWatchlineCategories && request()->routeIs('categories.index');
    $showWatchlineGiftVoucherTile = $usesLibermediaCategories && $isWatchlineCategories && config('gift_vouchers.enabled', true)
        && request()->routeIs('home', 'categories.index');
    $preferWebp = (bool) ($storeSettings['images']['use_webp'] ?? false);
    $categoryIcons = [
        'rasvjeta' => 'lightbulb',
        'elektromaterijal' => 'bolt',
        'uticnice-i-prekidaci' => 'outlet',
        'produzni-kabeli-i-motalice' => 'reel',
        'oprema-za-mjerenje-i-ispitivanje' => 'ruler',
        'senzori' => 'sensor',
        'alati' => 'screwdriver-wrench',
        'mali-kucanski-aparati' => 'kitchen-set',
        'ljepota-i-zdravlje' => 'heart-pulse',
        'internet-audio-video-tv' => 'tv',
        'baterije' => 'battery-half',
        'dom-i-dizajn' => 'house',
        'grijanje-hladenje-i-zastita-od-insekata' => 'temperature-half',
        'osobna-zastitna-oprema' => 'helmet-safety',
        'vodovodni-materijal-i-sanitarije' => 'faucet',
        'punionice-za-ev-vozila' => 'car-bolt',
        'extra-popusti' => 'tag',
        'zeljezarija' => 'toolbox',
        'svijece-i-upaljaci' => 'candle-holder',
    ];
    $libermediaCategoryIcons = [
        'didakticke-igracke' => ['icon' => 'teddy-bear', 'tone' => 'green'],
        'skolski-pribor' => ['icon' => 'pen-ruler', 'tone' => 'orange'],
        'kocke-za-djecu' => ['icon' => 'cubes-stacked', 'tone' => 'green'],
        'skolske-torbe-i-ruksaci' => ['icon' => 'backpack', 'tone' => 'orange'],
        'hobby-art' => ['icon' => 'paintbrush-pencil', 'tone' => 'green'],
        'guralica-i-njihalica-za-bebe' => ['icon' => 'car-side', 'tone' => 'orange'],
        'romobil-i-tricikl-za-djecu' => ['icon' => 'scooter', 'tone' => 'green'],
        'robot-i-stem-igracke' => ['icon' => 'robot', 'tone' => 'orange'],
        'kreativni-setovi-za-crtanje-i-igru' => ['icon' => 'palette', 'tone' => 'green'],
    ];
@endphp

@once
    @push('styles')
        <link rel="stylesheet" href="{{ asset('front-theme/styles/herrera-home-categories.css') }}?v={{ filemtime(public_path('front-theme/styles/herrera-home-categories.css')) }}">
    @endpush
@endonce

@if ($categories->isNotEmpty())
    <section class="herrera-category-module" data-herrera-category-module data-featured-categories>
        @if ($showCategoryModuleHeading)
        <header class="herrera-category-module-heading">
            <div>
                @if ($categoryTitle !== '')
                    <h2>{{ $categoryTitle }}</h2>
                @endif
                @if ($categorySubtitle !== '')
                    <p>{{ $categorySubtitle }}</p>
                @endif
            </div>
            @if ($categoryCtaLabel !== '' && $categoryCtaUrl !== '')
                <a href="{{ $categoryCtaUrl }}">{{ $categoryCtaLabel }} <x-fa-icon name="arrow-right" /></a>
            @endif
        </header>
        @endif
        <div class="herrera-category-module-grid category-index-grid">
            @foreach ($categories as $category)
                @php
                    $categoryTranslation = $category->translations->firstWhere('locale', $locale)
                        ?? $category->translations->firstWhere('locale', $fallbackLocale)
                        ?? $category->translations->first();
                    $categoryName = trim((string) ($categoryTranslation?->name ?? $category->code));
                    $categorySlug = (string) ($categoryTranslation?->slug ?? $category->id);
                    $iconSlug = (string) ($category->translations->firstWhere('locale', 'hr')?->slug ?? $categorySlug);
                    $categoryIcon = $categoryIcons[$iconSlug] ?? 'boxes-stacked';
                    $categoryIconTone = null;
                    if ($usesLibermediaCategories) {
                        $libermediaCategoryIcon = $libermediaCategoryIcons[$iconSlug] ?? ['icon' => 'shapes', 'tone' => 'green'];
                        $categoryIcon = $libermediaCategoryIcon['icon'];
                        $categoryIconTone = $libermediaCategoryIcon['tone'];
                    }
                    $categoryMedia = collect([
                        $category->getFirstMedia('category_banner'),
                        $category->getFirstMedia('category_icon'),
                    ])->first(fn ($media) => \App\Support\Media\MediaUrl::hasUsableSource($media, ['card_360x240', 'icon_96x96', 'card_192w', 'card_320w', 'square_540w']));
                    $categoryImageUrl = $categoryMedia
                        ? ($categoryMedia->collection_name === 'category_banner'
                            ? (\App\Support\Media\MediaUrl::conversionOrNull($categoryMedia, 'card_360x240', $preferWebp) ?? $categoryMedia->getUrl())
                            : (\App\Support\Media\MediaUrl::conversionOrNull($categoryMedia, 'square_540w', $preferWebp)
                                ?? \App\Support\Media\MediaUrl::conversionOrNull($categoryMedia, 'card_320w', $preferWebp)
                                ?? \App\Support\Media\MediaUrl::conversionOrNull($categoryMedia, 'card_192w', $preferWebp)
                                ?? \App\Support\Media\MediaUrl::conversionOrNull($categoryMedia, 'icon_96x96', $preferWebp)
                                ?? $categoryMedia->getUrl()))
                        : null;
                    $categoryPhotoPath = str_contains(strtolower((string) ($storeSettings['branding']['store_name'] ?? config('app.name'))), 'herrera')
                        ? ltrim((string) config('herrera-category-photography.'.$iconSlug, ''), '/')
                        : '';
                    if (! $categoryImageUrl && $categoryPhotoPath !== '' && is_file(public_path($categoryPhotoPath))) {
                        $categoryImageUrl = asset($categoryPhotoPath);
                    }
                    $categoryImageUrl ??= \App\Support\Media\LegacyCatalogImage::first($category);
                @endphp
                <a href="{{ route('categories.show', ['slug' => $categorySlug]) }}" class="herrera-category-tile {{ $categoryImageUrl ? 'has-image' : 'is-icon-only' }}" data-category-card="{{ $category->id }}" data-featured-category="{{ $category->id }}" @if ($categoryIconTone) data-category-icon-tone="{{ $categoryIconTone }}" @endif>
                    <span class="herrera-category-tile-media">
                        @if ($categoryImageUrl)
                            <img src="{{ $categoryImageUrl }}" alt="" width="144" height="144" loading="lazy" decoding="async">
                            <span class="herrera-category-tile-icon"><x-fa-icon :name="$categoryIcon" style="light" /></span>
                        @else
                            <x-fa-icon :name="$categoryIcon" style="light" class="herrera-category-tile-fallback" />
                        @endif
                    </span>
                    <span class="herrera-category-tile-caption">
                        <span class="herrera-category-tile-name">{{ $categoryName }}</span>
                        <x-fa-icon name="arrow-up-right" class="herrera-category-tile-arrow" />
                    </span>
                </a>
            @endforeach
            @if ($showWatchlineGiftVoucherTile)
                <a href="{{ route('gift-vouchers.create') }}" class="herrera-category-tile libermedia-category-bundle" data-watchline-category-gift-voucher>
                    <span class="libermedia-category-bundle-icon"><x-fa-icon name="gift" style="light" /></span>
                    <span class="libermedia-category-bundle-copy">
                        <span class="libermedia-category-bundle-eyebrow">POKLON PO NJIHOVOM IZBORU</span>
                        <span class="libermedia-category-bundle-title">Poklon bon</span>
                        <span class="libermedia-category-bundle-description">Darujte izbor satova, naočala i nakita.</span>
                    </span>
                    <span class="libermedia-category-bundle-cta">Odaberite poklon bon <x-fa-icon name="arrow-right" /></span>
                </a>
            @endif
            @if ($showLibermediaGiftTile)
                <a href="{{ route('gift-guide.index') }}" class="herrera-category-tile libermedia-category-bundle" data-libermedia-category-gift>
                    <span class="libermedia-category-bundle-icon"><x-fa-icon name="gift" style="light" /></span>
                    <span class="libermedia-category-bundle-copy">
                        <span class="libermedia-category-bundle-eyebrow">{{ __('Mali znak pažnje, velika radost') }}</span>
                        <span class="libermedia-category-bundle-title">{{ __('Tražiš poklon?') }}</span>
                        <span class="libermedia-category-bundle-description">{{ __('Odaberi dob, interese i budžet. Pronađi poklon koji donosi osmijeh.') }}</span>
                    </span>
                    <span class="libermedia-category-bundle-cta">{{ __('Pronađi poklon') }} <x-fa-icon name="arrow-right" /></span>
                </a>
            @endif
            @if ($showLibermediaBundleTile)
                <a href="{{ route('bundles.index') }}" class="herrera-category-tile libermedia-category-bundle" data-libermedia-category-bundle>
                    <span class="libermedia-category-bundle-icon"><x-fa-icon name="gift" style="light" /></span>
                    <span class="libermedia-category-bundle-copy">
                        <span class="libermedia-category-bundle-eyebrow">{{ __('Više igre, više uštede') }}</span>
                        <span class="libermedia-category-bundle-title">{{ __('Kupi u kompletu i uštedi') }}</span>
                        <span class="libermedia-category-bundle-description">{{ __('Otkrijte odabrane komplete za igru i učenje.') }}</span>
                    </span>
                    <span class="libermedia-category-bundle-cta">{{ __('Pogledajte ponudu') }} <x-fa-icon name="arrow-right" /></span>
                </a>
            @endif
            @if ($showHerreraCategorySupport)
                <article class="herrera-category-tile herrera-category-support" aria-label="{{ __('herrera.category_support.title') }}" data-herrera-category-support>
                    <div class="herrera-category-support-copy">
                        <p class="herrera-category-support-eyebrow">{{ __('herrera.category_support.eyebrow') }}</p>
                        <h3>{{ __('herrera.category_support.title') }}</h3>
                        <p class="herrera-category-support-description">{{ __('herrera.category_support.description') }}</p>
                    </div>
                    <div class="herrera-category-support-actions">
                        <a class="herrera-category-support-contact" href="{{ route('contact.create') }}">{{ __('herrera.category_support.contact') }}</a>
                        <a class="herrera-category-support-register" href="{{ route('front.auth.b2b-register') }}">{{ __('herrera.category_support.register') }}</a>
                    </div>
                </article>
            @endif
        </div>
    </section>
@endif
