<!doctype html>
<html lang="hr">
<head>
    <meta charset="utf-8">
    <title>Preuzimanje {{ $order->order_number }}</title>
    <style>
        @page { margin: 7mm; }
        body { margin: 0; font: {{ $fontSize }}pt/1.35 "DejaVu Sans", sans-serif; }
        div { overflow-wrap: break-word; }
        .heading { border-bottom: 2pt solid #000; padding-bottom: 3mm; font-weight: bold; }
        .label { margin-top: 7mm; font-size: .9em; }
        .name { font-size: 2em; font-weight: bold; }
        .number { font-size: 1.6em; font-weight: bold; }
    </style>
</head>
<body>
    <div class="heading">{{ $order->shipping_method_name ?: 'Preuzimanje u poslovnici' }}</div>
    <div class="label">Paket preuzima</div>
    <div class="name">{{ trim($order->shipping_first_name.' '.$order->shipping_last_name) ?: ($order->customer_name ?: trim($order->billing_first_name.' '.$order->billing_last_name)) }}</div>
    @if ($order->shipping_company ?: $order->billing_company)<div>{{ $order->shipping_company ?: $order->billing_company }}</div>@endif
    @if (filled($order->customer_phone))
        <div class="label">Telefon kupca</div>
        <div class="number">{{ trim($order->customer_phone) }}</div>
    @endif
    <div class="label">Broj narudžbe</div>
    <div class="number">{{ $order->order_number }}</div>
</body>
</html>
