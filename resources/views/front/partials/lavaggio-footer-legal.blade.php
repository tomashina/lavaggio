@php
    $lavaggioFooterLegalSettings = app(\App\Services\Settings\SystemSettingsService::class);
    $lavaggioFooterLegalTitle = (string) $lavaggioFooterLegalSettings->get('lavaggio_footer_legal_title', 'Uvjeti');
    $lavaggioFooterLegalLinks = collect($lavaggioFooterLegalSettings->get('lavaggio_footer_legal_links', []))
        ->filter(fn ($link) => is_array($link) && filled($link['label'] ?? '') && filled($link['url'] ?? ''));
@endphp
@if ($lavaggioFooterLegalLinks->isNotEmpty())
    <nav class="lavaggio-footer-legal" aria-labelledby="lavaggio-footer-legal-title">
        <h3 id="lavaggio-footer-legal-title">{{ $lavaggioFooterLegalTitle }}</h3>
        <ul>
            @foreach ($lavaggioFooterLegalLinks as $link)
                <li><a href="{{ $link['url'] }}">{{ $link['label'] }}</a></li>
            @endforeach
        </ul>
    </nav>
@endif
