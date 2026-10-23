@php($isGiftPromo = ($promoType ?? 'bundles') === 'gift-voucher')
<a href="{{ route($isGiftPromo ? 'gift-vouchers.create' : 'bundles.index') }}" class="lm-catalog-promo {{ $isGiftPromo ? 'lm-catalog-promo--gift' : 'lm-catalog-promo--bundles' }}" data-catalog-promo-card="{{ $isGiftPromo ? 'gift-voucher' : 'bundles' }}">
    <span class="lm-catalog-promo-icon"><x-fa-icon name="gift" style="light" /></span>
    <span class="lm-catalog-promo-eyebrow">{{ $isGiftPromo ? 'POKLON ZA MALE VELIKE IDEJE' : 'VIŠE IGRE, VIŠE UŠTEDE' }}</span>
    <strong class="lm-catalog-promo-title">{{ $isGiftPromo ? 'Poklon bon' : 'Kupi u kompletu i uštedi' }}</strong>
    <span class="lm-catalog-promo-description">{{ $isGiftPromo ? 'Darujte igru i veselje. Prepustite im izbor.' : 'Otkrijte odabrane komplete za igru i učenje.' }}</span>
    <span class="lm-catalog-promo-cta">{{ $isGiftPromo ? 'Odaberite poklon' : 'Pogledajte ponudu' }} <x-fa-icon name="arrow-right" /></span>
</a>
