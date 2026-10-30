@component('emails.watchline.layout', ['title' => 'Vaš Lavaggio kod za popust', 'preheader' => 'Hvala na prijavi. Vaš kod za popust nalazi se u poruci.'])
    <div class="center">
        <p class="eyebrow">Lavaggio newsletter</p>
        <h1>Svježina u vašem inboxu.</h1>
        <p class="intro">Hvala na prijavi na Lavaggio newsletter. Kao zahvalu šaljemo vam kod za {{ $discount_percent ?? 10 }}% popusta.</p>
        <p class="coupon">{{ $coupon_code }}</p>
        <p class="text">Kod unesite u košarici prije završetka narudžbe.</p>
        <p><a href="{{ $shop_url }}" class="cta">Istražite njegu rublja</a></p>
        <p class="muted">Ovu ste poruku primili nakon prijave na Lavaggio newsletter.</p>
    </div>
@endcomponent
