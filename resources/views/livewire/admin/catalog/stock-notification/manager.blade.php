<div class="space-y-6">
    <div class="admin-panel p-6">
        <h1 class="text-2xl font-semibold tracking-tight">Obavijesti o dostupnosti</h1>
        <p class="mt-2 max-w-3xl text-sm text-slate-600">Pratite što kupci čekaju i pošaljite im obavijest kada artikl, varijanta ili cijeli komplet ponovno bude dostupan. Svaka se prijava šalje samo jednom.</p>
        @if (! $emailsEnabled)
            <div class="mt-4 rounded-xl border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-900" role="status">Slanje e-mailova trenutačno je isključeno. Prijave se uredno spremaju, a obavijesti će se moći poslati nakon uključivanja slanja.</div>
        @endif
    </div>

    <div class="grid gap-3 sm:grid-cols-2 xl:grid-cols-6">
        @foreach (['total' => 'Ukupno prijava', 'waiting' => 'Čeka zalihu', 'ready' => 'Spremno za slanje', 'sent' => 'Poslano', 'failed' => 'Neuspjelo', 'cancelled' => 'Otkazano'] as $key => $label)
            <div class="admin-panel p-4 {{ $key === 'ready' ? 'border-emerald-200 bg-emerald-50' : '' }}">
                <p class="text-xs font-semibold uppercase tracking-wide text-slate-500">{{ $label }}</p>
                <p class="mt-2 text-3xl font-semibold {{ $key === 'ready' ? 'text-emerald-800' : 'text-slate-900' }}">{{ number_format($stats[$key], 0, ',', '.') }}</p>
            </div>
        @endforeach
    </div>

    <div class="admin-panel admin-search-panel p-5">
        <div class="grid items-end gap-4 md:grid-cols-[minmax(0,1fr)_16rem_auto]">
            <label class="block text-sm font-medium text-slate-700" for="stock-notification-search">Pretraži prijave
                <input id="stock-notification-search" type="search" maxlength="200" wire:model.live.debounce.300ms="search" placeholder="E-mail, naziv artikla, šifra ili komplet" class="admin-search-input mt-2 w-full rounded-xl border px-3 py-2 text-sm">
            </label>
            <label class="block text-sm font-medium text-slate-700" for="stock-notification-status">Status
                <select id="stock-notification-status" wire:model.live="status" class="admin-select mt-2 w-full rounded-xl border px-3 py-2 text-sm">
                    <option value="">Sve prijave</option>
                    <option value="ready">Spremno za slanje</option>
                    <option value="waiting">Čeka zalihu</option>
                    <option value="processing">Slanje u tijeku</option>
                    <option value="sent">Poslano</option>
                    <option value="failed">Neuspjelo</option>
                    <option value="cancelled">Otkazano</option>
                </select>
            </label>
            <button type="button" wire:click="clearFilters" class="rounded-xl border border-slate-300 px-4 py-2 text-sm font-semibold text-slate-700 hover:bg-slate-50">Očisti filtre</button>
        </div>
    </div>

    <div class="admin-panel p-5">
        <div class="flex flex-wrap items-center justify-between gap-4">
            <div>
                <h2 class="admin-section-title">Prijave kupaca</h2>
                <p class="mt-1 text-xs text-slate-500">Slanje uvijek provjerava aktualnu zalihu. Poslane i otkazane prijave ne šalju se ponovno.</p>
            </div>
            @if ($canUpdate)
                <div class="flex flex-wrap items-center gap-3">
                    <button type="button" wire:click="selectReady" wire:loading.attr="disabled" @disabled(! $emailsEnabled) class="rounded-lg border border-slate-300 px-3 py-2 text-sm font-semibold text-slate-700 hover:bg-slate-50 disabled:opacity-50">Odaberi spremne na stranici</button>
                    <span class="text-sm text-slate-500">Odabrano: {{ count($selected) }}</span>
                    <button type="button" wire:click="sendSelected" wire:confirm="Poslati obavijesti svim odabranim kupcima?" wire:loading.attr="disabled" @disabled(! $emailsEnabled || count($selected) === 0) class="inline-flex items-center gap-2 rounded-lg bg-emerald-800 px-4 py-2 text-sm font-semibold text-white hover:bg-emerald-900 disabled:opacity-50">
                        <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" aria-hidden="true"><path d="m22 2-7 20-4-9-9-4Z"/><path d="M22 2 11 13"/></svg>
                        <span wire:loading.remove wire:target="sendSelected">Pošalji odabrano</span><span wire:loading wire:target="sendSelected">Šaljem…</span>
                    </button>
                </div>
            @endif
        </div>

        @if ($errors->any())
            <div role="alert" class="mt-4 rounded-lg bg-rose-50 px-4 py-3 text-sm text-rose-800">@foreach ($errors->all() as $error)<p>{{ $error }}</p>@endforeach</div>
        @endif

        <div class="mt-4 overflow-x-auto">
            <table class="admin-items-table min-w-full text-sm">
                <thead><tr>
                    @if ($canUpdate)<th class="px-3 py-3"><span class="sr-only">Odaberi prijavu</span></th>@endif
                    <th class="px-3 py-3 text-left font-semibold">Artikl / komplet</th>
                    <th class="px-3 py-3 text-left font-semibold">Kupac</th>
                    <th class="px-3 py-3 text-left font-semibold">Status</th>
                    <th class="px-3 py-3 text-left font-semibold">Prijavljeno</th>
                    <th class="px-3 py-3 text-left font-semibold">Poslano</th>
                    @if ($canUpdate)<th class="px-3 py-3 text-right font-semibold">Upravljanje</th>@endif
                </tr></thead>
                <tbody class="divide-y divide-slate-100">
                    @forelse ($rows as $row)
                        @php
                            $isReady = in_array($row->status, ['waiting', 'failed'], true) && in_array((int) $row->id, $readyIds, true);
                            $isStaleProcessing = $row->status === 'processing' && $row->last_attempt_at && $row->last_attempt_at->lte(now()->subMinutes(30));
                            [$statusLabel, $statusClass] = match ($row->status) {
                                'sent' => ['Poslano', 'bg-emerald-100 text-emerald-800'],
                                'processing' => ['Slanje u tijeku', 'bg-blue-100 text-blue-800'],
                                'failed' => ['Neuspjelo', 'bg-rose-100 text-rose-800'],
                                'cancelled' => ['Otkazano', 'bg-slate-100 text-slate-600'],
                                default => $isReady ? ['Spremno za slanje', 'bg-emerald-100 text-emerald-800'] : ['Čeka zalihu', 'bg-amber-100 text-amber-800'],
                            };
                        @endphp
                        <tr wire:key="stock-notification-{{ $row->id }}">
                            @if ($canUpdate)
                                <td class="px-3 py-3"><input type="checkbox" value="{{ $row->id }}" wire:model.live="selected" @disabled(! $isReady || ! $emailsEnabled) class="rounded border-slate-300 text-emerald-700" aria-label="Odaberi prijavu za {{ $row->email }}"></td>
                            @endif
                            <td class="px-3 py-3">
                                @if ($row->product)
                                    @if ($canUpdate)
                                        <a href="{{ route('admin.products.edit', $row->product_id) }}" class="font-semibold text-slate-900 hover:text-orange-600">{{ $row->displayName() }}</a>
                                    @else
                                        <span class="font-semibold text-slate-900">{{ $row->displayName() }}</span>
                                    @endif
                                    <div class="mt-1 text-xs text-slate-500">Šifra: {{ $row->product->sku ?: '—' }}{{ $row->product_option_value_id ? ' · Odabrana varijanta' : '' }}</div>
                                @else
                                    <div class="font-semibold text-slate-900">{{ $row->displayName() }}</div>
                                    @if ($row->bundle_id)<div class="mt-1 text-xs text-slate-500">Poklon komplet</div>@endif
                                @endif
                            </td>
                            <td class="px-3 py-3"><div class="font-medium text-slate-800">{{ $row->email }}</div><div class="mt-1 text-xs text-slate-500">{{ $row->user_id ? 'Registrirani kupac' : 'Gost' }}</div></td>
                            <td class="px-3 py-3">
                                <span class="inline-flex rounded-full px-2.5 py-1 text-xs font-semibold {{ $statusClass }}">{{ $statusLabel }}</span>
                                @if ($isStaleProcessing)
                                    <p class="mt-2 max-w-xs text-xs text-amber-800">Slanje traje dulje od 30 minuta. Provjerite je li poruka dostavljena prije označavanja prekida i ponovnog pokušaja.</p>
                                @endif
                                @if ($row->status === 'failed')
                                    <div class="mt-1 text-xs text-slate-500">Pokušaji slanja: {{ $row->attempts }}</div>
                                    @if ($row->last_error)<p class="mt-1 max-w-xs text-xs text-rose-700">{{ \Illuminate\Support\Str::limit($row->last_error, 160) }}</p>@endif
                                    @if (! $isReady)<div class="mt-1 text-xs text-amber-700">Čeka ponovnu dostupnost</div>@endif
                                @endif
                            </td>
                            <td class="whitespace-nowrap px-3 py-3 text-xs text-slate-600">{{ $row->created_at?->format('d.m.Y. H:i') ?? '—' }}</td>
                            <td class="whitespace-nowrap px-3 py-3 text-xs text-slate-600">{{ $row->sent_at?->format('d.m.Y. H:i') ?? '—' }}</td>
                            @if ($canUpdate)
                                <td class="px-3 py-3 text-right">
                                    <div class="flex justify-end gap-2">
                                        @if ($isStaleProcessing)
                                            <button type="button" wire:click="markInterrupted({{ $row->id }})" wire:confirm="Označiti slanje kao prekinuto? Prije ponovnog slanja provjerite je li poruka već dostavljena. Automatsko slanje ove prijave ostat će zaustavljeno." wire:loading.attr="disabled" class="whitespace-nowrap rounded-lg border border-amber-300 bg-amber-50 px-3 py-2 text-xs font-semibold text-amber-900 hover:bg-amber-100">Označi kao prekinuto</button>
                                        @endif
                                        @if ($isReady)
                                            <button type="button" wire:click="send({{ $row->id }})" wire:confirm="Poslati kupcu obavijest o dostupnosti?" wire:loading.attr="disabled" @disabled(! $emailsEnabled) class="whitespace-nowrap rounded-lg bg-emerald-800 px-3 py-2 text-xs font-semibold text-white hover:bg-emerald-900 disabled:opacity-50">{{ $row->status === 'failed' ? 'Pokušaj ponovno' : 'Pošalji obavijest' }}</button>
                                        @endif
                                        @if (in_array($row->status, ['waiting', 'failed'], true))
                                            <button type="button" wire:click="cancel({{ $row->id }})" wire:confirm="Otkazati ovu prijavu? Kupcu se više neće slati obavijest za ovu prijavu." wire:loading.attr="disabled" class="rounded-lg border border-slate-300 px-3 py-2 text-xs font-semibold text-slate-600 hover:border-rose-300 hover:bg-rose-50 hover:text-rose-700">Otkaži</button>
                                        @endif
                                    </div>
                                </td>
                            @endif
                        </tr>
                    @empty
                        <tr><td colspan="{{ $canUpdate ? 7 : 5 }}" class="px-3 py-12 text-center text-slate-500">Nema prijava za odabrane filtre.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        <div class="mt-4">{{ $rows->links() }}</div>
    </div>

    <div class="admin-panel p-5">
        <h2 class="admin-section-title">Najtraženiji artikli i kompleti</h2>
        <p class="mt-1 text-sm text-slate-500">Ponude s najviše kupaca koji još čekaju obavijest.</p>
        <div class="mt-4 overflow-x-auto">
            <table class="admin-items-table min-w-full text-sm">
                <thead><tr><th class="px-3 py-3 text-left font-semibold">Artikl / komplet</th><th class="px-3 py-3 text-right font-semibold">Čeka obavijest</th><th class="px-3 py-3 text-right font-semibold">Poslano</th><th class="px-3 py-3 text-right font-semibold">Ukupno prijava</th></tr></thead>
                <tbody class="divide-y divide-slate-100">
                    @forelse ($topProducts as $item)
                        <tr><td class="px-3 py-3 font-medium text-slate-800">{{ $item['notification']?->displayName() ?? 'Artikl više nije dostupan' }}</td><td class="px-3 py-3 text-right font-semibold text-emerald-800">{{ $item['pending'] }}</td><td class="px-3 py-3 text-right">{{ $item['sent'] }}</td><td class="px-3 py-3 text-right">{{ $item['total'] }}</td></tr>
                    @empty
                        <tr><td colspan="4" class="px-3 py-8 text-center text-slate-500">Nakon prvih prijava ovdje će se prikazati najtraženiji artikli.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>
</div>
