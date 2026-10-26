@extends('front.desktop.layouts.store')
@section('title', 'Tražiš poklon? – Watchline')
@push('styles')
    <link rel="stylesheet" href="{{ asset('front-theme/styles/category-catalog.css') }}?v={{ filemtime(public_path('front-theme/styles/category-catalog.css')) }}">
    <link rel="stylesheet" href="{{ asset('front-theme/styles/libermedia-catalog-promos.css') }}?v={{ filemtime(public_path('front-theme/styles/libermedia-catalog-promos.css')) }}">
    <link rel="stylesheet" href="{{ asset('front-theme/styles/watchline-gift-guide.css') }}?v={{ filemtime(public_path('front-theme/styles/watchline-gift-guide.css')) }}">
@endpush
@push('scripts')
    <script defer src="{{ asset('front-theme/scripts/watchline-gift-guide.js') }}?v={{ filemtime(public_path('front-theme/scripts/watchline-gift-guide.js')) }}"></script>
@endpush
@section('content')
<div class="wl-gift-guide" data-watchline-gift-guide>
    <header class="front-soft-hero lm-page-title wl-gift-title">
        <p class="wl-gift-eyebrow"><x-fa-icon name="gift" style="solid" /> POKLON ZA NJIHOV TRENUTAK</p>
        <h1>Tražiš poklon?</h1>
        <p>Pronađite sat, sunčane naočale ili nakit prema osobi kojoj darujete i svom budžetu.</p>
    </header>

    <section class="wl-gift-filter-panel" aria-labelledby="watchline-gift-form-title">
        <div class="wl-gift-filter-heading">
            <span class="wl-gift-filter-icon" aria-hidden="true"><x-fa-icon name="gift" style="solid" /></span>
            <div>
                <h2 id="watchline-gift-form-title">Pronađite pravi poklon</h2>
                <p>Odaberite osobu, vrstu poklona i budžet.</p>
            </div>
        </div>
        <form id="watchline-gift-filters" action="{{ route('gift-guide.index') }}#preporuke" method="GET" class="wl-gift-filters">
            <fieldset class="wl-gift-recipients"><legend>Kome darujete?</legend>
                <div class="wl-gift-choices">@foreach($recipientOptions as $value => $label)
                    <label><input type="radio" name="recipient" value="{{ $value }}" @checked($filters['recipient'] === $value)><span>{{ $label }}</span></label>
                @endforeach</div>
            </fieldset>
            <div class="wl-gift-fields">
                <label>Vrsta poklona<select name="type">@foreach($typeOptions as $value => $label)<option value="{{ $value }}" @selected($filters['type'] === $value)>{{ $label }}</option>@endforeach</select></label>
                <label>Budžet<select name="budget">@foreach($budgetOptions as $value => $label)<option value="{{ $value }}" @selected((string) $filters['budget'] === (string) $value)>{{ $label }}</option>@endforeach</select></label>
                <label>Brand<select name="brand"><option value="">Svi brandovi</option>@foreach($giftBrands as $brand)<option value="{{ $brand->id }}" @selected($filters['brand'] === $brand->id)>{{ ($brand->translations->firstWhere('locale', $locale) ?? $brand->translations->firstWhere('locale', $fallbackLocale))?->name ?? $brand->code }}</option>@endforeach</select></label>
            </div>
            <input type="hidden" name="cols" value="{{ $filters['cols'] }}">
            <div class="wl-gift-filter-actions"><button type="submit">Pronađi poklon <x-fa-icon name="arrow-right" style="solid" /></button><a href="{{ route('gift-guide.index') }}">Poništi odabir</a></div>
        </form>
    </section>

    <section id="preporuke" class="wl-gift-results" aria-labelledby="watchline-gift-results-title">
        <div class="wl-gift-results-heading"><div><p class="wl-gift-eyebrow">ODABRANO ZA DARIVANJE</p><h2 id="watchline-gift-results-title">Prijedlozi za vaš odabir</h2></div><p>{{ $products->total() }} artikala</p></div>
        @if($filters['recipient'] !== 'all')
            <p class="wl-gift-taxonomy-note">Za još više ideja odaberite „Za sve” i istražite cijelu ponudu.</p>
        @endif
        <div class="wl-gift-toolbar">
            <div class="wl-gift-switches">@foreach(['available_only' => 'Samo odmah dostupno', 'promo_only' => 'Samo na akciji'] as $key => $label)
                <label><input type="checkbox" name="{{ $key }}" value="1" form="watchline-gift-filters" data-gift-auto-submit @checked($filters[$key])><span>{{ $label }}</span></label>
            @endforeach</div>
            <div class="wl-gift-sort"><label class="sr-only" for="watchline-gift-sort">Redoslijed artikala</label><select id="watchline-gift-sort" name="sort" form="watchline-gift-filters" data-gift-auto-submit>
                @foreach(['default' => 'Najprodavanije prvo', 'newest' => 'Najnovije prvo', 'oldest' => 'Najstarije prvo', 'price_low' => 'Cijena: od niže prema višoj', 'price_high' => 'Cijena: od više prema nižoj', 'stock_high' => 'Dostupnost: od veće prema manjoj'] as $value => $label)<option value="{{ $value }}" @selected($filters['sort'] === $value)>{{ $label }}</option>@endforeach
            </select><noscript><button type="submit" form="watchline-gift-filters">Primijeni filtre</button></noscript>
                <div class="wl-gift-columns">@foreach([3 => 'table-cells-large', 4 => 'table-cells', 5 => 'grip'] as $cols => $icon)
                    <a href="{{ route('gift-guide.index', array_merge($filters, ['cols' => $cols])) }}#preporuke" @class(['is-active' => $filters['cols'] === $cols]) aria-label="{{ $cols }} artikala u redu" @if($filters['cols'] === $cols) aria-current="true" @endif><x-fa-icon :name="$icon" style="solid" /></a>
                @endforeach</div>
            </div>
        </div>

        @if($products->isNotEmpty())
            <div class="catalog-lined-grid wl-gift-product-grid wl-gift-product-grid--{{ $filters['cols'] }}" data-catalog-grid data-catalog-mobile-cols="2" data-continuous-card-grid>
                @foreach($products as $product)<x-front.desktop.product-card :product="$product" :locale="$locale" :fallback-locale="$fallbackLocale" :lined="true" :flat="true" :image-sizes="\App\Support\Media\WatchlineImageSizes::giftGuide($filters['cols'])" />@endforeach
                @if($filters['cols'] === 5 && $products->count() % 5 === 4 && config('gift_vouchers.enabled', true))
                    @include('front.partials.libermedia-promo-card', ['promoType' => 'gift-voucher'])
                @endif
            </div>
            <div class="wl-gift-pagination">{{ $products->links() }}</div>
        @else
            <div class="wl-gift-empty"><x-fa-icon name="gift" style="solid" /><h3>Pronađimo drugi dobar odabir.</h3><p>Za odabranu kombinaciju trenutačno nema artikala. Promijenite primatelja, vrstu poklona, brand ili budžet.</p><a href="{{ route('gift-guide.index') }}">Prikaži sve ideje za poklon</a></div>
        @endif
    </section>

</div>
@endsection
