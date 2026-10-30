@php
    $isWatchlinePromo = config('storefront.home') === 'watchline';
    $isLavaggioPromo = config('storefront.home') === 'lavaggio';
    $isGiftPromo = $isWatchlinePromo || ($promoType ?? 'bundles') === 'gift-voucher';
@endphp
@if (! $isWatchlinePromo || config('gift_vouchers.enabled', true))
<a href="{{ route($isGiftPromo ? 'gift-vouchers.create' : 'bundles.index') }}" class="lm-catalog-promo {{ $isGiftPromo ? 'lm-catalog-promo--gift' : 'lm-catalog-promo--bundles' }}" data-catalog-promo-card="{{ $isGiftPromo ? 'gift-voucher' : 'bundles' }}">
    <span class="lm-catalog-promo-icon"><x-fa-icon name="gift" style="light" /></span>
    <span class="lm-catalog-promo-eyebrow">{{ $isWatchlinePromo ? 'POKLON PO NJIHOVOM IZBORU' : ($isGiftPromo ? 'POKLON ZA MALE VELIKE IDEJE' : ($isLavaggioPromo ? 'VIŠE NJEGE, VIŠE UŠTEDE' : 'VIŠE IGRE, VIŠE UŠTEDE')) }}</span>
    <strong class="lm-catalog-promo-title">{{ $isGiftPromo ? 'Poklon bon' : 'Kupi u kompletu i uštedi' }}</strong>
    <span class="lm-catalog-promo-description">{{ $isWatchlinePromo ? 'Darujte izbor satova, naočala i nakita.' : ($isGiftPromo ? 'Darujte igru i veselje. Prepustite im izbor.' : ($isLavaggioPromo ? 'Otkrijte odabrane komplete za pranje i njegu rublja.' : 'Otkrijte odabrane komplete za igru i učenje.')) }}</span>
    <span class="lm-catalog-promo-cta">{{ $isWatchlinePromo ? 'Odaberite poklon bon' : ($isGiftPromo ? 'Odaberite poklon' : 'Pogledajte ponudu') }} <x-fa-icon name="arrow-right" /></span>
</a>
@endif
