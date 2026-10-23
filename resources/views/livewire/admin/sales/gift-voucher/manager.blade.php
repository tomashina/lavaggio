<div class="space-y-6">
    <div class="admin-panel p-6">
        <div class="flex flex-wrap items-start justify-between gap-4">
            <div>
                <h1 class="text-2xl font-semibold tracking-tight">Poklon bonovi</h1>
                <p class="mt-2 max-w-3xl text-sm text-slate-600">Izdajte digitalni poklon bon, pratite plaćanje, slanje primatelju i iskorišteni iznos.</p>
            </div>
            @if ($canUpdate)
                <button type="button" wire:click="create" class="inline-flex items-center gap-2 rounded-xl bg-slate-900 px-4 py-2.5 text-sm font-semibold text-white hover:bg-slate-800"><x-fa-icon name="gift" class="h-4 w-4" /> Izdaj poklon bon</button>
            @endif
        </div>
        @if (! $emailsEnabled)
            <p class="mt-4 rounded-xl border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-900" role="status">Slanje e-pošte trenutačno je isključeno. Bonovi se spremaju i mogu se poslati kada uključite slanje.</p>
        @endif
    </div>

    <div class="grid gap-3 sm:grid-cols-2 xl:grid-cols-5">
        @foreach (['total' => 'Ukupno bonova', 'active' => 'Aktivni bonovi', 'pending' => 'Čeka plaćanje', 'issued' => 'Vrijednost izdanih', 'balance' => 'Preostali iznos'] as $key => $label)
            <div class="admin-panel p-4">
                <p class="text-xs font-semibold uppercase tracking-wide text-slate-500">{{ $label }}</p>
                <p class="mt-2 text-2xl font-semibold text-slate-900">{{ number_format($stats[$key], in_array($key, ['issued', 'balance'], true) ? 2 : 0, ',', '.') }}{{ in_array($key, ['issued', 'balance'], true) ? ' €' : '' }}</p>
            </div>
        @endforeach
    </div>

    <div class="admin-panel admin-search-panel p-5">
        <div class="grid items-end gap-4 md:grid-cols-2 xl:grid-cols-[minmax(0,1fr)_12rem_12rem_auto]">
            <label class="block text-sm font-medium text-slate-700">Pretraži bonove
                <input type="search" wire:model.live.debounce.300ms="search" maxlength="200" placeholder="Ime, e-mail, kôd, broj bona ili narudžbe" class="admin-search-input mt-2 w-full rounded-xl border px-3 py-2 text-sm">
            </label>
            <label class="block text-sm font-medium text-slate-700">Status bona
                <select wire:model.live="status" class="admin-select mt-2 w-full rounded-xl border px-3 py-2 text-sm">
                    <option value="">Svi statusi</option>
                    <option value="pending">Čeka plaćanje</option>
                    <option value="active">Aktivan</option>
                    <option value="exhausted">Iskorišten</option>
                    <option value="disabled">Onemogućen</option>
                    <option value="cancelled">Otkazan</option>
                    <option value="expired">Istekao</option>
                </select>
            </label>
            <label class="block text-sm font-medium text-slate-700">Slanje e-pošte
                <select wire:model.live="emailStatus" class="admin-select mt-2 w-full rounded-xl border px-3 py-2 text-sm">
                    <option value="">Sva slanja</option>
                    <option value="waiting">Još nije poslano</option>
                    <option value="sent">Poslano</option>
                    <option value="failed">Neuspjelo slanje</option>
                </select>
            </label>
            <button type="button" wire:click="clearFilters" class="rounded-xl border border-slate-300 px-4 py-2 text-sm font-semibold text-slate-700 hover:bg-slate-50">Očisti filtre</button>
        </div>
    </div>

    @if ($errors->any())
        <div role="alert" class="rounded-xl border border-rose-200 bg-rose-50 px-4 py-3 text-sm text-rose-800">@foreach ($errors->all() as $error)<p>{{ $error }}</p>@endforeach</div>
    @endif

    <div class="admin-panel overflow-hidden">
        <div class="overflow-x-auto">
            <table class="admin-items-table min-w-full text-sm">
                <thead><tr>
                    <th class="px-4 py-3 text-left">Bon / kupnja</th>
                    <th class="px-4 py-3 text-left">Primatelj / kupac</th>
                    <th class="px-4 py-3 text-right">Vrijednost / preostalo</th>
                    <th class="px-4 py-3 text-left">Status / vrijedi do</th>
                    <th class="px-4 py-3 text-left">E-pošta</th>
                    <th class="px-4 py-3 text-right">Upravljanje</th>
                </tr></thead>
                <tbody class="divide-y divide-slate-100">
                    @forelse ($rows as $row)
                        <tr wire:key="gift-voucher-{{ $row->id }}">
                            <td class="px-4 py-4 align-top">
                                <button type="button" wire:click="detail({{ $row->id }})" class="font-semibold text-slate-900 underline-offset-4 hover:underline">Bon #{{ $row->id }}</button>
                                <div class="mt-1 font-mono text-xs text-slate-500">{{ $row->code_suffix ? '•••• '.$row->code_suffix : 'Kôd nakon uplate' }}</div>
                                @if ($row->purchaseOrder)
                                    <a href="{{ route('admin.orders.show', $row->purchase_order_id) }}" class="mt-2 block text-xs font-medium text-emerald-800 hover:underline">{{ $row->purchaseOrder->order_number }}</a>
                                @else
                                    <span class="mt-2 block text-xs text-slate-500">Ručno izdan bon</span>
                                @endif
                            </td>
                            <td class="px-4 py-4 align-top">
                                <div class="font-semibold text-slate-800">{{ $row->recipient_name }}</div>
                                <div class="mt-1 text-xs text-slate-600">{{ $row->recipient_email }}</div>
                                @if ($row->buyer_name || $row->buyer_email)<div class="mt-2 text-xs text-slate-500">Kupac: {{ $row->buyer_name ?: $row->buyer_email }}</div>@endif
                            </td>
                            <td class="px-4 py-4 text-right align-top">
                                <strong class="whitespace-nowrap text-slate-900">{{ number_format((float) $row->initial_amount, 2, ',', '.') }} {{ $row->currency === 'EUR' ? '€' : $row->currency }}</strong>
                                <div class="mt-1 whitespace-nowrap text-xs text-slate-500">Preostalo {{ number_format((float) $row->balance, 2, ',', '.') }} {{ $row->currency === 'EUR' ? '€' : $row->currency }}</div>
                            </td>
                            <td class="px-4 py-4 align-top">
                                <span class="inline-flex whitespace-nowrap rounded-full px-2.5 py-1 text-xs font-semibold {{ $row->isUsable() ? 'bg-emerald-100 text-emerald-800' : ($row->status === 'pending' ? 'bg-amber-100 text-amber-800' : 'bg-slate-100 text-slate-600') }}">{{ $row->display_status }}</span>
                                <div class="mt-2 text-xs text-slate-500">{{ $row->expires_at ? 'Do '.$row->expires_at->format('d.m.Y.') : 'Bez datuma isteka' }}</div>
                            </td>
                            <td class="px-4 py-4 align-top">
                                @if ($row->email_error)
                                    <span class="text-xs font-semibold text-rose-700">Neuspjelo slanje</span>
                                    <p class="mt-1 max-w-xs text-xs text-rose-700">{{ \Illuminate\Support\Str::limit($row->email_error, 160) }}</p>
                                @elseif ($row->email_sent_at)
                                    <span class="text-xs font-semibold text-emerald-800">Poslano</span>
                                    <div class="mt-1 text-xs text-slate-500">{{ ($row->last_email_sent_at ?? $row->email_sent_at)->format('d.m.Y. H:i') }}</div>
                                @else
                                    <span class="text-xs text-slate-500">{{ $row->issued_at ? 'Čeka slanje' : 'Nakon plaćanja' }}</span>
                                @endif
                            </td>
                            <td class="px-4 py-4 align-top">
                                <div class="flex flex-wrap justify-end gap-2">
                                    <button type="button" wire:click="detail({{ $row->id }})" class="rounded-lg border border-slate-300 px-3 py-1.5 text-xs font-semibold hover:bg-slate-50">Detalji</button>
                                    @if ($canUpdate)
                                        @if ($row->status === 'pending' && $row->purchaseOrder && ! $row->purchaseOrder->paid_at)
                                            <button type="button" wire:click="confirmPayment({{ $row->id }})" wire:confirm="Potvrditi da je uplata za ovu narudžbu zaprimljena? Bon će postati aktivan i bit će poslan primatelju kada je slanje uključeno." wire:loading.attr="disabled" class="rounded-lg bg-emerald-800 px-3 py-1.5 text-xs font-semibold text-white hover:bg-emerald-900 disabled:opacity-50">Potvrdi uplatu</button>
                                        @endif
                                        @if ($row->isUsable())
                                            <button type="button" wire:click="send({{ $row->id }})" wire:confirm="Poslati poklon bon na e-mail primatelja?" wire:loading.attr="disabled" @disabled(! $emailsEnabled) class="rounded-lg border border-emerald-700 px-3 py-1.5 text-xs font-semibold text-emerald-800 hover:bg-emerald-50 disabled:opacity-50">{{ $row->email_sent_at ? 'Pošalji ponovno' : 'Pošalji bon' }}</button>
                                        @endif
                                        @if (in_array($row->status, ['active', 'disabled'], true))
                                            <button type="button" wire:click="toggle({{ $row->id }})" wire:confirm="Promijeniti dostupnost ovog poklon bona za korištenje?" wire:loading.attr="disabled" class="rounded-lg border border-slate-300 px-3 py-1.5 text-xs font-semibold text-slate-700 hover:bg-slate-50 disabled:opacity-50">{{ $row->status === 'disabled' ? 'Aktiviraj' : 'Onemogući' }}</button>
                                        @endif
                                    @endif
                                </div>
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="6" class="px-4 py-12 text-center text-sm text-slate-500">Nema poklon bonova za odabrane filtre.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        <div class="border-t border-slate-100 p-4">{{ $rows->links() }}</div>
    </div>

    @if ($creating && $canUpdate)
        <div class="fixed inset-0 z-50 overflow-y-auto bg-slate-950/50 p-4 sm:p-8" role="dialog" aria-modal="true" aria-labelledby="gift-voucher-create-title" wire:keydown.escape.window="closeCreate">
            <div class="mx-auto max-w-3xl rounded-2xl bg-white p-6 shadow-xl">
                <div class="flex items-start justify-between gap-4">
                    <div><h2 id="gift-voucher-create-title" class="text-xl font-semibold">Izdaj poklon bon</h2><p class="mt-1 text-sm text-slate-500">Ručno izdani bon odmah je aktivan. Primatelju se šalje nakon izdavanja ako je slanje uključeno.</p></div>
                    <button type="button" wire:click="closeCreate" aria-label="Zatvori izdavanje bona" class="rounded-lg border px-3 py-1.5 text-slate-600 hover:bg-slate-50">✕</button>
                </div>
                <form wire:submit="save" class="mt-6 space-y-5">
                    <div class="grid gap-4 sm:grid-cols-2">
                        <label class="block text-sm font-medium">Vrijednost bona
                            <select wire:model="form.amount" required class="admin-select mt-2 w-full rounded-xl border px-3 py-2">@foreach ($amounts as $amount)<option value="{{ $amount }}">{{ number_format($amount, 0, ',', '.') }} €</option>@endforeach</select>
                            @error('form.amount')<span class="mt-1 block text-xs text-rose-700">{{ $message }}</span>@enderror
                        </label>
                        <label class="block text-sm font-medium">Vrijedi do <span class="font-normal text-slate-500">(neobavezno)</span>
                            <input type="date" wire:model="form.expires_at" min="{{ now()->addDay()->toDateString() }}" class="mt-2 w-full rounded-xl border px-3 py-2">
                            @error('form.expires_at')<span class="mt-1 block text-xs text-rose-700">{{ $message }}</span>@enderror
                        </label>
                        <label class="block text-sm font-medium">Ime primatelja
                            <input type="text" wire:model="form.recipient_name" maxlength="191" required class="mt-2 w-full rounded-xl border px-3 py-2">
                            @error('form.recipient_name')<span class="mt-1 block text-xs text-rose-700">{{ $message }}</span>@enderror
                        </label>
                        <label class="block text-sm font-medium">E-mail primatelja
                            <input type="email" wire:model="form.recipient_email" maxlength="191" required class="mt-2 w-full rounded-xl border px-3 py-2">
                            @error('form.recipient_email')<span class="mt-1 block text-xs text-rose-700">{{ $message }}</span>@enderror
                        </label>
                        <label class="block text-sm font-medium">Ime kupca <span class="font-normal text-slate-500">(neobavezno)</span>
                            <input type="text" wire:model="form.buyer_name" maxlength="191" class="mt-2 w-full rounded-xl border px-3 py-2">
                            @error('form.buyer_name')<span class="mt-1 block text-xs text-rose-700">{{ $message }}</span>@enderror
                        </label>
                        <label class="block text-sm font-medium">E-mail kupca <span class="font-normal text-slate-500">(neobavezno)</span>
                            <input type="email" wire:model="form.buyer_email" maxlength="191" class="mt-2 w-full rounded-xl border px-3 py-2">
                            @error('form.buyer_email')<span class="mt-1 block text-xs text-rose-700">{{ $message }}</span>@enderror
                        </label>
                    </div>
                    <label class="block text-sm font-medium">Ime pošiljatelja na bonu
                        <input type="text" wire:model="form.sender_name" maxlength="191" required class="mt-2 w-full rounded-xl border px-3 py-2">
                        @error('form.sender_name')<span class="mt-1 block text-xs text-rose-700">{{ $message }}</span>@enderror
                    </label>
                    <label class="block text-sm font-medium">Poruka primatelju <span class="font-normal text-slate-500">(neobavezno)</span>
                        <textarea wire:model="form.message" rows="3" maxlength="1000" class="mt-2 w-full rounded-xl border px-3 py-2"></textarea>
                        @error('form.message')<span class="mt-1 block text-xs text-rose-700">{{ $message }}</span>@enderror
                    </label>
                    <div class="flex flex-wrap justify-end gap-3 border-t border-slate-100 pt-5">
                        <button type="button" wire:click="closeCreate" class="rounded-xl border border-slate-300 px-4 py-2 text-sm font-semibold hover:bg-slate-50">Odustani</button>
                        <button type="submit" wire:loading.attr="disabled" class="rounded-xl bg-slate-900 px-4 py-2 text-sm font-semibold text-white hover:bg-slate-800 disabled:opacity-50"><span wire:loading.remove wire:target="save">{{ $emailsEnabled ? 'Izdaj i pošalji bon' : 'Izdaj bon' }}</span><span wire:loading wire:target="save">Spremam…</span></button>
                    </div>
                </form>
            </div>
        </div>
    @endif

    @if ($detailVoucher)
        <div class="fixed inset-0 z-50 overflow-y-auto bg-slate-950/50 p-4 sm:p-8" role="dialog" aria-modal="true" aria-labelledby="gift-voucher-detail-title" wire:keydown.escape.window="closeDetail">
            <div class="mx-auto max-w-4xl rounded-2xl bg-white p-6 shadow-xl">
                <div class="flex items-start justify-between gap-4">
                    <div><h2 id="gift-voucher-detail-title" class="text-xl font-semibold">Poklon bon #{{ $detailVoucher->id }}</h2><p class="mt-1 text-sm text-slate-500">{{ $detailVoucher->display_status }} · {{ $detailVoucher->issued_at ? 'Izdan '.$detailVoucher->issued_at->format('d.m.Y. H:i') : 'Čeka potvrdu plaćanja' }}</p></div>
                    <button type="button" wire:click="closeDetail" aria-label="Zatvori detalje bona" class="rounded-lg border px-3 py-1.5 text-slate-600 hover:bg-slate-50">✕</button>
                </div>
                <div class="mt-5 rounded-xl border border-emerald-200 bg-emerald-50 p-5">
                    <p class="text-xs font-semibold uppercase tracking-wide text-emerald-800">Kôd poklon bona</p>
                    <code class="mt-2 block select-all break-all text-xl font-semibold text-slate-900">{{ $canUpdate ? ($detailVoucher->code ?: 'Kôd se izdaje nakon plaćanja') : ($detailVoucher->code_suffix ? '•••• '.$detailVoucher->code_suffix : 'Kôd nakon uplate') }}</code>
                    <div class="mt-4 flex flex-wrap gap-x-8 gap-y-2 text-sm"><span>Vrijednost <strong>{{ number_format((float) $detailVoucher->initial_amount, 2, ',', '.') }} €</strong></span><span>Preostalo <strong>{{ number_format((float) $detailVoucher->balance, 2, ',', '.') }} €</strong></span></div>
                </div>
                <dl class="mt-6 grid gap-5 text-sm sm:grid-cols-2">
                    <div><dt class="text-xs font-semibold uppercase tracking-wide text-slate-500">Primatelj</dt><dd class="mt-1 font-semibold">{{ $detailVoucher->recipient_name }}</dd><dd class="text-slate-600">{{ $detailVoucher->recipient_email }}</dd></div>
                    <div><dt class="text-xs font-semibold uppercase tracking-wide text-slate-500">Kupac</dt><dd class="mt-1 font-semibold">{{ $detailVoucher->buyer_name ?: 'Nije upisano' }}</dd><dd class="text-slate-600">{{ $detailVoucher->buyer_email }}</dd></div>
                    <div><dt class="text-xs font-semibold uppercase tracking-wide text-slate-500">Pošiljatelj na bonu</dt><dd class="mt-1">{{ $detailVoucher->sender_name }}</dd></div>
                    <div><dt class="text-xs font-semibold uppercase tracking-wide text-slate-500">Vrijedi do</dt><dd class="mt-1">{{ $detailVoucher->expires_at?->format('d.m.Y.') ?: 'Bez datuma isteka' }}</dd></div>
                    @if ($detailVoucher->message)<div class="sm:col-span-2"><dt class="text-xs font-semibold uppercase tracking-wide text-slate-500">Poruka primatelju</dt><dd class="mt-2 whitespace-pre-line rounded-xl bg-slate-50 p-4">{{ $detailVoucher->message }}</dd></div>@endif
                    @if ($detailVoucher->purchaseOrder)<div><dt class="text-xs font-semibold uppercase tracking-wide text-slate-500">Narudžba kupnje</dt><dd class="mt-1"><a class="font-semibold text-emerald-800 hover:underline" href="{{ route('admin.orders.show', $detailVoucher->purchase_order_id) }}">{{ $detailVoucher->purchaseOrder->order_number }}</a></dd></div>@endif
                    <div><dt class="text-xs font-semibold uppercase tracking-wide text-slate-500">Slanje primatelju</dt><dd class="mt-1">{{ $detailVoucher->email_sent_at ? 'Prvi put '.$detailVoucher->email_sent_at->format('d.m.Y. H:i') : 'Još nije poslano' }}</dd>@if ($detailVoucher->last_email_sent_at)<dd class="text-slate-600">Posljednje slanje {{ $detailVoucher->last_email_sent_at->format('d.m.Y. H:i') }}</dd>@endif</div>
                </dl>
                @if ($detailVoucher->email_error)<p role="alert" class="mt-5 rounded-lg bg-rose-50 p-4 text-sm text-rose-800">{{ $detailVoucher->email_error }}</p>@endif
                <div class="mt-7 border-t border-slate-200 pt-5">
                    <h3 class="font-semibold">Povijest korištenja</h3>
                    <div class="mt-3 overflow-x-auto"><table class="min-w-full text-sm"><thead><tr class="text-left text-xs text-slate-500"><th class="py-2 pr-4">Narudžba</th><th class="px-4 py-2">Iznos</th><th class="px-4 py-2">Status</th><th class="py-2 pl-4">Datum</th></tr></thead><tbody class="divide-y divide-slate-100">
                        @forelse ($detailVoucher->redemptions as $redemption)
                            <tr><td class="py-3 pr-4">@if ($redemption->order)<a href="{{ route('admin.orders.show', $redemption->order_id) }}" class="font-medium text-emerald-800 hover:underline">{{ $redemption->order->order_number }}</a>@else #{{ $redemption->order_id }} @endif</td><td class="whitespace-nowrap px-4 py-3 font-semibold">{{ number_format((float) $redemption->amount, 2, ',', '.') }} €</td><td class="px-4 py-3">{{ match ($redemption->status) { 'redeemed' => 'Iskorišteno', 'released' => 'Vraćeno na bon', default => 'Rezervirano za plaćanje' } }}@if ($redemption->release_reason)<small class="mt-1 block text-slate-500">{{ match ($redemption->release_reason) { 'order_cancelled' => 'Narudžba je otkazana', 'reservation_expired' => 'Istekla je rezervacija', default => 'Rezervacija je oslobođena' } }}</small>@endif</td><td class="py-3 pl-4 text-slate-500">{{ ($redemption->redeemed_at ?? $redemption->released_at ?? $redemption->created_at)?->format('d.m.Y. H:i') }}</td></tr>
                        @empty
                            <tr><td colspan="4" class="py-5 text-slate-500">Ovaj bon još nije korišten.</td></tr>
                        @endforelse
                    </tbody></table></div>
                </div>
                <div class="mt-6 flex flex-wrap justify-end gap-3 border-t border-slate-100 pt-5">
                    @if ($canUpdate && $detailVoucher->isUsable())<button type="button" wire:click="send({{ $detailVoucher->id }})" wire:confirm="Poslati poklon bon na e-mail primatelja?" @disabled(! $emailsEnabled) wire:loading.attr="disabled" class="rounded-xl bg-emerald-800 px-4 py-2 text-sm font-semibold text-white hover:bg-emerald-900 disabled:opacity-50">{{ $detailVoucher->email_sent_at ? 'Pošalji ponovno' : 'Pošalji bon' }}</button>@endif
                    <button type="button" wire:click="closeDetail" class="rounded-xl border border-slate-300 px-4 py-2 text-sm font-semibold hover:bg-slate-50">Zatvori</button>
                </div>
            </div>
        </div>
    @endif
</div>
