<div hidden data-libermedia-catalog-promo-templates>
    @if (config('storefront.home') !== 'watchline')
        <template data-catalog-promo-template="bundles">
            @include('front.partials.libermedia-promo-card', ['promoType' => 'bundles'])
        </template>
    @endif
    @if(config('gift_vouchers.enabled', true))
        <template data-catalog-promo-template="gift-voucher">
            @include('front.partials.libermedia-promo-card', ['promoType' => 'gift-voucher'])
        </template>
    @endif
</div>
