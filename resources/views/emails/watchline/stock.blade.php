@component('emails.watchline.layout', ['title' => 'Vaš artikl ponovno je dostupan', 'preheader' => $targetName.' ponovno je u ponudi.'])
    <p class="eyebrow">Dobre vijesti</p>
    <h1>{{ $isBundle ? 'Vaš komplet ponovno je dostupan.' : 'Vaš artikl ponovno je dostupan.' }}</h1>
    <div class="panel"><strong>{{ $targetName }}</strong></div>
    <p class="intro">Zatražili ste da vam javimo kada se vrati u ponudu. Sada ga možete ponovno pogledati i dodati u košaricu.</p>
    <p><a href="{{ $targetUrl }}" class="cta">{{ $isBundle ? 'Pogledajte komplet' : 'Pogledajte artikl' }}</a></p>
    <p class="muted">Dostupnost se može promijeniti. Ova obavijest ne rezervira artikl.</p>
    <p class="muted">Ovo je jednokratna obavijest na vaš zahtjev. Vašu adresu ne dodajemo na newsletter. <a href="{{ $unsubscribeUrl }}">Odjava obavijesti</a>.</p>
@endcomponent
