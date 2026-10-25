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
        @if (!empty($bankTransfer['qr_preview_only']))
            <p class="muted">OGLEDNI PRIKAZ · NIJE ZA UPLATU</p>
        @else
            <p>Skenirajte kod mobilnim bankarstvom.</p>
        @endif
        <img src="{{ $barcodeCid ?: 'data:'.$barcodeMime.';base64,'.$bankTransfer['qr_image_base64'] }}" alt="2D kod za uplatu">
    </div>
@endif
