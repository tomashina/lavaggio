@component('emails.watchline.layout', ['title' => $subject, 'preheader' => $intro])
    <p class="eyebrow">Vaš {{ \App\Support\Mail\StoreMailBrand::name() }} odabir</p>
    <h1>{{ $heading }}</h1>
    <p class="intro">{{ $intro }}</p>
    <div class="panel">
        @foreach ($products as $product)
            <p style="margin:6px 0;"><a href="{{ $product['url'] }}">{{ $product['name'] }}</a>@if (($product['quantity'] ?? 1) > 1) × {{ $product['quantity'] }}@endif</p>
        @endforeach
    </div>
    @if ($coupon)
        <p class="text">Za artikle iz ove košarice pripremili smo {{ $coupon['discount'] }}% popusta.</p>
        <p class="coupon">{{ $coupon['code'] }}</p>
        <p class="muted">Kod vrijedi do {{ $coupon['expires'] }} i može se iskoristiti jednom.</p>
    @endif
    <p><a href="{{ $action_url }}" class="cta">{{ $action_label }}</a></p>
    <p class="muted">Ne želite više ovakve poruke? <a href="{{ $unsubscribe_url }}">Odjavite se</a>.</p>
@endcomponent
