@php($watchlineHome = app(\App\Support\WatchlineHomeContent::class))
<section class="lm-campaign-section" aria-labelledby="lm-campaigns-title" data-libermedia-campaigns>
    <header class="lm-section-heading"><div><p class="lm-eyebrow">WATCHLINE. VAŠ TRENUTAK.</p><h2 id="lm-campaigns-title">Pronađite svoj novi omiljeni detalj</h2></div></header>
    <div class="lm-campaign-grid">
        <article class="lm-campaign lm-campaign--bags">
            <div class="lm-campaign-copy">
                <p class="lm-eyebrow">ZA SVAKI DAN</p>
                <h3>Vrijeme za stil.<br>Vrijeme za vas.</h3>
                <p>Pronađite sat koji odgovara vašem stilu.</p>
                <a class="lm-button" href="{{ $watchlineHome->productUrl(0) }}">Istražite ponudu <x-fa-icon name="arrow-right" /></a>
            </div>
            <div class="lm-campaign-bag-images" aria-hidden="true">
                <img class="lm-bag lm-bag--back" src="{{ $watchlineHome->image(0) }}" alt="" width="1000" height="1000" loading="lazy">
                <img class="lm-bag lm-bag--front" src="{{ $watchlineHome->image(1) }}" alt="" width="1000" height="1000" loading="lazy">
            </div>
        </article>
        <article class="lm-campaign lm-campaign--meli">
            <div class="lm-campaign-copy">
                <p class="lm-eyebrow">ODABRANA PONUDA</p>
                <h3>Mali detalji.<br>Veliki dojam.</h3>
                <p>Istražite modele iz Watchline ponude.</p>
                <a class="lm-button" href="{{ $watchlineHome->productUrl(2) }}">Pogledajte modele <x-fa-icon name="arrow-right" /></a>
            </div>
            <img class="lm-campaign-product lm-meli-product" src="{{ $watchlineHome->image(2) }}" alt="Model iz Watchline ponude" width="1000" height="1000" loading="lazy">
        </article>
        <article class="lm-campaign lm-campaign--montessori">
            <div class="lm-campaign-copy">
                <p class="lm-eyebrow">ZA VAŠ TRENUTAK</p>
                <h3>Odaberite detalj<br>koji vas prati.</h3>
                <p>Ponuda za svakodnevne i posebne trenutke.</p>
                <a class="lm-button lm-button--green" href="{{ $watchlineHome->productUrl(3) }}">Pronađite svoj model <x-fa-icon name="arrow-right" /></a>
            </div>
            <img class="lm-campaign-product lm-montessori-product" src="{{ $watchlineHome->image(3) }}" alt="Model iz Watchline ponude" width="1024" height="768" loading="lazy">
        </article>
    </div>
</section>
