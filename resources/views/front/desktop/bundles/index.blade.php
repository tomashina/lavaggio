@extends('front.desktop.layouts.store')
@section('title', 'Bundle artikli – Liber Media')
@section('main_class', 'lm-bundles-main')
@push('styles')
    <link rel="stylesheet" href="{{ asset('front-theme/styles/libermedia-bundles.css') }}?v={{ filemtime(public_path('front-theme/styles/libermedia-bundles.css')) }}">
    <script defer src="{{ asset('front-theme/scripts/libermedia-bundles.js') }}?v={{ filemtime(public_path('front-theme/scripts/libermedia-bundles.js')) }}"></script>
@endpush
@section('content')
<section class="lm-bundles-page">
    <header class="lm-bundles-header lm-page-title">
        <span class="lm-bundles-header-gift" aria-hidden="true"><x-fa-icon name="gift" style="light" /></span>
        <div>
            <p class="lm-bundles-eyebrow">Više igre, više radosti</p>
            <h1>Kupi u kompletu i uštedi</h1>
            <p>Kompleti koji idu zajedno — za male istraživače i velike osmijehe.</p>
        </div>
    </header>
    @if($errors->any())<div role="alert" class="lm-bundles-error">{{ $errors->first() }}</div>@endif
    <div class="lm-bundles-grid">
    @forelse($bundles as $bundle)
        @php
            $offer = $offers[$bundle->id];
        @endphp
        <article class="lm-bundle-card" id="bundle-{{ $bundle->id }}" data-bundle-card="{{ $bundle->id }}">
            <div class="lm-bundle-card-top">
                <span class="lm-bundle-gift-label"><x-fa-icon name="gift" style="light" /> Komplet za igru</span>
                @if($offer['can_view_prices'] && $offer['saving'] > 0)
                    <span class="lm-bundle-saving-badge">Ušteda {{ $offer['saving_percent'] }}%</span>
                @endif
            </div>
            <h2>{{ $bundle->title }}</h2>
            <ul class="lm-bundle-products" aria-label="Artikli u kompletu">
                @foreach($bundle->products as $product)
                    @php
                        $translation = $product->translations->firstWhere('locale', app()->getLocale()) ?? $product->translations->firstWhere('locale', 'hr');
                        $productName = $translation?->name ?? $product->sku;
                        $productUrl = $translation ? route('products.show', $translation->slug) : null;
                        $imageUrl = \App\Support\Media\LegacyCatalogImage::first($product, ['card_480w']);
                    @endphp
                    <li class="lm-bundle-product">
                        @if($productUrl)<a href="{{ $productUrl }}" class="lm-bundle-product-link">@else<div class="lm-bundle-product-link">@endif
                            <span class="lm-bundle-product-image">
                                @if($imageUrl)<img src="{{ $imageUrl }}" alt="{{ $productName }}" width="240" height="220" loading="lazy">@else<x-fa-icon name="gift" style="light" class="lm-bundle-image-placeholder" />@endif
                                <span class="lm-bundle-product-quantity">{{ $product->pivot->quantity }} kom.</span>
                            </span>
                            <span class="lm-bundle-product-name">{{ $productName }}</span>
                        @if($productUrl)</a>@else</div>@endif
                    </li>
                @endforeach
            </ul>
            <div class="lm-bundle-card-footer">
                @if($offer['can_view_prices'])
                    <div class="lm-bundle-price-row">
                        <div class="lm-bundle-price">
                            <span>Cijena kompleta</span>
                            <strong>{{ number_format($offer['total'], 2, ',', '.') }} <span>€</span></strong>
                            <small>s PDV-om</small>
                        </div>
                        @if($offer['saving'] > 0)
                            <div class="lm-bundle-price-comparison">
                                <span>Artikli pojedinačno</span>
                                <s>{{ number_format($offer['regular_total'], 2, ',', '.') }} €</s>
                                <strong>Ušteda {{ number_format($offer['saving'], 2, ',', '.') }} €</strong>
                            </div>
                        @endif
                    </div>
                @endif
                <div class="lm-bundle-meta">
                    <span class="lm-bundle-availability {{ $offer['can_purchase'] ? 'is-available' : 'is-unavailable' }}">{{ $offer['can_purchase'] ? 'Komplet je dostupan' : 'Komplet trenutno nije dostupan' }}</span>
                    @if($bundle->ends_at)<span class="lm-bundle-validity">Do {{ $bundle->ends_at->format('d.m.Y.') }}</span>@endif
                </div>
                @if($offer['can_purchase'])
                    <form method="post" action="{{ route('bundles.store', $bundle) }}" class="lm-bundle-purchase-controls" data-bundle-cart-form>
                        @csrf
                        <div class="lm-bundle-quantity-control">
                            <button type="button" data-bundle-quantity-dec aria-label="Smanji broj kompleta" disabled><x-fa-icon name="minus" /></button>
                            <input type="number" name="quantity" value="1" min="1" max="99" step="1" inputmode="numeric" required data-bundle-quantity aria-label="{{ 'Količina kompleta '.$bundle->title }}">
                            <button type="button" data-bundle-quantity-inc aria-label="Povećaj broj kompleta"><x-fa-icon name="plus" /></button>
                        </div>
                        <button type="submit" class="bundle-purchase-action lm-bundle-cart-button" aria-label="{{ 'Dodaj komplet '.$bundle->title.' u košaricu' }}" title="Dodaj komplet u košaricu">
                            <x-fa-icon name="bag-shopping" class="lm-bundle-cart-icon bag-shopping" />
                            <span class="sr-only">Dodaj komplet u košaricu</span>
                        </button>
                    </form>
                @else
                    @include('front.partials.stock-notification-button', ['notifyBundleId' => $bundle->id, 'notifyName' => $bundle->title])
                @endif
            </div>
        </article>
    @empty
        <div class="lm-bundles-empty"><x-fa-icon name="gift" style="light" /><h2>Pripremamo nove komplete</h2><p>Trenutno nema aktivnih bundle ponuda.</p><a href="{{ route('shop.index') }}">Istraži sve artikle <x-fa-icon name="arrow-right" /></a></div>
    @endforelse
    </div>
    <p class="lm-bundles-note"><x-fa-icon name="gift" style="light" /> Popust se automatski obračunava u košarici kada su u njoj svi artikli kompleta.</p>
    <div class="lm-bundles-pagination">{{ $bundles->links() }}</div>
</section>
@endsection
