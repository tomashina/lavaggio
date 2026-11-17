<!DOCTYPE html>
<html lang="hr">
<head>
    <meta charset="utf-8">
    <title>Adresna naljepnica {{ $orderNumber }}</title>
    <style>
        @page { margin: 5.5mm; }
        body { margin: 0; font-family: "DejaVu Sans", sans-serif; font-size: {{ $fontSize }}pt; line-height: 1.25; color: #000; }
        div { overflow-wrap: break-word; word-wrap: break-word; }
        .eyebrow { font-size: 0.75em; font-weight: bold; letter-spacing: 0.6pt; }
        .service { font-size: 0.9em; padding-bottom: 2mm; border-bottom: 1.5pt solid #000; }
        .sender { margin-top: 3mm; font-size: 0.9em; }
        .name { font-weight: bold; }
        .recipient { margin-top: 4mm; padding-top: 3mm; border-top: 0.6pt solid #000; }
        .recipient-name { font-size: 1.45em; font-weight: bold; margin-top: 1.5mm; }
        .contact { font-size: 0.9em; margin-top: 2mm; }
        .destination { margin-top: 3mm; padding: 2.5mm; border: 1pt solid #000; }
        .address { font-size: 1.15em; margin-top: 1mm; }
        .postal { font-size: 1.3em; font-weight: bold; }
        .point-id { font-size: 0.85em; margin-bottom: 2mm; }
        .order { margin-top: 3mm; font-size: 0.95em; }
        .footer { margin-top: 3mm; padding-top: 2mm; border-top: 0.6pt solid #000; font-size: 0.75em; }
    </style>
</head>
<body>
    <div class="eyebrow">ADRESNA NALJEPNICA</div>
    <div class="service">{{ $serviceName }}</div>
    <div class="sender">
        <div class="eyebrow">POŠILJATELJ</div>
        <div class="name">{{ $sender['name'] }}</div>
        @if ($sender['contact_name'] !== '' && $sender['contact_name'] !== $sender['name'])<div>{{ $sender['contact_name'] }}</div>@endif
        <div>{{ $sender['street'] }}</div>
        @if ($sender['address_line_2'] !== '')<div>{{ $sender['address_line_2'] }}</div>@endif
        <div>{{ $sender['postal_code'] }} {{ $sender['city'] }}, {{ strtoupper($sender['country_code']) }}</div>
        @if ($sender['contact_phone'] !== '')<div>Tel: {{ $sender['contact_phone'] }}</div>@endif
    </div>
    <div class="recipient">
        <div class="eyebrow">PRIMATELJ</div>
        <div class="recipient-name">{{ $recipient['name'] }}</div>
        @if ($recipient['company'] !== '')<div>{{ $recipient['company'] }}</div>@endif
        <div class="contact">
            @if ($recipient['phone'] !== '')<div>Tel: {{ $recipient['phone'] }}</div>@endif
            @if ($recipient['email'] !== '')<div>{{ $recipient['email'] }}</div>@endif
        </div>
    </div>
    <div class="destination">
        <div class="eyebrow">{{ $isLocker ? 'ODABRANA GLS LOKACIJA' : 'ADRESA DOSTAVE' }}</div>
        @if ($isLocker)
            <div class="name">{{ $destination['name'] }}</div>
            <div class="point-id">ID: {{ $destination['id'] }}</div>
        @endif
        <div class="address">{{ $destination['street'] }}</div>
        @if ($destination['address_line_2'] !== '')<div>{{ $destination['address_line_2'] }}</div>@endif
        <div class="postal">{{ $destination['postal_code'] }} {{ $destination['city'] }}</div>
        <div>{{ strtoupper($destination['country_code']) }}</div>
    </div>
    <div class="order">Narudžba: <strong>{{ $orderNumber }}</strong></div>
    <div class="footer">Za ručnu predaju paketa. Službenu transportnu naljepnicu izdaje GLS.<br>Ispis 100 % - papir 100 × 150 mm.</div>
</body>
</html>
