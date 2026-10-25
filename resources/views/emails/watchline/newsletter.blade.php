@component('emails.watchline.layout', ['title' => 'Vaš osobni kod za '.($discount_percent ?? 10).'% popusta', 'preheader' => 'Hvala na prijavi. Vaš osobni kod za prvu kupnju nalazi se u poruci.'])
    <div class="center">
        <p class="eyebrow">Poklon dobrodošlice</p>
        <h1>Vaših {{ $discount_percent ?? 10 }}% za prvi odabir.</h1>
        <p class="intro">Hvala što ste se prijavili na Watchline newsletter. Za prvu kupnju darujemo vam dodatnih {{ $discount_percent ?? 10 }}% popusta.</p>
        <p class="coupon">{{ $coupon_code }}</p>
        <p class="text">Unesite svoj osobni kod u košarici ili na blagajni. Kod je vezan uz vašu e-mail adresu i može se iskoristiti jednom, uz prvu kupnju.</p>
        <p><a href="{{ $shop_url }}" class="cta">Pronađite svoj Watchline odabir</a></p>
        <p class="muted">Prvi ćete saznati za nove kolekcije, posebne ponude i inspiraciju za darivanje.</p>
    </div>
@endcomponent
