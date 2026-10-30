@component('emails.watchline.layout', ['title' => $heading, 'preheader' => $intro])
    <p class="eyebrow">Vaš {{ \App\Support\Mail\StoreMailBrand::name() }} račun</p>
    <h1>{{ $heading }}</h1>
    <p class="intro">{{ $intro }}</p>
    <p><a href="{{ $actionUrl }}" class="cta">{{ $actionText }}</a></p>
    @if (!empty($expiryMinutes))<p class="text">Poveznica vrijedi {{ $expiryMinutes }} minuta.</p>@endif
    <p class="muted">{{ !empty($isVerification) ? 'Ako niste otvorili ovaj korisnički račun, zanemarite ovu poruku.' : 'Ako niste zatražili novu lozinku, zanemarite ovu poruku. Vaša postojeća lozinka ostaje važeća.' }}</p>
    <p class="muted">Ako se gumb ne otvara, kopirajte ovu poveznicu u preglednik:<br><a href="{{ $actionUrl }}" style="word-break:break-all;">{{ $actionUrl }}</a></p>
@endcomponent
