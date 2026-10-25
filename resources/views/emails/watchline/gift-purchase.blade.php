@component('emails.watchline.layout', ['title' => 'Vaša narudžba poklon bona', 'preheader' => 'Hvala što darujete poseban trenutak.'])
    <p class="eyebrow">Vaš poklon bon</p>
    <h1>Hvala što darujete poseban trenutak.</h1>
    <p class="intro">Zaprimili smo narudžbu <strong>{{ $order->order_number }}</strong> za poklon bon vrijednosti <strong>{{ number_format((float) ($voucher?->initial_amount ?? $order->grand_total), 2, ',', '.') }} €</strong>.</p>
    @if ($voucher)<p>Primatelj: <strong>{{ $voucher->recipient_name ?: $voucher->recipient_email }}</strong>@if ($voucher->recipient_name) ({{ $voucher->recipient_email }})@endif.</p>@endif
    <p class="text">{{ $order->paid_at ? 'Uplata je potvrđena. Poklon bon šaljemo primatelju na navedenu e-mail adresu.' : 'Poklon bon šaljemo primatelju nakon potvrde uplate. Kod bona neće biti aktivan prije plaćanja.' }}</p>
    @if ($bankTransfer && !$order->paid_at)
        <div class="bank">
            <h2>Podaci za uplatu</h2>
            <p>{{ $bankTransfer['receiver_name'] ?? '' }}</p>
            <p>IBAN: <strong>{{ $bankTransfer['receiver_iban'] ?? '' }}</strong></p>
            <p>Iznos: <strong>{{ number_format((float) $order->grand_total, 2, ',', '.') }} €</strong></p>
            <p>Model: HR{{ preg_replace('/^HR/i', '', (string) ($bankTransfer['model'] ?? '00')) }}</p>
            <p>Poziv na broj: {{ $bankTransfer['reference'] ?? '' }}</p>
            @include('emails.watchline.bank-barcode', ['bankTransfer' => $bankTransfer, 'reference' => $order->order_number])
        </div>
    @endif
    <p><a href="{{ $purchaseUrl }}" class="cta">Pregledajte narudžbu bona</a></p>
    <p class="muted">Poveznica za pregled narudžbe vrijedi 30 dana. Kupnja digitalnog bona ne mijenja artikle koje imate u košarici.</p>
@endcomponent
