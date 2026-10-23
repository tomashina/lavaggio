<div class="space-y-6">
    <div class="flex items-center justify-between"><h1 class="text-2xl font-semibold">Bundle ponude</h1><button wire:click="create" class="rounded-lg bg-slate-900 px-4 py-2 text-white">Nova bundle ponuda</button></div>
    @if(session('bundle_status'))<div role="status" class="rounded-lg bg-green-50 p-4 text-green-800">{{ session('bundle_status') }}</div>@endif
    @if($editing)
    <form wire:submit="save" class="space-y-5 rounded-xl border bg-white p-6">
        <h2 class="text-xl font-semibold">{{ $editingId ? 'Uredi bundle ponudu' : 'Nova bundle ponuda' }}</h2>
        @if($errors->any())<div role="alert" class="text-red-700">@foreach($errors->all() as $error)<p>{{ $error }}</p>@endforeach</div>@endif
        <label class="block">Naziv<input wire:model="title" required class="mt-1 block w-full rounded-lg border-slate-300"></label>
        <div class="grid gap-4 md:grid-cols-3">
            <label>Vrijedi od<input type="datetime-local" wire:model="startsAt" class="mt-1 block w-full rounded-lg border-slate-300"></label>
            <label>Vrijedi do<input type="datetime-local" wire:model="endsAt" class="mt-1 block w-full rounded-lg border-slate-300"></label>
            <label>Redoslijed<input type="number" min="0" wire:model="sortOrder" class="mt-1 block w-full rounded-lg border-slate-300"></label>
        </div>
        <label class="flex items-center gap-2"><input type="checkbox" wire:model="isActive"> Aktivna ponuda</label>
        <h3 class="font-semibold">Artikli u kompletu</h3>
        <label class="block">Pronađi artikl po nazivu ili šifri<input wire:model.live.debounce.300ms="productSearch" class="mt-1 block w-full rounded-lg border-slate-300" placeholder="Najmanje 2 znaka"></label>
        @foreach($productResults as $product)<button type="button" wire:click="addProduct({{ $product->id }})" class="block w-full rounded border p-2 text-left">+ {{ $product->translations->firstWhere('locale','hr')?->name ?? $product->translations->first()?->name }} · {{ $product->sku }}</button>@endforeach
        @foreach($products as $index => $row)
            <div wire:key="bundle-product-{{ $row['product_id'] }}" class="flex items-center gap-3 rounded-lg bg-slate-50 p-3">
                <span class="flex-1">{{ $selectedProducts[$row['product_id']]?->translations->firstWhere('locale','hr')?->name ?? $selectedProducts[$row['product_id']]?->sku ?? $row['product_id'] }}</span>
                <label>Količina <input aria-label="Količina artikla" type="number" min="1" max="999" wire:model="products.{{ $index }}.quantity" class="w-24 rounded-lg border-slate-300"></label>
                <button type="button" wire:click="removeProduct({{ $index }})" class="text-red-700">Ukloni</button>
            </div>
        @endforeach
        <h3 class="font-semibold">{{ config('commerce.b2c_only') ? 'Popust na komplet' : 'Popusti po grupi kupaca' }}</h3>
        <p class="text-sm text-slate-600">Popust vrijedi kada su svi artikli kompleta u košarici u zadanim količinama. Fiksni iznos je popust na cijeli komplet.</p>
        @foreach($discounts as $index => $discount)
            <div wire:key="bundle-discount-{{ $index }}" class="flex flex-wrap items-center gap-3">
                @unless(config('commerce.b2c_only'))
                <select aria-label="Grupa kupaca" wire:model="discounts.{{ $index }}.customer_group_id" class="rounded-lg border-slate-300"><option value="">Odaberi grupu</option>@foreach($groups as $group)<option value="{{ $group->id }}">{{ $group->name }}</option>@endforeach</select>
                @endunless
                <select aria-label="Vrsta popusta" wire:model="discounts.{{ $index }}.type" class="rounded-lg border-slate-300"><option value="percent">Postotak (%)</option><option value="fixed">Fiksni iznos (€)</option></select>
                <input aria-label="Vrijednost popusta" type="number" min="0" step="0.01" wire:model="discounts.{{ $index }}.value" class="w-28 rounded-lg border-slate-300">
                @unless(config('commerce.b2c_only'))<button type="button" wire:click="removeDiscount({{ $index }})" class="text-red-700">Ukloni</button>@endunless
            </div>
        @endforeach
        @unless(config('commerce.b2c_only'))<button type="button" wire:click="addDiscount" class="text-blue-700">+ Dodaj grupu kupaca</button>@endunless
        <div class="flex gap-3"><button type="submit" wire:loading.attr="disabled" class="rounded-lg bg-slate-900 px-5 py-2 text-white">Spremi ponudu</button><button type="button" wire:click="$set('editing', false)" class="rounded-lg border px-5 py-2">Odustani</button></div>
    </form>
    @endif
    <label class="block">Pretraži ponude<input wire:model.live.debounce.300ms="search" class="mt-1 block w-full rounded-lg border-slate-300" placeholder="Naziv ponude"></label>
    <div class="overflow-x-auto rounded-xl border bg-white"><table class="w-full text-left"><thead class="bg-slate-50"><tr><th class="p-3">Naziv</th><th class="p-3">Artikli</th><th class="p-3">Status</th><th class="p-3">Vrijedi do</th><th class="p-3">Upravljanje</th></tr></thead><tbody>
        @forelse($bundles as $bundle)<tr class="border-t"><td class="p-3">{{ $bundle->title }}</td><td class="p-3">{{ $bundle->products_count }}</td><td class="p-3">{{ !$bundle->is_active ? 'Isključena' : ($bundle->ends_at?->isPast() ? 'Istekla' : ($bundle->starts_at?->isFuture() ? 'Zakazana' : 'Aktivna')) }}</td><td class="p-3">{{ $bundle->ends_at?->format('d.m.Y.') ?? 'Bez ograničenja' }}</td><td class="space-x-3 p-3"><button wire:click="edit({{ $bundle->id }})" class="text-blue-700">Uredi</button><button wire:click="delete({{ $bundle->id }})" wire:confirm="Obrisati ovu bundle ponudu?" class="text-red-700">Obriši</button></td></tr>@empty<tr><td colspan="5" class="p-6 text-center">Nema bundle ponuda.</td></tr>@endforelse
    </tbody></table></div>
    {{ $bundles->links() }}
</div>
