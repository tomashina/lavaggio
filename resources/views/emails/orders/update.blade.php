@php
    $sharedDesign = \App\Support\Mail\StoreMailBrand::usesSharedDesign();
@endphp
@component($sharedDesign ? 'emails.watchline.layout' : 'emails.orders.update-layout', ['title' => $mailSubject, 'preheader' => $details['message'], 'storeName' => $details['store_name']])
    <p class="eyebrow">Narudžba {{ $details['order_number'] }}</p>
    <h1>{{ $details['type'] === 'tracking' ? 'Podaci za praćenje pošiljke' : 'Promjena statusa narudžbe' }}</h1>
    <p>Poštovani {{ $details['customer_name'] }},</p>
    <p class="intro">{{ $details['message'] }}</p>
    <div class="panel">
        <strong>Narudžba:</strong> {{ $details['order_number'] }}<br>
        <strong>Status:</strong> {{ $details['status_name'] }}
        @if (!empty($details['status_description']))
            <p><strong>{{ $details['status_description'] }}</strong></p>
        @endif
        @if ($details['tracking'])
            <br><strong>Dostavna služba:</strong> {{ $details['tracking']['carrier'] }}
            <br><strong>Broj za praćenje:</strong> {{ $details['tracking']['number'] }}
        @endif
    </div>
    @if ($details['tracking'])
        <p><a href="{{ $details['tracking']['url'] }}" class="cta">Prati pošiljku</a></p>
        @if ($details['type'] === 'tracking')
            <p class="muted">Broj pošiljke je dodijeljen. Praćenje će se ažurirati kada dostavna služba preuzme i obradi paket.</p>
        @endif
    @endif
    @if ($details['order_url'])
        <p><a href="{{ $details['order_url'] }}">Pregledaj narudžbu u svom računu</a></p>
    @endif
    <p>Hvala na povjerenju!<br>{{ $details['store_name'] }}</p>
@endcomponent
