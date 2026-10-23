<div class="space-y-6" x-data x-on:watchline-integration-report.window="$nextTick(() => $refs.integrationReport?.scrollIntoView({ behavior: 'smooth', block: 'center' }))">
    <section class="admin-panel p-6">
        <p class="text-xs font-semibold uppercase tracking-widest text-slate-500">WATCHLINE / INTEGRACIJE</p>
        <h1 class="mt-2 text-2xl font-semibold text-slate-900">GATH, Wolt i automatske poruke</h1>
        <p class="mt-2 text-sm text-slate-600">Ručno pokrenite pregled ili obradu i pripremite rasporede za produkciju. Svi pregledi koriste spremljene postavke.</p>
        @if($localPreview)
            <p class="mt-4 rounded-lg border border-sky-200 bg-sky-50 p-3 text-sm text-sky-900">Lokalni pregled je aktivan. Automatske obrade, slanje u Wolt i slanje poruka kupcima ostaju isključeni.</p>
        @endif
        <a class="mt-4 inline-flex text-sm font-semibold text-sky-700 underline" href="{{ route('admin.integrations.wolt.index') }}">Wolt povezivanje i asortiman →</a>
    </section>

    <section class="admin-panel space-y-4 p-6">
        <h2 class="text-lg font-semibold">GATH — cijene i vlastite zalihe</h2>
        <p class="text-sm text-slate-600">Izvor je ERP izvještaj webstanje.csv, kao na staroj stranici. Šifra povezuje postojeće artikle; osnovna cijena je u eurima, akcijske cijene ostaju spremljene.</p>
        <label class="block text-sm font-medium">Putanja do aktualne datoteke na serveru
            <input wire:model="form.gath_csv_path" class="mt-1 w-full rounded-lg border-slate-300 text-sm" type="text">
        </label>
        @error('gath_csv_path')<p class="text-sm text-red-600">{{ $message }}</p>@enderror
        <div class="flex flex-wrap items-end gap-3">
            <label class="block text-sm font-medium">Ili učitajte novi GATH izvještaj
                <input wire:model="csvUpload" class="mt-1 block text-sm" type="file" accept=".csv,.txt">
            </label>
            <button wire:click="uploadCsv" wire:loading.attr="disabled" type="button" class="rounded-lg border border-slate-300 px-4 py-2 text-sm font-semibold">Spremi datoteku</button>
        </div>
        @error('csvUpload')<p class="text-sm text-red-600">{{ $message }}</p>@enderror
        <label class="flex items-start gap-2 text-sm"><input wire:model="form.gath_reset_missing" type="checkbox" class="mt-0.5 rounded border-slate-300"><span>Potpuni izvještaj: postavi vlastitu zalihu na 0 za uvezene Watchline artikle kojih nema u datoteci.</span></label>
        <p class="text-xs text-slate-500">Uključite samo kada ERP dostavlja cijelu ponudu. Kod djelomičnog izvještaja ostavite isključeno.</p>
        <div class="flex flex-wrap gap-3">
            <button wire:click="previewJob('gath')" wire:loading.attr="disabled" type="button" class="rounded-lg bg-slate-900 px-4 py-2 text-sm font-semibold text-white">Pregledaj GATH promjene</button>
            <button wire:click="applyJob('gath')" wire:loading.attr="disabled" @disabled(!$gathPreviewChecksum) type="button" class="rounded-lg bg-sky-700 px-4 py-2 text-sm font-semibold text-white disabled:opacity-40">Primijeni pregledane promjene</button>
        </div>
        @error('gath')<p class="text-sm text-red-600">{{ $message }}</p>@enderror
    </section>

    <section class="admin-panel space-y-4 p-6">
        <h2 class="text-lg font-semibold">Poruke kupcima</h2>
        <p class="text-sm text-slate-600">Obrađuju se samo novi događaji nakon uključivanja. Stare uvezene narudžbe i košarice ne šalju se ponovno.</p>
        <div class="grid gap-6 lg:grid-cols-2">
            <div class="space-y-3">
                <label class="flex items-center gap-2 font-medium"><input wire:model="messages.reviews.enabled" type="checkbox" class="rounded border-slate-300"> Uključi pozive za recenzije</label>
                <label class="block text-sm">Broj dana od narudžbe<input wire:model="messages.reviews.delay_days" type="number" min="1" max="90" class="mt-1 block w-32 rounded-lg border-slate-300 text-sm"></label>
                <label class="block text-sm">Dopušteni statusi narudžbe<select wire:model="messages.reviews.status_ids" multiple class="mt-1 block w-full rounded-lg border-slate-300 text-sm">@foreach($orderStatuses as $status)<option value="{{ $status->id }}">{{ $status->name }}</option>@endforeach</select></label>
                @error('messages.reviews.status_ids')<p class="text-sm text-red-600">{{ $message }}</p>@enderror
            </div>
            <div class="space-y-3">
                <label class="flex items-center gap-2 font-medium"><input wire:model="messages.abandoned.enabled" type="checkbox" class="rounded border-slate-300"> Uključi podsjetnike za napuštenu košaricu</label>
                <label class="block text-sm">Broj minuta neaktivnosti<input wire:model="messages.abandoned.delay_minutes" type="number" min="1" max="10080" class="mt-1 block w-32 rounded-lg border-slate-300 text-sm"></label>
                <p class="text-xs text-slate-500">Za kupce s evidentiranom privolom; jedan podsjetnik po košarici. Ponuda iz izvornog predloška 7: 10% na artikle te košarice, vrijedi 7 dana.</p>
            </div>
        </div>
    </section>

    <section class="admin-panel space-y-4 p-6">
        <h2 class="text-lg font-semibold">Rasporedi i ručno pokretanje</h2>
        <label class="flex items-center gap-2 font-medium"><input wire:model="form.live_enabled" type="checkbox" class="rounded border-slate-300"> Uključi integracije na produkciji</label>
        <p class="text-sm text-slate-600">Vremenska zona: Europe/Zagreb. Rasporedi su preuzeti sa stare stranice i početno su isključeni. Wolt i poruke imaju i vlastite postavke uključivanja.</p>
        <div class="overflow-x-auto">
            <table class="w-full text-left text-sm"><thead><tr class="border-b text-slate-500"><th class="py-3 pr-3">Automatski</th><th class="py-3 pr-3">Obrada</th><th class="py-3 pr-3">Cron raspored</th><th class="py-3">Ručno</th></tr></thead><tbody>
            @foreach($jobs as $job => $definition)
                <tr wire:key="watchline-job-{{ $job }}" class="border-b border-slate-100">
                    <td class="py-3 pr-3"><input wire:model="form.schedules.{{ $job }}.enabled" type="checkbox" aria-label="Automatski: {{ $definition['label'] }}" class="rounded border-slate-300"></td>
                    <td class="py-3 pr-3 font-medium">{{ $definition['label'] }}</td>
                    <td class="py-3 pr-3"><input wire:model="form.schedules.{{ $job }}.expression" type="text" aria-label="Raspored: {{ $definition['label'] }}" class="w-48 rounded-lg border-slate-300 font-mono text-xs">@error('form.schedules.'.$job.'.expression')<p class="text-xs text-red-600">{{ $message }}</p>@enderror</td>
                    <td class="py-3"><div class="flex flex-wrap gap-2"><button wire:click="previewJob('{{ $job }}')" wire:loading.attr="disabled" type="button" class="rounded border border-slate-300 px-3 py-1.5">Pregled</button>@if($job !== 'gath')<button wire:click="applyJob('{{ $job }}')" wire:loading.attr="disabled" @disabled(!$liveReady) type="button" class="rounded bg-sky-700 px-3 py-1.5 text-white disabled:opacity-40">Pokreni</button>@endif</div></td>
                </tr>
            @endforeach
            </tbody></table>
        </div>
        <button wire:click="save" wire:loading.attr="disabled" type="button" class="rounded-lg bg-slate-900 px-5 py-2.5 text-sm font-semibold text-white">Spremi postavke integracija</button>
        @if($errors->any())<p class="text-sm text-red-600">{{ $errors->first() }}</p>@endif
        <details class="rounded-lg border border-slate-200 p-4 text-sm"><summary class="cursor-pointer font-semibold">Postavljanje server crona pri prelasku na live</summary><p class="mt-3 text-slate-600">Na novom hostingu postavite Laravel raspoređivač jednom u minuti, uz stvarnu putanju projekta i PHP verziju:</p><code class="mt-2 block overflow-x-auto rounded bg-slate-100 p-3">* * * * * cd /PUTANJA/DO/PROJEKTA &amp;&amp; /PUTANJA/DO/PHP artisan schedule:run &gt;&gt; /dev/null 2&gt;&amp;1</code><p class="mt-3 text-slate-600">ERP treba redovito dostavljati aktualni CSV na spremljenu putanju. Zatim uključite željene rasporede i glavni prekidač. Stare cronove zamijenite pri prijelazu kako se obrade ne bi izvršavale dvaput.</p></details>
    </section>

    @if($report)
        <section class="admin-panel space-y-3 p-6" aria-live="polite" x-ref="integrationReport">
            <h2 class="text-lg font-semibold">Rezultat: {{ $jobs[$report['job']]['label'] ?? '' }}</h2>
            <p class="text-sm font-medium">{{ ['preview' => 'Pregled bez primjene', 'completed' => 'Završeno', 'blocked' => 'Nije pokrenuto', 'accepted' => 'Wolt je zaprimio zahtjev', 'unchanged' => 'Nema promjena', 'failed' => 'Obrada nije uspjela'][$report['status']] ?? $report['status'] }}</p>
            @foreach($report['reasons'] ?? [] as $reason)<p class="text-sm text-slate-600">{{ [
                'not_watchline'=>'Obrada je dostupna samo za Watchline.',
                'job_disabled'=>'Ova vrsta poruka nije uključena.',
                'activation_required'=>'Poruke se počinju pratiti nakon prvog uključivanja.',
                'schema_missing'=>'Potrebno je dovršiti nadogradnju baze.',
                'store_email_disabled'=>'Slanje e-poruka trgovine je isključeno.',
                'local_safe_mode'=>'Lokalni sigurnosni način sprječava slanje.',
                'local_environment'=>'Na lokalnoj stranici slanje je isključeno.',
                'review_status_required'=>'Odaberite dopušteni status narudžbe za poziv na recenziju.',
            ][$reason] ?? $reason }}</p>@endforeach
            <dl class="grid gap-3 sm:grid-cols-3">
                @foreach(['fetched_count'=>'Redaka u datoteci','matched_count'=>'Povezanih artikala','updated_count'=>'Promjena','unmatched_count'=>'Šifri bez artikla','price_changed_count'=>'Promjena cijene','stock_changed_count'=>'Promjena zalihe','reset_candidate_count'=>'Nedostaje u izvještaju','eligible'=>'Poruka za obradu','sent'=>'Poslano','skipped'=>'Preskočeno'] as $key=>$label)
                    @if(isset($report[$key]))<div class="rounded-lg bg-slate-50 p-3"><dt class="text-xs text-slate-500">{{ $label }}</dt><dd class="mt-1 text-lg font-semibold">{{ $report[$key] }}</dd></div>@endif
                @endforeach
                @foreach($report['counts'] ?? [] as $key=>$value)<div class="rounded-lg bg-slate-50 p-3"><dt class="text-xs text-slate-500">{{ ['selected'=>'Odabrano','valid'=>'Valjano','changed'=>'Promijenjeno','unchanged'=>'Bez promjene','invalid'=>'Nevaljano','sent'=>'Poslano','skipped_conflicts'=>'Šifre s različitim cijenama — preskočeno'][$key] ?? $key }}</dt><dd class="mt-1 text-lg font-semibold">{{ $value }}</dd></div>@endforeach
            </dl>
            @if(!empty($report['examples']))
                <p class="text-xs text-slate-500">Prvih {{ count($report['examples']) }} promjena iz izvještaja.</p>
                <div class="overflow-x-auto"><table class="w-full text-left text-sm"><thead><tr class="border-b text-slate-500"><th class="py-2 pr-3">Artikl</th><th class="py-2 pr-3">Vlastita zaliha</th><th class="py-2">Osnovna cijena EUR</th></tr></thead><tbody>@foreach($report['examples'] as $example)<tr class="border-b border-slate-100"><td class="py-2 pr-3">{{ $example['sku'] }}</td><td class="py-2 pr-3">{{ $example['old_quantity'] }} → {{ $example['new_quantity'] }}</td><td class="py-2">{{ $example['old_price'] }} → {{ $example['new_price'] }}</td></tr>@endforeach</tbody></table></div>
            @endif
        </section>
    @endif
    <section class="admin-panel p-6">
        <h2 class="text-lg font-semibold">Posljednja izvršavanja</h2>
        <div class="mt-3 overflow-x-auto"><table class="w-full text-left text-sm"><thead><tr class="border-b text-slate-500"><th class="py-2 pr-3">Vrijeme</th><th class="py-2 pr-3">Obrada</th><th class="py-2 pr-3">Pokretanje</th><th class="py-2">Rezultat</th></tr></thead><tbody>@forelse($runs as $run)<tr class="border-b border-slate-100"><td class="py-3 pr-3">{{ $run->started_at->timezone('Europe/Zagreb')->format('d.m.Y. H:i') }}</td><td class="py-3 pr-3">{{ $jobs[$run->job]['label'] ?? $run->job }}</td><td class="py-3 pr-3">{{ $run->trigger === 'cron' ? 'Automatski' : 'Ručno' }}{{ $run->dry_run ? ' · pregled' : '' }}</td><td class="py-3">{{ ['preview'=>'Pregled','completed'=>'Završeno','accepted'=>'Wolt zaprimio','unchanged'=>'Bez promjene','blocked'=>'Nije pokrenuto','failed'=>'Neuspješno','running'=>'U tijeku'][$run->status] ?? $run->status }}</td></tr>@empty<tr><td colspan="4" class="py-4 text-slate-500">Još nema izvršavanja.</td></tr>@endforelse</tbody></table></div>
    </section>
</div>
