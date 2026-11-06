@php
    $lavaggioFooterLegalSettings = app(\App\Services\Settings\SystemSettingsService::class);
    $lavaggioFooterLegalTitle = (string) $lavaggioFooterLegalSettings->get('lavaggio_footer_legal_title', 'Uvjeti');
    $lavaggioFooterLegalLinks = collect($lavaggioFooterLegalSettings->get('lavaggio_footer_legal_links', []))
        ->filter(fn ($link) => is_array($link) && filled($link['label'] ?? '') && filled($link['url'] ?? ''))
        ->reject(fn ($link) => str_starts_with(mb_strtolower(trim((string) $link['label'])), 'zakonsko jamstvo'))
        ->map(function ($link) {
            if (in_array(parse_url($link['url'], PHP_URL_PATH), ['/page/raskid-ugovora-i-prigovor', '/stranica/raskid-ugovora-i-prigovor'], true)) {
                return ['label' => 'Raskid ugovora', 'url' => route('returns.create', ['returnRequestSlug' => __('return_request.slug')], false)];
            }

            return $link;
        });
    $lavaggioWithdrawalUrl = route('returns.create', ['returnRequestSlug' => __('return_request.slug')], false);
    if (! $lavaggioFooterLegalLinks->contains(fn ($link) => parse_url($link['url'], PHP_URL_PATH) === $lavaggioWithdrawalUrl)) {
        $lavaggioFooterLegalLinks->push(['label' => 'Raskid ugovora', 'url' => $lavaggioWithdrawalUrl]);
    }
    $lavaggioFooterLegalLinks->push([
        'label' => 'Zakonsko jamstvo',
        'url' => $storeSettings['legal_warranty']['asset_url'],
        'warranty' => true,
    ]);
@endphp
@if ($lavaggioFooterLegalLinks->isNotEmpty())
    <nav class="lavaggio-footer-legal" aria-labelledby="lavaggio-footer-legal-title">
        <h3 id="lavaggio-footer-legal-title">{{ $lavaggioFooterLegalTitle }}</h3>
        <ul>
            @foreach ($lavaggioFooterLegalLinks as $link)
                <li><a href="{{ $link['url'] }}" @if ($link['warranty'] ?? false) target="_blank" rel="noopener noreferrer" data-legal-warranty-open aria-controls="legal-warranty-modal" aria-haspopup="dialog" @endif>{{ $link['label'] }}</a></li>
            @endforeach
        </ul>
    </nav>
@endif
