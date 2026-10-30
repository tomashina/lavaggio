@if (\App\Support\Mail\StoreMailBrand::isLavaggio() && !empty($bankTransfer['epc_qr_image_base64']))
    @php
        $epcBinary = base64_decode((string) $bankTransfer['epc_qr_image_base64'], true);
        $epcMime = (string) ($bankTransfer['epc_qr_image_mime'] ?? 'image/png');
        $epcCid = null;
        if ($epcBinary !== false && $epcBinary !== '' && isset($message) && method_exists($message, 'embedData')) {
            $epcCid = $message->embedData($epcBinary, 'sepa-qr-'.preg_replace('/[^a-zA-Z0-9_-]/', '-', (string) ($reference ?? 'narudzba')).'.png', $epcMime);
        }
    @endphp
    @if ($epcBinary !== false && $epcBinary !== '')
        <div class="bank-qr">
            <p><strong>{{ !empty($bankTransfer['preview_only']) ? 'Ogledni QR kod · nije za uplatu' : 'QR kod za uplatu (SEPA)' }}</strong></p>
            @if (!empty($bankTransfer['qr_preview_only']) || !empty($bankTransfer['preview_only']))
                <p class="muted">OGLEDNI PRIKAZ · NIJE ZA UPLATU</p>
            @else
                <p>Skenirajte kod u mobilnom bankarstvu koje podržava SEPA QR kod.</p>
            @endif
            <img src="{{ $epcCid ?: 'data:'.$epcMime.';base64,'.$bankTransfer['epc_qr_image_base64'] }}" alt="QR kod za uplatu" width="200" style="width:200px;max-width:100%;height:auto;">
        </div>
    @endif
@endif
@php
    $barcodeBinary = base64_decode((string) ($bankTransfer['qr_image_base64'] ?? ''), true);
    $barcodeMime = (string) ($bankTransfer['qr_image_mime'] ?? 'image/png');
    $barcodeCid = null;
    if ($barcodeBinary !== false && $barcodeBinary !== '' && isset($message) && method_exists($message, 'embedData')) {
        $barcodeFilename = 'uplata-'.preg_replace('/[^a-zA-Z0-9_-]/', '-', (string) ($reference ?? 'narudzba')).'.png';
        $barcodeCid = $message->embedData($barcodeBinary, $barcodeFilename, $barcodeMime);
    }
@endphp
@if ($barcodeBinary !== false && $barcodeBinary !== '')
    <div class="bank-qr">
        <p><strong>2D kod za uplatu</strong></p>
        @if (!empty($bankTransfer['qr_preview_only']) || !empty($bankTransfer['preview_only']))
            <p class="muted">OGLEDNI PRIKAZ · NIJE ZA UPLATU</p>
        @else
            <p>Skenirajte kod mobilnim bankarstvom.</p>
        @endif
        <img src="{{ $barcodeCid ?: 'data:'.$barcodeMime.';base64,'.$bankTransfer['qr_image_base64'] }}" alt="2D kod za uplatu">
    </div>
@endif
