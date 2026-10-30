<div class="space-y-6">
    <div class="admin-panel admin-form-panel p-6">
        <div class="flex flex-wrap items-start justify-between gap-4">
            <div>
                <h2 class="admin-section-title">{{ __('Akcije za ovaj artikl') }}</h2>
                <p class="mt-2 text-sm text-slate-600">{{ __('Ovdje možete urediti jednostavne akcije koje vrijede samo za ovaj artikl. Zajedničke i složene akcije uređuju se u upravitelju akcija.') }}</p>
                <p class="mt-2 text-sm text-slate-600">{{ __('Akcija se sprema zasebno od ostalih podataka artikla.') }}</p>
            </div>
            @if ($canCreate)
                <button type="button" wire:click="create" class="rounded-xl bg-cyan-700 px-4 py-2 text-sm font-semibold text-white hover:bg-cyan-800">{{ __('Nova akcija za ovaj artikl') }}</button>
            @endif
        </div>
        <div class="mt-4 rounded-xl border border-slate-200 bg-slate-50 p-4">
            <p class="text-xs font-semibold uppercase tracking-[0.12em] text-slate-500">{{ __('Javna cijena sada') }}</p>
            @if ($price['can_view_price'])
                <p class="mt-1 text-xl font-semibold text-slate-900">
                    {{ number_format($price['current_gross'], 2, ',', '.') }} €
                    @if ($price['has_promotional_discount'] && $price['old_gross'] !== null)
                        <span class="ml-2 text-sm font-normal text-slate-500 line-through">{{ number_format($price['old_gross'], 2, ',', '.') }} €</span>
                    @endif
                </p>
            @else
                <p class="mt-1 text-sm text-slate-600">{{ __('Javni prikaz cijena trenutačno nije omogućen.') }}</p>
            @endif
            <p class="mt-1 text-xs text-slate-500">{{ __('Prikaz za kupca bez prijave i kupona. Ažurira se nakon spremanja akcije; zakazana akcija primjenjuje se u svom razdoblju.') }}</p>
        </div>
    </div>

    @if ($editing)
        <form wire:submit="save" class="admin-panel admin-form-panel space-y-4 p-6">
            <h2 class="admin-section-title">{{ $editingId ? __('Uredi akciju') : __('Nova akcija') }}</h2>
            @if (config('storefront.home') === 'lavaggio')
                <p class="text-xs text-slate-500">{{ __('Početak i završetak akcije unose se prema lokalnom vremenu u Hrvatskoj (Europe/Zagreb).') }}</p>
            @endif
            <div>
                <label for="product-promotion-title" class="mb-1 block text-sm font-semibold text-slate-700">{{ __('Naziv akcije') }}</label>
                <input id="product-promotion-title" wire:model="form.title" type="text" maxlength="255" class="w-full rounded-xl border border-slate-300 px-3 py-2 text-sm">
                @error('form.title') <p class="mt-1 text-xs text-rose-600">{{ $message }}</p> @enderror
            </div>
            <div class="grid gap-4 sm:grid-cols-2">
                <div>
                    <label for="product-promotion-type" class="mb-1 block text-sm font-semibold text-slate-700">{{ __('Vrsta popusta') }}</label>
                    <select id="product-promotion-type" wire:model.live="form.type" class="w-full rounded-xl border border-slate-300 px-3 py-2 text-sm">
                        <option value="percentage">{{ __('Postotni popust (%)') }}</option>
                        <option value="fixed_amount">{{ __('Popust u eurima (€)') }}</option>
                    </select>
                    @error('form.type') <p class="mt-1 text-xs text-rose-600">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label for="product-promotion-value" class="mb-1 block text-sm font-semibold text-slate-700">{{ $form['type'] === 'percentage' ? __('Popust (%)') : __('Iznos popusta (€)') }}</label>
                    <input id="product-promotion-value" wire:model="form.discount_value" type="number" min="0.01" step="0.01" @if ($form['type'] === 'percentage') max="100" @endif class="w-full rounded-xl border border-slate-300 px-3 py-2 text-sm">
                    @error('form.discount_value') <p class="mt-1 text-xs text-rose-600">{{ $message }}</p> @enderror
                    <p class="mt-1 text-xs text-slate-500">{{ __('Popust se obračunava od osnovne cijene artikla. Najpovoljniju primjenjivu akciju odabire trgovina.') }}</p>
                </div>
                <div>
                    <label for="product-promotion-start" class="mb-1 block text-sm font-semibold text-slate-700">{{ __('Vrijedi od') }}</label>
                    <input id="product-promotion-start" wire:model="form.starts_at" type="datetime-local" step="1" class="w-full rounded-xl border border-slate-300 px-3 py-2 text-sm">
                    @error('form.starts_at') <p class="mt-1 text-xs text-rose-600">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label for="product-promotion-end" class="mb-1 block text-sm font-semibold text-slate-700">{{ __('Vrijedi do') }}</label>
                    <input id="product-promotion-end" wire:model="form.ends_at" type="datetime-local" step="1" class="w-full rounded-xl border border-slate-300 px-3 py-2 text-sm">
                    @error('form.ends_at') <p class="mt-1 text-xs text-rose-600">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label for="product-promotion-priority" class="mb-1 block text-sm font-semibold text-slate-700">{{ __('Prioritet') }}</label>
                    <input id="product-promotion-priority" wire:model="form.priority" type="number" min="0" step="1" class="w-full rounded-xl border border-slate-300 px-3 py-2 text-sm">
                    @error('form.priority') <p class="mt-1 text-xs text-rose-600">{{ $message }}</p> @enderror
                </div>
                <div class="flex flex-col justify-center gap-3">
                    <label class="flex items-center gap-2 text-sm text-slate-700"><input wire:model="form.is_active" type="checkbox" class="rounded border-slate-300 text-cyan-700"> {{ __('Akcija je aktivna') }}</label>
                    <label class="flex items-center gap-2 text-sm text-slate-700"><input wire:model="form.is_exclusive" type="checkbox" class="rounded border-slate-300 text-cyan-700"> {{ __('Isključiva akcija') }}</label>
                    @error('form.is_active') <p class="text-xs text-rose-600">{{ $message }}</p> @enderror
                    @error('form.is_exclusive') <p class="text-xs text-rose-600">{{ $message }}</p> @enderror
                </div>
            </div>
            <div class="flex flex-wrap items-center gap-2">
                <button type="submit" wire:loading.attr="disabled" class="rounded-xl bg-cyan-700 px-4 py-2 text-sm font-semibold text-white hover:bg-cyan-800 disabled:opacity-50">{{ __('Spremi akciju') }}</button>
                <button type="button" wire:click="cancel" class="rounded-xl border border-slate-300 bg-white px-4 py-2 text-sm font-semibold text-slate-700 hover:bg-slate-100">{{ __('Odustani') }}</button>
            </div>
        </form>
    @endif

    <div class="admin-panel admin-form-panel divide-y divide-slate-200 overflow-hidden">
        @forelse ($promotions as $promotion)
            <article wire:key="product-promotion-{{ $promotion['id'] }}" class="p-5 sm:p-6">
                <div class="flex flex-wrap items-start justify-between gap-3">
                    <div>
                        <h3 class="font-semibold text-slate-900">{{ $promotion['title'] }}</h3>
                        <p class="mt-1 text-sm text-slate-600">{{ $promotion['scope'] }} · {{ $promotion['status'] }} · {{ $promotion['discount'] }}</p>
                        <p class="mt-1 text-xs text-slate-500">{{ $promotion['starts_at'] }} — {{ $promotion['ends_at'] }} @if(config('storefront.home') === 'lavaggio') (Europe/Zagreb) @endif</p>
                    </div>
                    @if ($canUpdate)
                        @if ($promotion['editable'])
                            <button type="button" wire:click="edit({{ $promotion['id'] }})" class="rounded-xl border border-slate-300 bg-white px-3 py-2 text-sm font-semibold text-slate-700 hover:bg-slate-100">{{ __('Uredi ovdje') }}</button>
                        @else
                            <a href="{{ route('admin.actions.edit', ['action' => $promotion['id'], 'locale' => $locale]) }}" class="rounded-xl border border-slate-300 bg-white px-3 py-2 text-sm font-semibold text-slate-700 hover:bg-slate-100">{{ __('Uredi zajedničku ili složenu akciju') }}</a>
                        @endif
                    @endif
                </div>
                @unless ($promotion['editable'])
                    <p class="mt-2 text-xs text-slate-500">{{ __('Ova akcija ima širi opseg ili dodatna pravila. Promjene u upravitelju akcija mogu utjecati i na druge artikle ili kupce.') }}</p>
                @endunless
            </article>
        @empty
            <p class="p-6 text-sm text-slate-600">{{ __('Za ovaj artikl nema dodijeljenih akcija.') }}</p>
        @endforelse
    </div>
</div>
