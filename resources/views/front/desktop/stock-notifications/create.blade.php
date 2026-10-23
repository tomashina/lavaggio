@extends('front.desktop.layouts.store')
@section('title', 'Obavijesti me o dostupnosti')
@section('content')
    @include('front.partials.stock-notification-assets')
    @php
        $stockNotifyProductId = (int) ($product?->id ?? request('product_id', 0));
        $stockNotifyBundleId = (int) ($bundle?->id ?? request('bundle_id', 0));
        $stockNotifyOptionId = (int) ($productOptionValue?->id ?? request('product_option_value_id', 0));
        $stockNotifyName = $targetName ?? $notificationName ?? ($bundle?->title ?? $product?->translations?->firstWhere('locale', app()->getLocale())?->name ?? $product?->code ?? '');
    @endphp
    <header class="lm-page-title">
        <a href="{{ route('shop.index') }}" class="stock-notify-back"><x-fa-icon name="arrow-left" /> Natrag u trgovinu</a>
            <span class="stock-notify-heading-icon" aria-hidden="true"><x-fa-icon name="bell" /></span>
            <p class="stock-notify-eyebrow">Ne propustite povratak u ponudu</p>
            <h1 id="stock-notify-page-title">Obavijesti me o dostupnosti</h1>
            <p>Ostavite e-mail i poslat ćemo vam obavijest kada ponovno bude dostupno.</p>
    </header>
    <section class="stock-notify-page" aria-labelledby="stock-notify-page-title">
        <div class="stock-notify-dialog-content">
            @if($stockNotifyName !== '')<p class="stock-notify-product">{{ $stockNotifyName }}</p>@endif
            @include('front.partials.stock-notification-form', ['stockNotifyFormId' => 'stock-notify-page'])
        </div>
    </section>
@endsection
