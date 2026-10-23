<!doctype html>
<html lang="hr"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>Poklon bon Liber Media</title></head>
<body style="margin:0;background:#eff4eb;color:#243b30;font-family:Arial,Helvetica,sans-serif">
<table role="presentation" width="100%" cellspacing="0" cellpadding="0"><tr><td align="center" style="padding:32px 16px">
    <table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="max-width:600px;border:1px solid #dbe5d7;border-radius:16px;background:#fff">
        <tr><td style="padding:28px 32px;border-bottom:1px solid #e5ebdf;font-size:26px;font-weight:bold;color:#245b42">Liber <span style="color:#ff4d00">Media</span></td></tr>
        <tr><td style="padding:32px;text-align:center">
            <p style="font-size:36px;margin:0 0 12px" aria-hidden="true">🎁</p>
            <p style="font-size:13px;letter-spacing:1px;font-weight:bold;color:#245b42">POKLON ZA IGRU I UČENJE</p>
            <h1 style="font-size:28px;line-height:1.3;margin:12px 0">{{ $voucher->recipient_name ? $voucher->recipient_name.', stigao je vaš poklon!' : 'Stigao je vaš poklon!' }}</h1>
            @if($voucher->sender_name)<p style="line-height:1.6;color:#576b5e">Od: <strong>{{ $voucher->sender_name }}</strong></p>@endif
            <div style="margin:24px 0;padding:28px 20px;border:1px solid #d8e4d2;border-radius:12px;background:#eff5e9">
                <p style="margin:0;font-size:14px">Poklon bon Liber Media</p>
                <p style="margin:12px 0;font-size:44px;font-weight:bold;color:#245b42">{{ number_format((float) $voucher->balance, 2, ',', '.') }} €</p>
                @if((float) $voucher->balance < (float) $voucher->initial_amount)<p style="font-size:12px">Preostali iznos · početna vrijednost {{ number_format((float) $voucher->initial_amount, 2, ',', '.') }} €</p>@endif
                <p style="margin:20px 0 0;font-size:12px;color:#576b5e">VAŠ KOD</p>
                <p style="margin:8px 0 0;font-size:22px;letter-spacing:2px;font-weight:bold;word-break:break-all">{{ $voucher->code }}</p>
                @if($voucher->expires_at)<p style="font-size:12px;color:#576b5e">Vrijedi do {{ $voucher->expires_at->format('d.m.Y.') }}</p>@endif
            </div>
            @if($voucher->message)<p style="padding:20px;border-radius:10px;background:#fff7ed;font-size:16px;line-height:1.7;white-space:pre-line">{{ $voucher->message }}</p>@endif
            <p style="font-size:15px;line-height:1.7;color:#52675a">Odaberite nešto za igru i učenje. U košarici ili na blagajni unesite kod u polje „Poklon bon”. Ako potrošite dio vrijednosti, ostatak ostaje na istom bonu.</p>
            <p style="margin:28px 0"><a href="{{ route('shop.index') }}" style="display:inline-block;padding:15px 24px;border-radius:8px;background:#d64200;color:#fff;text-decoration:none;font-weight:bold">Pronađi svoj poklon</a></p>
            <p style="font-size:12px;line-height:1.7;color:#6b7b70">Kod čuvajte kao novac i podijelite ga samo s osobom kojoj je poklon namijenjen. Bon se koristi za kupnju artikala, a nije ga moguće zamijeniti za gotovinu ili koristiti za kupnju drugog bona.</p>
        </td></tr>
        <tr><td style="padding:20px 32px;background:#eff4eb;border-radius:0 0 16px 16px;text-align:center;font-size:12px;line-height:1.7;color:#576b5e">Liber Media · Igra, učenje i mali veliki trenuci.</td></tr>
    </table>
</td></tr></table></body></html>
