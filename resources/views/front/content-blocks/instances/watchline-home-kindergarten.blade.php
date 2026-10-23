@php($watchlineHome = app(\App\Support\WatchlineHomeContent::class))
<section class="lm-kindergarten" aria-labelledby="lm-kindergarten-title" data-libermedia-kindergarten>
    <img class="lm-kindergarten-photo" src="{{ $watchlineHome->image(4) }}" alt="Watchline ponuda" width="750" height="550" loading="lazy">
    <div class="lm-kindergarten-copy">
        <p class="lm-eyebrow">DETALJI KOJI PRATE VAŠ STIL</p>
        <h2 id="lm-kindergarten-title">Pronađite svoj<br>sljedeći model</h2>
        <p>Pregledajte proizvode i brandove iz naše ponude. Odaberite model koji prati vaš ritam i upotpunjuje vaš stil.</p>
        <p>Trebate dodatne informacije? Obratite nam se za pomoć pri odabiru.</p>
        <a class="lm-button" href="{{ route('contact.create', absolute: false) }}">Saznajte više <x-fa-icon name="arrow-up-right" /></a>
    </div>
    <img class="lm-kindergarten-photo lm-kindergarten-photo--outdoor" src="{{ $watchlineHome->image(5) }}" alt="Watchline ponuda" width="750" height="550" loading="lazy">
</section>
