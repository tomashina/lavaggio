<!doctype html>
<html lang="hr">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex,nofollow,noarchive">
    <title>Pregled e-mail poruka | Watchline</title>
    <link rel="icon" href="{{ asset('assets/brand/watchline-favicon.svg') }}" type="image/svg+xml">
    <link rel="stylesheet" href="{{ asset('front-theme/styles/watchline-mail-preview.css') }}?v={{ filemtime(public_path('front-theme/styles/watchline-mail-preview.css')) }}">
    <script src="{{ asset('front-theme/scripts/watchline-mail-preview.js') }}?v={{ filemtime(public_path('front-theme/scripts/watchline-mail-preview.js')) }}" defer></script>
</head>
<body>
    <header class="preview-header">
        <a href="{{ url('/') }}" aria-label="Watchline početna"><img src="{{ asset('assets/brand/watchline-logo.svg') }}" alt="Watchline" width="140" height="68"></a>
        <div><h1>Pregled e-mail poruka</h1><p>Samo ogledni podaci. Poruke se ne šalju.</p></div>
        <a class="preview-back" href="{{ url('/') }}">Povratak u trgovinu</a>
    </header>
    <main data-mail-preview>
        <section class="preview-controls" aria-label="Odabir pregleda">
            <div class="preview-select">
                <label for="mail-sample">Poruka</label>
                <select id="mail-sample" data-mail-sample>
                    @foreach (collect($samples)->groupBy('group', true) as $group => $groupSamples)
                        <optgroup label="{{ $group }}">
                            @foreach ($groupSamples as $key => $sample)
                                <option value="{{ $key }}" data-url="{{ route('preview.watchline-mail.show', $key) }}" data-description="{{ $sample['description'] }}" @selected($selected === $key)>{{ $sample['label'] }}</option>
                            @endforeach
                        </optgroup>
                    @endforeach
                </select>
            </div>
            <div class="preview-width" role="group" aria-label="Širina poruke">
                <button type="button" data-mail-width="desktop" aria-pressed="true">Računalo</button>
                <button type="button" data-mail-width="mobile" aria-pressed="false">Mobitel · 375 px</button>
            </div>
            <a class="preview-open" data-mail-open href="{{ route('preview.watchline-mail.show', $selected) }}" target="_blank" rel="noopener">Otvori poruku zasebno ↗</a>
        </section>
        <p class="preview-description" data-mail-description aria-live="polite">{{ $samples[$selected]['description'] }}</p>
        <div class="preview-stage" data-mail-stage>
            <iframe data-mail-frame src="{{ route('preview.watchline-mail.show', $selected) }}" title="{{ $samples[$selected]['label'] }}" sandbox="" referrerpolicy="no-referrer"></iframe>
        </div>
        <p class="preview-note">Prikaz koristi stvarne predloške trgovine. Imena, adrese, narudžbe i kodovi su izmišljeni; poveznice ne izvršavaju kupnju ili potvrdu računa. Izgled u pojedinim e-mail aplikacijama može se razlikovati.</p>
    </main>
</body>
</html>
