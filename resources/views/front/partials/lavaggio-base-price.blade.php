@php
    $reference = \App\Support\Lavaggio\RetailBasePrice::forProduct($product);
@endphp
@if ($reference)
    <p class="lavaggio-product-listing-price mt-1 text-xs text-slate-700">Bazna cijena na <time datetime="{{ $reference['date'] }}">{{ \Carbon\CarbonImmutable::parse($reference['date'])->format('d.m.Y.') }}</time> · {{ number_format($reference['price'], 2, ',', '.') }} €</p>
@else
    <p class="lavaggio-product-listing-price mt-1 text-xs text-slate-700">Bazna cijena u provjeri</p>
@endif
