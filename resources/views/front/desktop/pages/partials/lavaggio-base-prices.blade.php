@php
    $publication = app(\App\Support\Lavaggio\PublishedPriceLists::class);
    $archive = $publication->archive();
    $firaUrl = \App\Services\Integrations\Fira\FiraSettingsService::PRICE_LIST_URL;
@endphp
<section class="lavaggio-content-article bg-white px-2 py-2" aria-label="Cjenici robe i usluga">
    @if (trim(strip_tags($pageBody)) !== '')
        <div class="content-richtext mb-6">{!! app(\App\Support\WatchlineArticleImages::class)->render($pageBody) !!}</div>
    @endif
    <p>Bareda d.o.o. · Barban 70, 52207 Barban · OIB 96889385802. Cijene su iskazane u eurima s PDV-om.</p>
    <ul class="my-5 space-y-2">
        @foreach (['roba' => 'Cjenik robe', 'usluge' => 'Cjenik usluga praonice'] as $kind => $label)
            <li><strong>{{ $label }}</strong>
                @foreach (['csv', 'xml'] as $format)
                    <a class="ml-3 underline" href="{{ route('lavaggio.price-list', [$kind, $format]) }}" aria-label="{{ $label }}, {{ strtoupper($format) }}">{{ strtoupper($format) }}</a>
                @endforeach
            </li>
        @endforeach
    </ul>
    <h2>Arhiva cjenika</h2>
    <p>Prethodna verzija dostupna je najmanje 15 dana od zamjene.</p>
    @foreach ($archive as $entry)
        <p class="mt-2">{{ $entry['date'] }} · {{ $entry['kind'] === 'roba' ? 'Roba' : 'Usluge' }} · verzija {{ $entry['id'] }}
            @foreach (['csv', 'xml'] as $format)
                <a class="ml-3 underline" href="{{ route('lavaggio.price-list.archive', [$entry['kind'], $entry['id'], $format]) }}">{{ strtoupper($format) }}</a>
            @endforeach
        </p>
    @endforeach
    <p class="mt-4"><a class="underline" href="{{ $firaUrl }}#arhiva">Ranije objave u FIRA arhivi</a> · <a class="underline" href="{{ $firaUrl }}/cjenik.pdf">FIRA cjenik (PDF)</a></p>
</section>
