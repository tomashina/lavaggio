@php($watchlineHome = app(\App\Support\WatchlineHomeContent::class))
<section class="lm-benefits" aria-label="Prednosti kupnje" data-libermedia-home-benefits>
    <a href="{{ $watchlineHome->informationUrl('dostav') }}"><x-fa-icon name="truck-fast" style="light" :inline="true" /><span><strong>Dostava i preuzimanje</strong><small>Informacije o vašoj narudžbi</small></span></a>
    <a href="{{ $watchlineHome->informationUrl('plaćanj') }}"><x-fa-icon name="credit-card" style="light" :inline="true" /><span><strong>Načini plaćanja</strong><small>Odaberite kako želite platiti</small></span></a>
    <div><x-fa-icon name="shield-check" style="light" :inline="true" /><span><strong>Podrška pri kupnji</strong><small>Od odabira do narudžbe</small></span></div>
    <div><x-fa-icon name="heart-pulse" style="light" :inline="true" /><span><strong>Tu smo za vas</strong><small>Javite nam se za više informacija</small></span></div>
</section>
