<!doctype html>
<html lang="hr">
<head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>{{ $title }}</title></head>
<body style="margin:0;background:#f5f5fa;font:15px/1.7 Arial,Helvetica,sans-serif;color:#13100d">
    <table role="presentation" width="100%" cellspacing="0" cellpadding="0"><tr><td align="center" style="padding:24px 12px">
        <table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="max-width:640px;background:#fff;border:1px solid #e4e5f0">
            <tr><td style="padding:24px;font-size:24px;font-weight:bold">{{ $storeName }}</td></tr>
            <tr><td style="padding:24px">{{ $slot }}</td></tr>
        </table>
    </td></tr></table>
</body>
</html>
