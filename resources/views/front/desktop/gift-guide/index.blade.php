@extends('front.desktop.layouts.store')
@section('title', 'Tražiš poklon? – Liber Media')
@push('styles')
<link rel="stylesheet" href="{{ asset('front-theme/styles/category-catalog.css') }}?v={{ filemtime(public_path('front-theme/styles/category-catalog.css')) }}">
<link rel="stylesheet" href="{{ asset('front-theme/styles/libermedia-gift-guide.css') }}?v={{ filemtime(public_path('front-theme/styles/libermedia-gift-guide.css')) }}">
@endpush
@section('content')
<div class="lm-gift-guide">
    <header class="front-soft-hero lm-page-title px-4 py-4 text-center sm:px-6 sm:py-5">
        <p class="front-eyebrow lm-gift-title-eyebrow"><x-fa-icon name="gift" /> POKLON ZA MALE VELIKE IDEJE</p>
        <h1 id="gift-title" class="text-2xl font-extrabold tracking-tight text-slate-900">Tražiš poklon?</h1>
        <p>Odaberi dob, interes i budžet. Pronađi poklon za igru, maštu i male velike osmijehe.</p>
    </header>
    <section class="lm-gift-hero" aria-label="Odaberi poklon">
        <form id="gift-filter-form" method="GET" action="{{ route('gift-guide.index') }}#preporuke" class="lm-gift-form">
            <fieldset><legend>Kome poklanjaš?</legend><div class="lm-gift-choices">
                @foreach(['all' => 'Za svako dijete', 'girl' => 'Djevojčica', 'boy' => 'Dječak'] as $value => $label)
                    <label><input type="radio" name="gender" value="{{ $value }}" @checked(($filters['gender'] ?? 'all') === $value)><span>{{ $label }}</span></label>
                @endforeach
            </div></fieldset>
            <div class="lm-gift-fields">
                <label>Dob djeteta<select name="age"><option value="">Sve dobi</option>@foreach([6 => '6 mjeseci', 12 => '1 godina', 18 => '18 mjeseci', 24 => '2 godine', 36 => '3 godine', 48 => '4 godine', 60 => '5 godina', 72 => '6 godina', 84 => '7 godina', 96 => '8 godina', 120 => '10 godina', 144 => '12 godina'] as $value => $label)<option value="{{ $value }}" @selected(($filters['age'] ?? '') == $value)>{{ $label }}</option>@endforeach</select></label>
                <label>Budžet<select name="budget"><option value="">Bez ograničenja</option>@foreach([25,50,100] as $value)<option value="{{ $value }}" @selected(($filters['budget'] ?? '') == $value)>Do {{ $value }} €</option>@endforeach</select></label>
                <label class="lm-gift-interest">Interesi<select name="interest"><option value="">Sve male avanture</option>@foreach(['creative' => 'Crtanje i kreativnost', 'learning' => 'Slagalice i učenje', 'building' => 'Kocke i građenje', 'outdoor' => 'Igru na otvorenom'] as $value => $label)<option value="{{ $value }}" @selected(($filters['interest'] ?? '') === $value)>{{ $label }}</option>@endforeach</select></label>
            </div>
            <div class="lm-gift-form-actions"><button type="submit">Pronađi poklon <x-fa-icon name="arrow-right" /></button>
            <a class="lm-gift-reset" href="{{ route('gift-guide.index') }}">Poništi</a></div>
        </form>
    </section>
    <section id="preporuke" class="lm-gift-results" aria-labelledby="gift-results-title">
        <div class="lm-gift-results-heading"><div><p class="lm-gift-eyebrow">INSPIRACIJA ZA DARIVANJE</p><h2 id="gift-results-title">{{ count(array_filter($filters)) ? 'Pokloni prema tvom odabiru' : 'Ideje koje donose radost' }}</h2><p>Igračke i kreativni setovi za darivanje.</p></div><span>{{ $products->total() }} artikala</span></div>
        <div class="catalog-desktop-toolbar lm-gift-toolbar">
            <div class="catalog-desktop-toolbar-toggles">
                @foreach(['available_only' => 'Prikaži samo odmah dostupne', 'promo_only' => 'Prikaži samo proizvode na promociji'] as $key => $label)
                    <label class="catalog-toolbar-toggle"><span>{{ $label }}</span><span class="catalog-switch"><input type="checkbox" name="{{ $key }}" value="1" form="gift-filter-form" @checked(!empty($filters[$key])) onchange="this.form.requestSubmit()"><span class="catalog-switch-track" aria-hidden="true"></span></span></label>
                @endforeach
            </div>
            <div class="catalog-desktop-toolbar-actions">
                <select name="sort" form="gift-filter-form" aria-label="Redoslijed artikala" class="catalog-filter-select catalog-filter-inline-select" onchange="this.form.requestSubmit()">
                    @foreach(['default' => 'Zadani redoslijed', 'newest' => 'Najnovije prvo', 'oldest' => 'Najstarije prvo', 'price_low' => 'Cijena: od niže prema višoj', 'price_high' => 'Cijena: od više prema nižoj', 'stock_high' => 'Dostupnost: od veće prema manjoj'] as $value => $label)<option value="{{ $value }}" @selected(($filters['sort'] ?? 'default') === $value)>{{ $label }}</option>@endforeach
                </select>
                <input type="hidden" name="cols" value="{{ $filters['cols'] ?? 5 }}" form="gift-filter-form">
                @foreach([3 => 'table-cells-large', 4 => 'table-cells', 5 => 'grip'] as $cols => $icon)
                    <a href="{{ route('gift-guide.index', array_merge(request()->except('page'), ['cols' => $cols])) }}#preporuke" class="catalog-grid-toggle {{ $cols === 5 ? 'catalog-grid-toggle--five' : '' }} {{ (int)($filters['cols'] ?? 5) === $cols ? 'is-active' : '' }}" aria-label="{{ $cols }} artikala u redu"><x-fa-icon :name="$icon" class="h-4 w-4" /></a>
                @endforeach
            </div>
        </div>
        <div class="lm-gift-catalog-layout">
            <aside class="lm-gift-sidebar" aria-label="Dodatni filtri za poklone">
                <h3>Suzi izbor</h3>
                @foreach(['category' => ['Kategorija', $giftCategories], 'brand' => ['Brand', $giftBrands]] as $key => [$label, $options])
                    <label>{{ $label }}<select name="{{ $key }}" form="gift-filter-form" onchange="this.form.requestSubmit()"><option value="">{{ $key === 'category' ? 'Sve kategorije' : 'Svi brandovi' }}</option>@foreach($options as $option)<option value="{{ $option->id }}" @selected((int)($filters[$key] ?? 0) === $option->id)>{{ ($option->translations->firstWhere('locale', app()->getLocale()) ?? $option->translations->first())?->name ?? $option->code }}</option>@endforeach</select></label>
                @endforeach
                <p>Kategorije i brandovi prate tvoj odabir dobi, interesa i budžeta.</p>
            </aside>
            <div class="catalog-products-main">
        @if($products->isNotEmpty())
            <div class="catalog-lined-grid {{ match((int)($filters['cols'] ?? 5)) { 3 => 'grid grid-cols-2 lg:grid-cols-3', 4 => 'grid grid-cols-2 lg:grid-cols-3 xl:grid-cols-4', default => 'grid grid-cols-2 lg:grid-cols-3 xl:grid-cols-4 2xl:grid-cols-5' } }}" data-catalog-grid data-catalog-mobile-cols="2" data-continuous-card-grid>@foreach($products as $product)<x-front.desktop.product-card :product="$product" :locale="app()->getLocale()" :fallback-locale="config('app.locale')" :lined="true" :flat="true" />@endforeach</div>
            {{ $products->links() }}
        @else
            <div class="lm-gift-empty"><x-fa-icon name="gift" /><h3>Još tražimo pravi mali veliki poklon.</h3><p>Za ovaj odabir nema dostupnih artikala. Promijeni dob, interes ili budžet i istraži još ideja.</p><a href="{{ route('gift-guide.index') }}">Prikaži sve ideje za poklon</a></div>
        @endif
            </div>
        </div>
    </section>
    <aside class="lm-gift-voucher"><div><p class="lm-gift-eyebrow">KAD JE NAJLJEPŠE PREPUSTITI IZBOR</p><h2>Pokloni mogućnost izbora.</h2><p>Uz poklon bon, mali istraživači sami biraju svoju sljedeću avanturu.</p></div><a href="{{ route('gift-vouchers.create') }}"><x-fa-icon name="ticket" /> Odaberi poklon bon <x-fa-icon name="arrow-right" /></a></aside>
</div>
@endsection
