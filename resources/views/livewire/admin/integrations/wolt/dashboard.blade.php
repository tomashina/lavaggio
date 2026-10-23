<div class="space-y-6">
    <section class="admin-panel p-6">
        <h1 class="text-xl font-semibold text-slate-900">Wolt</h1>
        <a href="{{ route('admin.integrations.watchline.index') }}" class="mt-2 inline-block text-sm font-semibold text-cyan-700">{{ __('Rasporedi i povijest izvršavanja') }}</a>
        <p class="mt-2 max-w-4xl text-sm text-slate-600">{{ __('Cijene i zalihe za proizvode koji već postoje u Wolt asortimanu. Ova veza ne kreira nove proizvode i ne uvozi Wolt narudžbe.') }}</p>
        @if ($localPreviewOnly)
            <p class="mt-4 rounded-xl border border-sky-200 bg-sky-50 p-4 text-sm text-sky-900">{{ __('Lokalni pregled je uključen. Postavke i sadržaj poruke možete pregledati bez slanja podataka u Wolt.') }}</p>
        @endif
        <p class="mt-3 text-sm text-slate-600">{{ __('Izvorna veza koristi model proizvoda, osnovnu cijenu i količinu zalihe. index.php šalje brojčane zalihe, a inventory.php cijene i vidljivost. Prije uključivanja potvrdite vezu i asortiman s Woltom.') }}</p>
        @if (($form['catalog_venue_id'] ?? '') !== '' && ($form['inventory_venue_id'] ?? '') !== '' && $form['catalog_venue_id'] !== $form['inventory_venue_id'])
            <p class="mt-3 text-sm text-amber-800">{{ __('Cijene i zalihe trenutno su usmjerene na različita Wolt prodajna mjesta. Provjerite je li to namjeravano.') }}</p>
        @endif
    </section>

    <section class="admin-panel p-6">
        <form wire:submit="save" class="space-y-6">
            <div class="grid gap-4 md:grid-cols-2">
                <label class="block text-sm font-semibold text-slate-700">{{ __('Okruženje') }}
                    <select wire:model="form.environment" class="mt-1 w-full rounded-xl border-slate-300 text-sm"><option value="stage">{{ __('Wolt test') }}</option><option value="production">{{ __('Wolt produkcija') }}</option></select>
                </label>
                <label class="block text-sm font-semibold text-slate-700">{{ __('Wolt prodajno mjesto za cijene (inventory.php)') }}
                    <input wire:model="form.catalog_venue_id" type="text" maxlength="128" class="mt-1 w-full rounded-xl border-slate-300 text-sm">
                </label>
                <label class="block text-sm font-semibold text-slate-700">{{ __('Wolt prodajno mjesto za zalihe (index.php)') }}
                    <input wire:model="form.inventory_venue_id" type="text" maxlength="128" class="mt-1 w-full rounded-xl border-slate-300 text-sm">
                </label>
                <label class="block text-sm font-semibold text-slate-700">{{ __('Način prijave') }}
                    <select wire:model.live="form.auth_type" class="mt-1 w-full rounded-xl border-slate-300 text-sm"><option value="legacy_basic">{{ __('Naslijeđeni korisnik i lozinka') }}</option><option value="bearer">{{ __('OAuth pristupni token') }}</option></select>
                </label>
                <label class="block text-sm font-semibold text-slate-700">{{ __('Šifra za povezivanje s Wolt artiklom') }}
                    <select wire:model="form.sku_field" class="mt-1 w-full rounded-xl border-slate-300 text-sm"><option value="opencart_model">{{ __('Izvorni OpenCart model (stara Wolt veza)') }}</option><option value="sku">{{ __('SKU proizvoda') }}</option><option value="code">{{ __('Šifra proizvoda') }}</option><option value="barcode">{{ __('Barkod kao Wolt SKU') }}</option><option value="source_product_id">{{ __('Izvorni OpenCart ID kao Wolt SKU') }}</option><option value="explicit">{{ __('Posebno mapirana Wolt šifra') }}</option></select>
                </label>
                <label class="block text-sm font-semibold text-slate-700">{{ __('Proizvodi za izvoz') }}
                    <select wire:model.live="form.scope" class="mt-1 w-full rounded-xl border-slate-300 text-sm"><option value="source_imported">{{ __('Svi proizvodi uvezeni iz stare Watchline trgovine') }}</option><option value="selected">{{ __('Samo ručno odabrani proizvodi') }}</option></select>
                </label>
                <label class="block text-sm font-semibold text-slate-700">{{ __('Cijena za Wolt') }}
                    <select wire:model="form.price_basis" class="mt-1 w-full rounded-xl border-slate-300 text-sm"><option value="stored_base">{{ __('Osnovna cijena proizvoda (stara Wolt veza)') }}</option><option value="current_gross">{{ __('Trenutna javna cijena s akcijama i PDV-om') }}</option></select>
                </label>
                <label class="block text-sm font-semibold text-slate-700">{{ __('Vidljivost i zalihe') }}
                    <select wire:model="form.availability_basis" class="mt-1 w-full rounded-xl border-slate-300 text-sm"><option value="stock_quantity">{{ __('Količina zalihe, neovisno o statusu proizvoda (stara veza)') }}</option><option value="product_status">{{ __('Aktivnost proizvoda; neaktivnima postavi zalihu 0') }}</option></select>
                </label>
            </div>
            <label class="flex items-start gap-3 text-sm text-slate-700"><input type="checkbox" wire:model="form.include_vat" class="mt-0.5 rounded border-slate-300"><span>{{ __('Uključi zasebno polje postotka PDV-a. Izvorna Watchline Wolt veza ga ne šalje.') }}</span></label>
            <p class="text-sm text-slate-500">{{ __('Naslijeđena biblioteka koristi korisnika i lozinku. Aktualni Wolt API koristi OAuth; potvrdu odgovarajućeg načina prijave daje Wolt. Pristupni token vrijedi ograničeno vrijeme i treba ga obnoviti prije isteka.') }}</p>
            <div class="grid gap-4 md:grid-cols-2">
                @if (($form['auth_type'] ?? '') === 'legacy_basic')
                    <label class="block text-sm font-semibold text-slate-700">{{ __('Wolt korisnik') }}<input wire:model="credentials.username" type="password" autocomplete="new-password" maxlength="1024" class="mt-1 w-full rounded-xl border-slate-300 text-sm"></label>
                    <label class="block text-sm font-semibold text-slate-700">{{ __('Wolt lozinka') }}<input wire:model="credentials.password" type="password" autocomplete="new-password" maxlength="4096" class="mt-1 w-full rounded-xl border-slate-300 text-sm"></label>
                @else
                    <label class="block text-sm font-semibold text-slate-700 md:col-span-2">{{ __('OAuth pristupni token') }}<input wire:model="credentials.access_token" type="password" autocomplete="new-password" maxlength="8192" class="mt-1 w-full rounded-xl border-slate-300 text-sm"></label>
                    <label class="block text-sm font-semibold text-slate-700">{{ __('Token vrijedi do (ako token nema ugrađen rok)') }}<input wire:model="form.access_token_expires_at" type="datetime-local" class="mt-1 w-full rounded-xl border-slate-300 text-sm"></label>
                @endif
            </div>
            <p class="text-xs text-slate-500">{{ $credentialsConfigured ? __('Pristupni podaci su spremljeni šifrirano. Prazna polja ih zadržavaju.') : __('Pristupni podaci još nisu spremljeni. Spremaju se šifrirano i ne prikazuju ponovno.') }}</p>

            <div class="border-t border-slate-200 pt-5">
                <h2 class="text-base font-semibold text-slate-900">{{ __('Proizvodi u postojećem Wolt asortimanu') }}</h2>
                @if (($form['scope'] ?? '') === 'source_imported')
                    <p class="mt-3 text-sm text-slate-600">{{ __('Izvoz obuhvaća :count uvezenih proizvoda, uključujući neaktivne, kao izvorna veza. Nove proizvode treba prvo dodati u Wolt asortiman.', ['count' => $sourceProductCount]) }}</p>
                @else
                <label for="wolt-product-search" class="mt-3 block text-sm text-slate-700">{{ __('Pronađite proizvod po nazivu ili šifri') }}</label>
                <input id="wolt-product-search" wire:model.live.debounce.300ms="productSearch" type="search" maxlength="100" class="mt-1 w-full max-w-xl rounded-xl border-slate-300 text-sm" placeholder="{{ __('Unesite barem 2 znaka') }}">
                @if ($searchProducts->isNotEmpty())
                    <ul class="mt-2 max-h-72 max-w-xl overflow-y-auto rounded-xl border border-slate-200 divide-y divide-slate-100">
                        @foreach ($searchProducts as $product)
                            <li wire:key="wolt-search-{{ $product->id }}"><button type="button" wire:click="addProduct({{ $product->id }})" class="w-full px-3 py-2 text-left text-sm hover:bg-slate-50"><strong>{{ $product->code }}</strong> — {{ $product->translations->firstWhere('locale', app()->getLocale())?->name ?? $product->translations->first()?->name }}</button></li>
                        @endforeach
                    </ul>
                @endif
                <p class="mt-3 text-sm text-slate-600">{{ __('Odabrano proizvoda: :count', ['count' => $selectedProducts->count()]) }}</p>
                <div class="mt-2 max-h-80 overflow-y-auto rounded-xl border border-slate-200 divide-y divide-slate-100">
                    @forelse ($selectedProducts as $product)
                        <div wire:key="wolt-selected-{{ $product->id }}" class="flex items-center justify-between gap-4 px-3 py-2 text-sm"><span><strong>{{ $product->code }}</strong> — {{ $product->translations->firstWhere('locale', app()->getLocale())?->name ?? $product->translations->first()?->name }}</span><button type="button" wire:click="removeProduct({{ $product->id }})" class="shrink-0 text-rose-700">{{ __('Ukloni') }}</button></div>
                    @empty
                        <p class="px-3 py-3 text-sm text-slate-500">{{ __('Nijedan proizvod nije odabran. Bez odabira nema izvoza.') }}</p>
                    @endforelse
                </div>
                @endif
            </div>

            <div class="space-y-3 border-t border-slate-200 pt-5 text-sm text-slate-700">
                <label class="flex items-start gap-3"><input type="checkbox" wire:model="form.mapping_confirmed" class="mt-0.5 rounded border-slate-300"><span>{{ __('Potvrđeno je da odabrane šifre i proizvodi odgovaraju postojećem Wolt asortimanu.') }}</span></label>
                <label class="flex items-start gap-3"><input type="checkbox" wire:model="form.auth_confirmed" class="mt-0.5 rounded border-slate-300"><span>{{ __('Potvrđeni su način prijave i prodajno mjesto za ovu Wolt integraciju.') }}</span></label>
                <label class="flex items-start gap-3"><input type="checkbox" wire:model="form.send_enabled" class="mt-0.5 rounded border-slate-300"><span>{{ __('Dopusti slanje na aktivnoj stranici. Lokalni sigurnosni način i dalje sprječava sve vanjske izmjene.') }}</span></label>
            </div>
            <button type="submit" wire:loading.attr="disabled" wire:target="save" class="rounded-xl bg-cyan-700 px-5 py-3 text-sm font-semibold text-white disabled:opacity-50">{{ __('Spremi Wolt postavke') }}</button>
        </form>
    </section>

    <section class="admin-panel p-6">
        <h2 class="text-base font-semibold text-slate-900">{{ __('Pregled spremljenog izvoza') }}</h2>
        <p class="mt-2 text-sm text-slate-600">{{ __('Pregled koristi spremljene postavke i prikazuje cijene u centima. Zalihe su fizička količina trgovine, bez zaliha dobavljača. Oznaka in_stock ne šalje se jer Wolt zalihe vodi brojčano.') }}</p>
        <div class="mt-4 flex flex-wrap gap-3"><button type="button" wire:click="previewPayload('catalog')" wire:loading.attr="disabled" class="rounded-xl border border-slate-300 px-4 py-2 text-sm font-semibold text-slate-800">{{ __('Pregled cijena i vidljivosti') }}</button><button type="button" wire:click="previewPayload('inventory')" wire:loading.attr="disabled" class="rounded-xl border border-slate-300 px-4 py-2 text-sm font-semibold text-slate-800">{{ __('Pregled zaliha') }}</button></div>
        @if ($blockers !== [])
            <ul class="mt-4 list-disc space-y-1 pl-5 text-sm text-amber-800">@foreach ($blockers as $blocker)<li>{{ __($blocker) }}</li>@endforeach</ul>
        @endif
        @if ($preview)
            <p class="mt-5 text-sm font-semibold text-slate-800">{{ __('Valjanih stavki: :count od :selected', ['count' => $preview['item_count'], 'selected' => $preview['selected_count']]) }}</p>
            @if ($preview['errors'] !== [])<ul class="mt-3 list-disc space-y-1 pl-5 text-sm text-rose-700">@foreach ($preview['errors'] as $error)<li>{{ $error }}</li>@endforeach</ul>@endif
            @if ($preview['warnings'] !== [])<ul class="mt-3 list-disc space-y-1 pl-5 text-sm text-amber-800">@foreach ($preview['warnings'] as $warning)<li>{{ $warning }}</li>@endforeach</ul>@endif
            @foreach ($preview['conflicts'] as $conflict)
                <div class="mt-4 rounded-xl border border-amber-200 bg-amber-50 p-4">
                    <h3 class="text-sm font-semibold text-amber-950">{{ __('Preskočena Wolt šifra: :sku', ['sku' => $conflict['sku']]) }}</h3>
                    <p class="mt-1 text-sm text-amber-900">{{ __('Cijela šifra je izostavljena iz ovog izvoza; ostali ispravni proizvodi mogu se poslati. Provjerite ove proizvode i uskladite podatke ili zasebno mapirajte Wolt šifru.') }}</p>
                    <ul class="mt-3 space-y-2 text-sm text-amber-950">
                        @foreach ($conflict['products'] as $product)
                            <li><a href="{{ $product['edit_url'] }}" class="font-semibold underline">{{ $product['code'] }} · #{{ $product['product_id'] }}</a> · {{ __('OpenCart ID :id', ['id' => $product['source_product_id'] ?? '—']) }} · {{ number_format($product['base_price'], 2, ',', '.') }} € · {{ __('Zaliha: :qty', ['qty' => $product['stock_qty']]) }} · {{ $product['is_active'] ? __('Aktivan') : __('Neaktivan') }}</li>
                        @endforeach
                    </ul>
                </div>
            @endforeach
            <p class="mt-3 text-xs text-slate-500">{{ __('Prikazano je prvih 25 stavki. Pregled ne mijenja Wolt ni podatke trgovine.') }}</p>
            <pre class="mt-2 max-h-96 overflow-auto rounded-xl bg-slate-50 p-4 text-xs text-slate-800">{{ json_encode($preview['payload'], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) }}</pre>
        @endif
    </section>
</div>
