@php($mailBrand = \App\Support\Mail\StoreMailBrand::details())
<!doctype html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $title ?? $mailBrand['name'] }}</title>
    <style>
        @media only screen and (max-width:620px) {
            .wl-mail-content { padding:24px 18px !important; }
            .wl-mail-frame { padding:12px 6px !important; }
            .items thead { display:none !important; }
            .items tr { display:block !important; padding:12px 0 !important; border-bottom:1px solid {{ $mailBrand['line'] }}; }
            .items td { display:block !important; border:0 !important; padding:3px 0 !important; }
            .items .num { text-align:left !important; }
            .mobile-label { display:inline !important; color:{{ $mailBrand['muted'] }}; }
            .details th,.label { width:34% !important; }
        }
    </style>
</head>
<body style="margin:0;padding:0;background:{{ $mailBrand['paper'] }};color:{{ $mailBrand['ink'] }};font-family:Arial,Helvetica,sans-serif;font-size:15px;line-height:1.7;">
<div style="display:none;max-height:0;overflow:hidden;opacity:0;mso-hide:all;">{{ $preheader ?? $title ?? $mailBrand['name'].' · '.$mailBrand['tagline'] }}</div>
<table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="width:100%;background:{{ $mailBrand['paper'] }};">
    <tr><td align="center" class="wl-mail-frame" style="padding:28px 12px;">
        <table role="presentation" width="640" cellspacing="0" cellpadding="0" style="width:100%;max-width:640px;background:#ffffff;border:1px solid {{ $mailBrand['line'] }};border-top:4px solid {{ $mailBrand['accent'] }};border-radius:{{ \App\Support\Mail\StoreMailBrand::isLavaggio() ? 16 : 0 }}px;">
            <tr><td align="center" style="padding:{{ \App\Support\Mail\StoreMailBrand::isLavaggio() ? 28 : 20 }}px 24px;background:#ffffff;border-bottom:1px solid {{ $mailBrand['line'] }};border-radius:{{ \App\Support\Mail\StoreMailBrand::isLavaggio() ? '12px 12px 0 0' : '0' }};">
                <a href="{{ url('/') }}" style="text-decoration:none;"><img src="{{ $mailBrand['logo'] }}" alt="{{ $mailBrand['name'] }}" width="{{ $mailBrand['width'] }}" height="{{ $mailBrand['height'] }}" style="display:block;width:{{ $mailBrand['width'] }}px;max-width:100%;height:auto;border:0;"></a>
            </td></tr>
            <tr><td class="wl-mail-content" style="padding:32px;color:{{ $mailBrand['ink'] }};font-family:Arial,Helvetica,sans-serif;font-size:15px;line-height:1.7;">
                {!! \App\Support\Mail\WatchlineMailDesign::content((string) $slot) !!}
            </td></tr>
            <tr><td align="center" style="padding:{{ \App\Support\Mail\StoreMailBrand::isLavaggio() ? 24 : 20 }}px 24px;background:{{ $mailBrand['panel'] }};border-top:1px solid {{ $mailBrand['line'] }};border-radius:{{ \App\Support\Mail\StoreMailBrand::isLavaggio() ? '0 0 12px 12px' : '0' }};font-size:12px;line-height:1.8;color:{{ $mailBrand['muted'] }};">
                <a href="{{ url('/') }}" style="color:{{ $mailBrand['link'] }};font-weight:bold;text-decoration:none;">{{ $mailBrand['name'] }}</a> · {{ $mailBrand['tagline'] }}<br>
                @if ($mailBrand['email'])<a href="mailto:{{ $mailBrand['email'] }}" style="color:{{ $mailBrand['link'] }};text-decoration:none;">{{ $mailBrand['email'] }}</a>@endif
                @if ($mailBrand['email'] && $mailBrand['phone']) · @endif
                @if ($mailBrand['phone']){{ $mailBrand['phone'] }}@endif
                @if ($mailBrand['email'] || $mailBrand['phone'])<br>@endif
                @if ($mailBrand['address'])<span style="white-space:pre-line;">{{ $mailBrand['address'] }}</span><br>@endif
                @isset($footer){{ $footer }}@else{{ now()->year }} · Hvala na povjerenju.@endisset
            </td></tr>
        </table>
    </td></tr>
</table>
</body>
</html>
