@php($watchlineHome = app(\App\Support\WatchlineHomeContent::class))
<section class="lm-assurance" aria-labelledby="lm-assurance-title" data-libermedia-assurance>
    <div class="lm-assurance-heading">
        <span class="lm-assurance-seal"><img src="/assets/brand/libermedia-guarantee.svg" alt="" width="48" height="48" aria-hidden="true"></span>
        <p class="lm-eyebrow">WATCHLINE</p>
        <h2 id="lm-assurance-title">Vaš izbor. Naša podrška.</h2>
        <p>Istražite <strong>satove, nakit i dodatke</strong> iz naše ponude. Za dodatne informacije o proizvodima, narudžbama, dostavi ili povratu javite se našoj korisničkoj podršci.</p>
    </div>
    <div class="lm-assurance-points">
        <div><x-fa-icon name="heart" style="regular" /><h3>Odabrana ponuda</h3><p>Pronađite detalj za svoj stil.</p></div>
        <div><x-fa-icon name="comments" style="regular" /><h3>Tu smo za vas</h3><p>Javite nam se za pomoć pri odabiru.</p></div>
        <div><x-fa-icon name="rotate-left" style="regular" /><h3>Informacije o povratu</h3><p>Saznajte više prije kupnje.</p><a href="{{ $watchlineHome->informationUrl('uvjet') }}">Uvjeti kupnje <x-fa-icon name="arrow-right" /></a></div>
    </div>
</section>
