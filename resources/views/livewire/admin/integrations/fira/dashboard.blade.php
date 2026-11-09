<div class="admin-panel space-y-5 p-6">
    <div>
        <h1 class="text-2xl font-semibold text-slate-900">FIRA ponude i računi</h1>
        <p class="mt-2 text-sm text-slate-600">Dokument izradite iz pojedine narudžbe. Za fiskalne račune možete zasebno uključiti automatsku izradu nakon potvrđene kartične uplate. Spremanje postavki ne šalje dokumente za postojeće narudžbe.</p>
    </div>
    <div class="rounded-xl border border-amber-300 bg-amber-50 p-4 text-sm text-amber-900">
        Za provjeru odaberite ponudu, koja se ne fiskalizira. Fiskalni račun smije se uključiti tek nakon prijave prodajnog mjesta Poreznoj i dovršene FIRA konfiguracije.
    </div>
    <p class="text-sm text-slate-700">Web trgovina: <strong>lavaggio.hr</strong> · Slijed fiskalnih računa: <strong>1-web-1</strong></p>
    <p class="text-sm text-slate-600">Ponuda se sprema u vaš stvarni FIRA račun. Slanje ponude kupcu e-poštom određuju FIRA postavke; za probu isključite slanje e-pošte za ponude. Ponudu po potrebi pretvorite u račun izravno u FIRA-i. Trgovina istu narudžbu ne šalje drugi put.</p>
    <form wire:submit="save" class="space-y-4">
        <div>
            <label for="fira-document-type" class="mb-1 block text-sm font-semibold">Vrsta dokumenta</label>
            <select id="fira-document-type" wire:model="form.document_type" class="w-full max-w-xl rounded-xl border border-slate-300 px-3 py-2">
                <option value="PONUDA">Ponuda za provjeru — bez fiskalizacije</option>
                <option value="FISKALNI_RAČUN">Fiskalni račun — slanje na fiskalizaciju</option>
            </select>
            @error('document_type') <p class="text-sm text-rose-600">{{ $message }}</p> @enderror
        </div>
        <div>
            <label for="fira-secret" class="mb-1 block text-sm font-semibold">Tajni ključ</label>
            <input id="fira-secret" type="password" wire:model="secret" autocomplete="new-password" class="w-full max-w-xl rounded-xl border border-slate-300 px-3 py-2" />
            <p class="mt-1 text-xs text-slate-500">{{ $secretConfigured ? 'Ključ je spremljen šifrirano. Prazno polje čuva postojeći ključ.' : 'Upišite ključ Custom integracije iz FIRA postavki.' }}</p>
            @error('secret') <p class="text-sm text-rose-600">{{ $message }}</p> @enderror
        </div>
        <label class="flex items-start gap-2 text-sm"><input type="checkbox" wire:model="form.point_of_sale_registered" class="mt-1" />Za fiskalne račune: potvrđujem da je prodajno mjesto prijavljeno Poreznoj.</label>
        <label class="flex items-start gap-2 text-sm"><input type="checkbox" wire:model="form.fira_sequence_confirmed" class="mt-1" />Za fiskalne račune: potvrđujem da je u FIRA-i postavljen slijed 1-web-1 i dovršena fiskalna konfiguracija.</label>
        <p class="text-xs text-slate-500">Ove dvije potvrde nisu potrebne za ponude. Slijed fiskalnih računa 1-web-1 bira se u FIRA postavkama integracije lavaggioshop za odgovarajuće načine plaćanja.</p>
        <label class="flex items-start gap-2 text-sm font-semibold"><input type="checkbox" wire:model="form.send_enabled" class="mt-1" />Uključi izradu odabrane vrste dokumenta iz narudžbe</label>
        @error('form.send_enabled') <p class="text-sm text-rose-600">{{ $message }}</p> @enderror
        <label class="flex items-start gap-2 text-sm font-semibold"><input type="checkbox" wire:model="form.auto_card_invoices_enabled" class="mt-1" />Automatski izradi račun nakon potvrđene kartične uplate</label>
        <p class="text-xs text-slate-500">Vrijedi samo za uključeni fiskalni način nakon potvrđene uspješne kartične uplate. Ponude i bankovne transakcije ostaju ručne. Spremanje ove postavke ne šalje račune za ranije narudžbe.</p>
        @error('form.auto_card_invoices_enabled') <p class="text-sm text-rose-600">{{ $message }}</p> @enderror
        <button class="rounded-xl bg-slate-900 px-4 py-2 font-semibold text-white" wire:loading.attr="disabled">Spremi postavke</button>
    </form>
    @if ($blockers)
        <ul class="list-disc space-y-1 pl-5 text-sm text-slate-600">@foreach ($blockers as $blocker)<li>{{ $blocker }}</li>@endforeach</ul>
    @endif
    <p class="text-xs text-slate-500"><a href="https://app.fira.finance/webshop-docs/quickstart" target="_blank" rel="noopener noreferrer" class="underline">Službene FIRA upute</a> · <a href="https://fira.finance/hr/novosti/fiskalizacija-2-0-i-webshopovi/" target="_blank" rel="noopener noreferrer" class="underline">Pretvaranje ponude u račun u FIRA-i</a></p>
</div>
