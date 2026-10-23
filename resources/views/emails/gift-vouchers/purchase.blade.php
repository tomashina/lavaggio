<!doctype html>
<html lang="hr"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>Vaša narudžba poklon bona</title></head>
<body style="margin:0;background:#eff4eb;color:#243b30;font-family:Arial,Helvetica,sans-serif">
<table role="presentation" width="100%" cellspacing="0" cellpadding="0"><tr><td align="center" style="padding:32px 16px">
    <table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="max-width:600px;border:1px solid #dbe5d7;border-radius:16px;background:#fff">
        <tr><td style="padding:28px 32px;border-bottom:1px solid #e5ebdf;font-size:26px;font-weight:bold;color:#245b42">Liber <span style="color:#ff4d00">Media</span></td></tr>
        <tr><td style="padding:32px">
            <p style="font-size:13px;font-weight:bold;color:#245b42">VAŠ POKLON BON</p>
            <h1 style="font-size:27px;line-height:1.3;margin:12px 0">Hvala što poklanjate igru i učenje.</h1>
            <p style="line-height:1.7">Zaprimili smo narudžbu <strong>{{ $order->order_number }}</strong> za poklon bon vrijednosti <strong>{{ number_format((float) ($voucher?->initial_amount ?? $order->grand_total), 2, ',', '.') }} €</strong>.</p>
            @if($voucher)<p style="line-height:1.7">Primatelj: <strong>{{ $voucher->recipient_name ?: $voucher->recipient_email }}</strong>@if($voucher->recipient_name) ({{ $voucher->recipient_email }})@endif.</p>@endif
            <p style="line-height:1.7;color:#52675a">{{ $order->paid_at ? 'Uplata je potvrđena. Poklon bon šaljemo primatelju na navedenu e-mail adresu.' : 'Poklon bon šaljemo primatelju nakon potvrde uplate. Kod bona neće biti aktivan prije plaćanja.' }}</p>
            @if($bankTransfer && !$order->paid_at)
                <div style="padding:20px;margin:24px 0;border:1px solid #d8e4d2;border-radius:10px;background:#eff5e9;line-height:1.8">
                    <strong>Podaci za uplatu</strong><br>
                    {{ $bankTransfer['receiver_name'] ?? '' }}<br>
                    IBAN: <strong>{{ $bankTransfer['receiver_iban'] ?? '' }}</strong><br>
                    Iznos: <strong>{{ number_format((float) $order->grand_total, 2, ',', '.') }} €</strong><br>
                    Model: HR{{ preg_replace('/^HR/i', '', (string) ($bankTransfer['model'] ?? '00')) }}<br>
                    Poziv na broj: {{ $bankTransfer['reference'] ?? '' }}
                </div>
            @endif
            <p style="margin:28px 0"><a href="{{ $purchaseUrl }}" style="display:inline-block;padding:15px 24px;border-radius:8px;background:#d64200;color:#fff;text-decoration:none;font-weight:bold">Pregledaj narudžbu bona</a></p>
            <p style="font-size:12px;line-height:1.7;color:#6b7b70">Poveznica za pregled narudžbe vrijedi 30 dana. Kupnja digitalnog bona ne mijenja artikle koje imate u košarici.</p>
        </td></tr>
    </table>
</td></tr></table></body></html>
