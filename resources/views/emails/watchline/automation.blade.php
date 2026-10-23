<!doctype html>
<html lang="hr">
<head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>{{ $subject }}</title></head>
<body style="margin:0;background:#EDF6FC;font-family:Arial,sans-serif;color:#132A38">
<table role="presentation" width="100%" cellpadding="0" cellspacing="0"><tr><td align="center" style="padding:28px 12px">
    <table role="presentation" width="600" cellpadding="0" cellspacing="0" style="max-width:100%;background:#fff">
        <tr><td style="padding:24px;background:#132A38"><a href="{{ url('/') }}"><img src="{{ asset('assets/brand/watchline-logo-on-dark.svg') }}" alt="Watchline" width="180" style="display:block;max-width:100%;height:auto"></a></td></tr>
        <tr><td style="padding:28px">
            <h1 style="margin:0 0 20px;font-size:25px">{{ $heading }}</h1>
            <p style="line-height:1.65">{{ $intro }}</p>
            <ul style="padding-left:20px;line-height:1.8">
                @foreach ($products as $product)
                    <li><a href="{{ $product['url'] }}" style="color:#0077B5">{{ $product['name'] }}</a>@if (($product['quantity'] ?? 1) > 1) × {{ $product['quantity'] }}@endif</li>
                @endforeach
            </ul>
            @if ($coupon)
                <p style="line-height:1.65">Za artikle iz ove košarice pripremili smo {{ $coupon['discount'] }}% popusta uz kod <strong>{{ $coupon['code'] }}</strong>. Kod vrijedi do {{ $coupon['expires'] }} i može se iskoristiti jednom.</p>
            @endif
            <p style="margin:28px 0"><a href="{{ $action_url }}" style="display:inline-block;background:#0077B5;color:#fff;padding:14px 20px;text-decoration:none">{{ $action_label }}</a></p>
            <p style="font-size:12px;line-height:1.6;color:#52606B">Ne želite više ovakve poruke? <a href="{{ $unsubscribe_url }}" style="color:#0077B5">Odjavite se</a>.</p>
        </td></tr>
    </table>
</td></tr></table>
</body></html>
