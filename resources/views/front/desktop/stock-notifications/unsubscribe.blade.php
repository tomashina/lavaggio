@extends('front.desktop.layouts.store')
@section('title', 'Odjava obavijesti o dostupnosti')
@section('content')
    <header class="lm-page-title">
        <span class="stock-notify-heading-icon" aria-hidden="true"><x-fa-icon name="bell" /></span>
        <h1>Obavijest o dostupnosti</h1>
    </header>
    <section class="stock-notify-page">
        <div class="stock-notify-dialog-content">
            <p class="stock-notify-product">{{ $notification->displayName() }}</p>
            @if($notification->status === \App\Models\Catalog\StockNotification::CANCELLED)
                <p role="status">Prijava je odjavljena. Nećemo vam slati ovu obavijest.</p>
            @elseif($notification->status === \App\Models\Catalog\StockNotification::SENT)
                <p>Jednokratna obavijest već je poslana. Za ovu prijavu više nećete primati poruke.</p>
            @elseif($notification->status === \App\Models\Catalog\StockNotification::PROCESSING)
                <p>Obavijest se trenutačno šalje. Za ovu prijavu nećete primati dodatne poruke.</p>
            @else
                <p>Želite li odjaviti jednokratnu obavijest za ovaj artikl ili komplet?</p>
                <form method="post" action="{{ route('stock-notifications.cancel', ['token' => $notification->token]) }}">
                    @csrf
                    <button type="submit" class="stock-notify-button">Odjavi obavijest</button>
                </form>
            @endif
            <p><a href="{{ route('shop.index') }}" class="stock-notify-back">Natrag u trgovinu</a></p>
        </div>
    </section>
@endsection
