@component('emails.watchline.layout', ['title' => 'Stigao je vaš Watchline poklon bon!', 'preheader' => 'Netko je za vas odabrao poseban poklon.'])
    <div class="center">
        <p class="eyebrow">Poklon po vašem izboru</p>
        <h1>{{ $voucher->recipient_name ? $voucher->recipient_name.', stigao je vaš poklon!' : 'Stigao je vaš poklon!' }}</h1>
        @if ($voucher->sender_name)<p class="intro">Od: <strong>{{ $voucher->sender_name }}</strong></p>@endif
        <div class="panel">
            <p>Poklon bon Watchline</p>
            <p style="margin:12px 0;font-size:40px;font-weight:bold;color:#0077b5;">{{ number_format((float) $voucher->balance, 2, ',', '.') }} €</p>
            @if ((float) $voucher->balance < (float) $voucher->initial_amount)<p class="muted">Preostali iznos · početna vrijednost {{ number_format((float) $voucher->initial_amount, 2, ',', '.') }} €</p>@endif
            <p class="eyebrow">Vaš kod</p>
            <p style="font-size:22px;font-weight:bold;letter-spacing:2px;word-break:break-all;">{{ $voucher->code }}</p>
            @if ($voucher->expires_at)<p class="muted">Vrijedi do {{ $voucher->expires_at->format('d.m.Y.') }}</p>@endif
        </div>
        @if ($voucher->message)<div class="panel pre-line">{{ $voucher->message }}</div>@endif
        <p class="intro">Odaberite svoj omiljeni sat, nakit ili sunčane naočale. U košarici ili na blagajni unesite kod u polje „Poklon bon”. Ako potrošite dio vrijednosti, ostatak ostaje na istom bonu.</p>
        <p><a href="{{ route('shop.index') }}" class="cta">Pronađite svoj poklon</a></p>
        <p class="muted">Kod čuvajte kao novac i podijelite ga samo s osobom kojoj je poklon namijenjen. Bon nije moguće zamijeniti za gotovinu ili koristiti za kupnju drugog bona.</p>
    </div>
@endcomponent
