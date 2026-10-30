@if (\App\Support\Mail\StoreMailBrand::usesSharedDesign())
    @include('emails.watchline/stock')
@else
<!doctype html>
<html lang="hr">
<head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>Ponovno dostupno | Liber Media</title></head>
<body style="margin:0;background:#f4f6f2;font-family:Arial,Helvetica,sans-serif;color:#243b33">
    <table role="presentation" width="100%" cellspacing="0" cellpadding="0"><tr><td align="center" style="padding:32px 16px">
        <table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="max-width:600px;background:#fff;border:1px solid #dce5db;border-radius:16px">
            <tr><td style="padding:28px 32px;border-bottom:1px solid #e8eee5;font-size:26px;font-weight:bold;color:#245b42">Liber <span style="color:#ff4d00">Media</span></td></tr>
            <tr><td style="padding:32px">
                <p style="color:#245b42;font-size:13px;letter-spacing:1px;font-weight:bold">DOBRE VIJESTI!</p>
                <h1 style="margin:12px 0;font-size:28px;line-height:1.25">{{ $isBundle ? 'Vaš komplet ponovno je dostupan.' : 'Vaš artikl ponovno je dostupan.' }}</h1>
                <p style="font-size:18px;line-height:1.5;font-weight:bold">{{ $targetName }}</p>
                <p style="font-size:16px;line-height:1.6;color:#52675c">Zatražili ste da vam javimo kada se vrati u ponudu. Sada ga možete ponovno pogledati i dodati u košaricu.</p>
                <p style="margin:28px 0"><a href="{{ $targetUrl }}" style="display:inline-block;padding:15px 24px;border-radius:8px;background:#d64200;color:#fff;text-decoration:none;font-weight:bold">{{ $isBundle ? 'Pogledaj komplet' : 'Pogledaj artikl' }}</a></p>
                <p style="font-size:13px;line-height:1.6;color:#66796e">Dostupnost se može promijeniti. Ova obavijest ne rezervira artikl.</p>
            </td></tr>
            <tr><td style="padding:22px 32px;background:#edf3e9;font-size:12px;line-height:1.7;color:#52675c;border-radius:0 0 16px 16px">
                Ovo je jednokratna obavijest na vaš zahtjev. Vašu adresu ne dodajemo na newsletter.<br>
                <a href="{{ $unsubscribeUrl }}" style="color:#245b42">Odjava obavijesti</a> &middot; <a href="{{ route('home') }}" style="color:#245b42">Liber Media</a>
            </td></tr>
        </table>
    </td></tr></table>
</body></html>

@endif
