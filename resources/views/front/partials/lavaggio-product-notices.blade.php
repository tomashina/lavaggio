@php($lavaggioPresenter = app(\App\Support\LavaggioProductPresenter::class))
<div class="lavaggio-product-notices">
    <p data-product-delivery><x-fa-icon name="truck-fast" /><span>{{ $lavaggioPresenter->delivery($product) }}</span></p>
    <p data-product-free-shipping><x-fa-icon name="box" /><span>Besplatna dostava iznad {{ $lavaggioPresenter->money($lavaggioPresenter->freeShippingThreshold()) }} do 20 kg</span></p>
</div>
