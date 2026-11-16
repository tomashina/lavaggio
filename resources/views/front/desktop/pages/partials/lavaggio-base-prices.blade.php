@php($priceListUrl = \App\Services\Integrations\Fira\FiraSettingsService::PRICE_LIST_URL)
@php($archiveEntries = app(\App\Services\Integrations\Fira\FiraPriceArchiveService::class)->entries())
<section class="lavaggio-content-article bg-white px-2 py-2" aria-label="Cjenik i arhiva cijena">
    @if (trim(strip_tags($pageBody)) !== '')
        <div class="content-richtext mb-6">{!! app(\App\Support\WatchlineArticleImages::class)->render($pageBody) !!}</div>
    @endif
    <p class="mb-5 text-slate-700">Pregledajte aktualni cjenik i prethodno objavljene cijene. Cjenik i arhiva ažuriraju se izravno putem FIRA-e.</p>
    <nav class="mb-6 flex flex-wrap gap-4" aria-label="Preuzimanje cjenika">
        <a class="font-semibold underline underline-offset-4" href="{{ $priceListUrl }}" target="_blank" rel="noopener noreferrer">Otvori cjenik i arhivu</a>
        <a class="font-semibold underline underline-offset-4" href="{{ $priceListUrl }}/cjenik.pdf" target="_blank" rel="noopener noreferrer">Cjenik (PDF)</a>
        <a class="font-semibold underline underline-offset-4" href="{{ $priceListUrl }}/cjenik.csv">Preuzmi cjenik (CSV)</a>
    </nav>
    <h2 class="mb-4 text-xl font-semibold">Arhiva cjenika</h2>
    @if ($archiveEntries !== [])
        <ul class="divide-y divide-slate-200 rounded-xl border border-slate-200 px-5">
            @foreach ($archiveEntries as $entry)
                <li class="flex flex-wrap items-center justify-between gap-3 py-4">
                    <span>{{ $entry['date'] }}</span>
                    <a class="font-semibold underline underline-offset-4" href="{{ $entry['url'] }}" aria-label="Preuzmi CSV cjenik od {{ $entry['date'] }}">Preuzmi CSV</a>
                </li>
            @endforeach
        </ul>
        <p class="mt-3 text-sm text-slate-500">Arhiva se osvježava iz FIRA-e. Datoteke su dostupne 30 dana od objave.</p>
    @else
        <p class="text-slate-600">Za pregled dostupnih datuma <a class="font-semibold underline" href="{{ $priceListUrl }}#arhiva" target="_blank" rel="noopener noreferrer">otvorite arhivu cjenika u FIRA-i</a>.</p>
    @endif
</section>
