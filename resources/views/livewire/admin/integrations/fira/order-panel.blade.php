@php
    $isOffer = ($document->document_type ?? $documentType) === 'PONUDA';
    $previewIsOffer = ($preview['invoiceType'] ?? $documentType) === 'PONUDA';
    $previewStale = $preview && $preview['invoiceType'] !== $documentType;
@endphp
<div class="admin-panel space-y-4 p-6">
    <h2 class="admin-section-title">FIRA ponude i računi</h2>
    <p class="text-sm text-slate-600">{{ $isOffer ? 'Ručna izrada ponude za provjeru, bez fiskalizacije' : 'Ručna izrada fiskalnog računa · slijed 1-web-1' }}</p>
    <p class="text-xs text-slate-500">Pregled podržava potrošačke narudžbe iz naplate trgovine. Popuste na cijelu košaricu, naknade plaćanja, poklon bonove, poslovne i ručno unesene narudžbe obradite u FIRA-i. Ako se zbroj stavki i PDV-a zbog zaokruživanja razlikuje od narudžbe, slanje se zaustavlja radi ručne provjere.</p>
    @if ($document)
        @if ($document->state === 'created')
            <p class="text-sm font-semibold">{{ $isOffer ? 'FIRA ponuda' : 'FIRA račun' }} {{ $document->invoice_number ?: $document->invoice_id }} {{ $isOffer ? 'je izrađena.' : 'je izrađen.' }}</p>
            @if ($isOffer)
                <p class="text-sm text-slate-600">Ponuda se ne fiskalizira. Po potrebi je pretvorite u račun izravno u FIRA-i odabirom „Napravi račun iz ponude” ili „Napravi fiskalni račun iz ponude”. Ponovno slanje ove narudžbe iz trgovine je blokirano radi zaštite od duplikata.</p>
            @else
                <p class="text-sm text-slate-600">{{ $document->has_jir ? 'Fiskalizacija potvrđena (JIR zaprimljen).' : 'JIR nije potvrđen. Provjerite status fiskalizacije u FIRA-i.' }}</p>
            @endif
        @else
            <p class="text-sm text-amber-800">Postoji pokušaj izrade bez potvrđenog ishoda. Provjerite ovu narudžbu u FIRA-i. Ponovno slanje je blokirano radi zaštite od duplikata.</p>
        @endif
    @else
        @if ($blockers)
            <ul class="list-disc pl-5 text-sm text-amber-800">@foreach ($blockers as $blocker)<li>{{ $blocker }}</li>@endforeach</ul>
        @endif
        @if ($isOffer)
            <p class="text-sm text-slate-600">Ponuda se sprema u vaš stvarni FIRA račun. Slanje ponude kupcu e-poštom određuju FIRA postavke; za probu provjerite da je slanje e-pošte za ponude isključeno.</p>
        @endif
        <div>
            <label for="fira-payment-{{ $orderId }}" class="mb-1 block text-sm font-semibold">Način plaćanja dokumenta</label>
            <select id="fira-payment-{{ $orderId }}" wire:model.live="paymentType" class="rounded-xl border border-slate-300 px-3 py-2">
                <option value="">Odaberite</option><option value="TRANSAKCIJSKI">Bankovna transakcija</option><option value="KARTICA">Kartica</option><option value="GOTOVINA">Gotovina</option>
            </select>
        </div>
        <button type="button" wire:click="previewInvoice" class="rounded-xl border border-slate-300 px-4 py-2 text-sm font-semibold">{{ $isOffer ? 'Pregledaj ponudu bez slanja' : 'Pregledaj račun bez slanja' }}</button>
        @if ($preview)
            <div class="rounded-xl border border-slate-200 p-4 text-sm">
                <p class="font-semibold">{{ $previewIsOffer ? 'Ponuda — bez fiskalizacije' : 'Fiskalni račun — slanje na fiskalizaciju' }}</p>
                <p>{{ $preview['webshopOrderNumber'] }} · {{ $preview['billingAddress']['name'] }} · {{ number_format($preview['brutto'], 2, ',', '.') }} €</p>
                <p>PDV: {{ number_format($preview['taxValue'], 2, ',', '.') }} € · Plaćanje: {{ $paymentType }}</p>
                @if (isset($preview['prepaidAmount']))<p>Plaćeno unaprijed: {{ number_format($preview['prepaidAmount'], 2, ',', '.') }} €</p>@endif
                <ul class="mt-2 list-disc pl-5">@foreach ($preview['lineItems'] as $line)<li>{{ $line['name'] }} × {{ $line['quantity'] }} · {{ number_format($line['price'], 2, ',', '.') }} € bez PDV-a</li>@endforeach</ul>
            </div>
            @if ($previewStale)
                <p class="text-sm text-amber-800">Vrsta dokumenta je promijenjena. Ponovno otvorite pregled prije izrade.</p>
            @elseif (! $blockers)
                <label class="flex items-start gap-2 text-sm"><input type="checkbox" wire:model="confirmed" class="mt-1" />{{ $isOffer ? 'Potvrđujem podatke i želim izraditi ponudu u FIRA-i, bez fiskalizacije.' : 'Potvrđujem podatke i želim izraditi fiskalni račun. FIRA ga šalje na fiskalizaciju.' }}</label>
                <button type="button" wire:click="issueInvoice" wire:confirm="{{ $isOffer ? 'Izraditi ponudu u FIRA-i, bez fiskalizacije?' : 'Izraditi fiskalni račun u FIRA-i i poslati ga na fiskalizaciju?' }}" wire:loading.attr="disabled" class="rounded-xl bg-slate-900 px-4 py-2 text-sm font-semibold text-white">{{ $isOffer ? 'Izradi ponudu u FIRA-i' : 'Izradi fiskalni račun u FIRA-i' }}</button>
            @endif
        @endif
    @endif
    @error('fira') <p class="text-sm text-rose-600">{{ $message }}</p> @enderror
    @error('confirmed') <p class="text-sm text-rose-600">Potvrdite podatke prije izrade dokumenta.</p> @enderror
    @if (auth()->user()->isA('superadmin') || auth()->user()->can('settings.api.manage'))
        <a href="{{ route('admin.settings.api.fira') }}" class="inline-block text-sm underline">FIRA postavke</a>
    @endif
</div>
