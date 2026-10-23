<section class="lm-campaign-section" aria-labelledby="lm-campaigns-title" data-libermedia-campaigns>
    <header class="lm-section-heading"><div><p class="lm-eyebrow">MALI SVIJET. VELIKE MOGUĆNOSTI.</p><h2 id="lm-campaigns-title">Pronađite svoju novu avanturu</h2></div></header>
    <div class="lm-campaign-grid">
        <article class="lm-campaign lm-campaign--bags">
            <div class="lm-campaign-copy">
                <p class="lm-eyebrow">ZA SVAKI ŠKOLSKI DAN</p>
                <h3>Velike avanture<br>počinju s dobrom torbom.</h3>
                <p>Školske torbe i ruksaci za sve male osobnosti.</p>
                <a class="lm-button" href="{{ route('categories.show', ['slug' => 'skolske-torbe-i-ruksaci'], false) }}">Pronađi svoju torbu <x-fa-icon name="arrow-right" /></a>
            </div>
            <div class="lm-campaign-bag-images" aria-hidden="true">
                <img class="lm-bag lm-bag--back" src="/assets/libermedia/home/schoolbag-jurassic.webp" alt="" width="1000" height="1000" loading="lazy">
                <img class="lm-bag lm-bag--front" src="/assets/libermedia/home/schoolbag-unicorn.webp" alt="" width="1000" height="1000" loading="lazy">
            </div>
        </article>
        <article class="lm-campaign lm-campaign--meli">
            <div class="lm-campaign-copy">
                <p class="lm-eyebrow">MELI KOCKE</p>
                <h3>Male kocke.<br>Bezbroj velikih ideja.</h3>
                <p>Spoji, rastavi i izmisli nešto sasvim novo.</p>
                <a class="lm-button" href="{{ route('manufacturers.show', ['slug' => 'ame-group-marcin-hernas'], false) }}">Istraži Meli <x-fa-icon name="arrow-right" /></a>
            </div>
            <img class="lm-campaign-product lm-meli-product" src="/assets/libermedia/home/meli-model-pink.webp" alt="Model složen od Meli kocki" width="1000" height="1000" loading="lazy">
        </article>
        <article class="lm-campaign lm-campaign--montessori">
            <div class="lm-campaign-copy">
                <p class="lm-eyebrow">MONTESSORI IGRE</p>
                <h3>Učim svojim<br>malim koracima.</h3>
                <p>Otkrijte igre za znatiželjne prstiće i glavice.</p>
                <a class="lm-button lm-button--green" href="{{ route('categories.show', ['slug' => 'didakticke-igre-za-djecu'], false) }}">Otkrij igre <x-fa-icon name="arrow-right" /></a>
            </div>
            <img class="lm-campaign-product lm-montessori-product" src="/assets/libermedia/home/montessori-play.webp" alt="Drvena umetaljka s geometrijskim oblicima u bojama" width="1024" height="768" loading="lazy">
        </article>
    </div>
</section>
