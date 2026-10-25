<!doctype html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $title ?? 'Watchline' }}</title>
    <style>
        @media only screen and (max-width:620px) {
            .wl-mail-content { padding:24px 18px !important; }
            .wl-mail-frame { padding:12px 6px !important; }
            .items thead { display:none !important; }
            .items tr { display:block !important; padding:12px 0 !important; border-bottom:1px solid #dce8ef; }
            .items td { display:block !important; border:0 !important; padding:3px 0 !important; }
            .items .num { text-align:left !important; }
            .mobile-label { display:inline !important; color:#64748b; }
            .details th,.label { width:34% !important; }
        }
    </style>
</head>
<body style="margin:0;padding:0;background:#f4f8fb;color:#111111;font-family:Arial,Helvetica,sans-serif;font-size:15px;line-height:1.7;">
<div style="display:none;max-height:0;overflow:hidden;opacity:0;mso-hide:all;">{{ $preheader ?? $title ?? 'Watchline · Satovi, naočale i nakit.' }}</div>
<table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="width:100%;background:#f4f8fb;">
    <tr><td align="center" class="wl-mail-frame" style="padding:28px 12px;">
        <table role="presentation" width="640" cellspacing="0" cellpadding="0" style="width:100%;max-width:640px;background:#ffffff;border:1px solid #dce8ef;border-top:4px solid #0077b5;">
            <tr><td align="center" style="padding:20px 24px;background:#ffffff;border-bottom:1px solid #dce8ef;">
                <a href="{{ url('/') }}" style="text-decoration:none;"><img src="{{ asset('assets/brand/watchline-mail-logo.png') }}" alt="Watchline" width="160" height="78" style="display:block;width:160px;max-width:100%;height:auto;border:0;"></a>
            </td></tr>
            <tr><td class="wl-mail-content" style="padding:32px;color:#111111;font-family:Arial,Helvetica,sans-serif;font-size:15px;line-height:1.7;">
                {!! \App\Support\Mail\WatchlineMailDesign::content((string) $slot) !!}
            </td></tr>
            <tr><td align="center" style="padding:20px 24px;background:#edf6fc;border-top:1px solid #dce8ef;font-size:12px;line-height:1.8;color:#52616c;">
                <a href="{{ url('/') }}" style="color:#0077b5;font-weight:bold;text-decoration:none;">Watchline</a> · Satovi, naočale i nakit.<br>
                @isset($footer){{ $footer }}@else{{ now()->year }} · Hvala na povjerenju.@endisset
            </td></tr>
        </table>
    </td></tr>
</table>
</body>
</html>
