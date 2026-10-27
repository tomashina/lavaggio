@php
    $lavaggioSettings = app(\App\Services\Settings\SystemSettingsService::class);
    $lavaggioLocale = app()->getLocale();
    $lavaggioCategories = \App\Models\Catalog\Category\Category::query()
        ->where('scope', 'catalog')->currentlyVisible()->whereNull('parent_id')->where('show_in_menu', true)
        ->with('translations')->orderBy('sort_order')->orderBy('id')->get();
    $lavaggioProducts = \App\Models\Catalog\Product\Product::query()
        ->visibleOnStorefront(app(\App\Services\Catalog\CatalogFeatureService::class)->hideOutOfStockProducts())
        ->with(['translations', 'manufacturer.translations', 'media'])->orderByDesc('id')->limit(8)->get();
@endphp

<div class="wl-home-opening lavaggio-home">
    <section class="lm-campaign-section" aria-label="Lavaggio webshop">
        <div class="lm-campaign-grid">
            <article class="lm-campaign lm-campaign--bags wl-cluse-campaign lavaggio-main-campaign">
                <div class="lm-campaign-copy">
                    <p class="lm-eyebrow">{{ $lavaggioSettings->get('lavaggio_home_eyebrow', 'LAVAGGIO WEBSHOP') }}</p>
                    <h1 class="wl-campaign-title">{{ $lavaggioSettings->get('lavaggio_home_title', 'Njega rublja s potpisom Lavaggio.') }}</h1>
                    <p>{{ $lavaggioSettings->get('lavaggio_home_description', '') }}</p>
                    <ul class="wl-cluse-perks">
                        <li><x-fa-icon name="truck-fast" /><span>Besplatna dostava iznad <strong>{{ $lavaggioSettings->get('lavaggio_free_shipping_threshold', 50) }} €</strong></span></li>
                    </ul>
                    <a class="lm-button" href="{{ route('shop.index') }}">Istražite ponudu <x-fa-icon name="arrow-right" /></a>
                </div>
            </article>
            <article class="lm-campaign lm-campaign--meli wl-mens-campaign lavaggio-care-campaign">
                <div class="lm-campaign-copy">
                    <p class="lm-eyebrow">{{ $lavaggioSettings->get('lavaggio_home_side_eyebrow', 'ZA VAŠ DOM') }}</p>
                    <h2 class="wl-campaign-title">{{ $lavaggioSettings->get('lavaggio_home_side_title', 'Svježina u svakom pranju.') }}</h2>
                    <p>{{ $lavaggioSettings->get('lavaggio_home_side_description', '') }}</p>
                    <a class="lm-button" href="{{ route('shop.index') }}">Otkrijte ponudu <x-fa-icon name="arrow-right" /></a>
                </div>
            </article>
            <article class="lm-campaign lm-campaign--montessori wl-sunglasses-campaign lavaggio-pro-campaign">
                <div class="lm-campaign-copy">
                    <p class="lm-eyebrow">{{ $lavaggioSettings->get('lavaggio_home_pro_eyebrow', 'LAVAGGIO ISKUSTVO') }}</p>
                    <h2 class="wl-campaign-title">{{ $lavaggioSettings->get('lavaggio_home_pro_title', 'Znanje iz praonice. Njega kod kuće.') }}</h2>
                    <p>{{ $lavaggioSettings->get('lavaggio_home_pro_description', '') }}</p>
                    <a class="lm-button" href="{{ route('contact.create') }}">Kontaktirajte nas <x-fa-icon name="arrow-right" /></a>
                </div>
            </article>
        </div>
    </section>

    @if ($lavaggioCategories->isNotEmpty())
        <section class="wl-categories" aria-labelledby="lavaggio-categories-title">
            <header class="wl-section-heading">
                <div><p class="wl-eyebrow">LAVAGGIO PONUDA</p><h2 id="lavaggio-categories-title">Sve za njegu rublja.</h2></div>
                <a href="{{ route('categories.index') }}">Cijela ponuda <x-fa-icon name="arrow-right" /></a>
            </header>
            <div class="wl-categories-grid">
                @foreach ($lavaggioCategories as $lavaggioCategory)
                    @php($lavaggioCategoryTranslation = $lavaggioCategory->translations->firstWhere('locale', $lavaggioLocale) ?? $lavaggioCategory->translations->first())
                    @if ($lavaggioCategoryTranslation?->slug)
                        <a class="wl-category-card" href="{{ route('categories.show', $lavaggioCategoryTranslation->slug) }}">
                            <span class="wl-category-copy"><span><strong>{{ $lavaggioCategoryTranslation->name }}</strong><small>{{ \Illuminate\Support\Str::limit(strip_tags((string) $lavaggioCategoryTranslation->description), 140) }}</small></span><x-fa-icon name="arrow-up-right" /></span>
                        </a>
                    @endif
                @endforeach
            </div>
        </section>
    @endif

    <section class="herrera-home-section herrera-b2b-products lavaggio-offer" aria-labelledby="lavaggio-offer-title">
        <div class="herrera-home-heading">
            <div><p class="herrera-home-section-eyebrow">LAVAGGIO WEBSHOP</p><h2 id="lavaggio-offer-title">{{ $lavaggioProducts->isEmpty() ? 'Ponuda je u pripremi.' : 'Novo u ponudi' }}</h2></div>
            @if ($lavaggioProducts->isNotEmpty())
                <a class="herrera-home-products-link" href="{{ route('shop.index') }}">Svi proizvodi <x-fa-icon name="arrow-right" /></a>
            @endif
        </div>
        @if ($lavaggioProducts->isEmpty())
            <p class="lavaggio-empty-copy">{{ $lavaggioSettings->get('lavaggio_home_empty_description', 'Pripremamo proizvode za čisto, svježe i njegovano rublje. Uskoro više.') }}</p>
        @else
            <div class="grid grid-cols-2 lg:grid-cols-4">
                @foreach ($lavaggioProducts as $lavaggioProduct)
                    <x-front.desktop.product-card :product="$lavaggioProduct" :locale="$lavaggioLocale" :fallback-locale="config('app.locale')" :lined="true" :flat="true" />
                @endforeach
            </div>
        @endif
    </section>
</div>
