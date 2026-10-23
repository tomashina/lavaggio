@php($shippingBenefit = app(\App\Support\LibermediaShippingBenefit::class)->forCroatia())
<section class="lm-benefits" aria-label="Prednosti kupnje" data-libermedia-home-benefits>
    <a href="{{ route('pages.show', ['slug' => 'nacin-placanja-i-dostava'], false) }}"><x-fa-icon name="truck-fast" style="light" :inline="true" /><span><strong>{{ $shippingBenefit['title'] }}</strong><small>{{ $shippingBenefit['description'] }}</small></span></a>
    <a href="{{ route('pages.show', ['slug' => 'nacin-placanja-i-dostava'], false) }}"><x-fa-icon name="credit-card" style="light" :inline="true" /><span><strong>Sigurno plaćanje</strong><small>Kupujte jednostavno i bez brige</small></span></a>
    <div><x-fa-icon name="shield-check" style="light" :inline="true" /><span><strong>Zaštita kupca</strong><small>Od narudžbe do dostave</small></span></div>
    <div><x-fa-icon name="heart-pulse" style="light" :inline="true" /><span><strong>Zdravstvena provjerenost</strong><small>Držimo do sigurnosti vaše djece</small></span></div>
</section>
