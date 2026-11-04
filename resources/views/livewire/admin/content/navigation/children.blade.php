<div class="mt-4 space-y-3 border-l-2 border-slate-200 pl-2 sm:pl-4">
    <div class="flex flex-wrap items-center gap-2">
        <span class="mr-2 text-sm font-semibold text-slate-600">Podstavke izbornika</span>
        @if ($navigationDepth < 3)
            <button type="button" wire:click="addChildItem('{{ $parentPath }}', 'page')" class="rounded-lg border border-slate-300 px-3 py-1 text-xs font-semibold text-slate-700">+ Stranica</button>
            <button type="button" wire:click="addChildItem('{{ $parentPath }}', 'custom')" class="rounded-lg border border-slate-300 px-3 py-1 text-xs font-semibold text-slate-700">+ Poveznica</button>
        @endif
    </div>
    @foreach ($navigationChildren as $childIndex => $navigationChild)
        @php
            $childPath = $parentPath.'.children.'.$childIndex;
            $childExpanded = ! empty($expandedItems[$childPath]);
            $childCaption = $navigationItemLabel($navigationChild);
            $childDetailsId = 'navigation-item-details-'.str_replace('.', '-', $childPath);
        @endphp
        <div class="overflow-hidden rounded-xl border border-slate-200 bg-white" wire:key="navigation-child-{{ $childPath }}-{{ $navigationChild['type'] ?? '' }}-{{ $navigationChild['page_id'] ?? 0 }}">
            <div class="flex flex-wrap items-center gap-2 px-3 py-3">
                <button type="button" wire:click="toggleItem('{{ $childPath }}')" aria-expanded="{{ $childExpanded ? 'true' : 'false' }}" aria-controls="{{ $childDetailsId }}" class="flex w-full min-w-0 flex-wrap items-center gap-2 text-left sm:w-auto sm:flex-1">
                    <svg class="h-4 w-4 shrink-0 text-slate-500" style="transform: rotate({{ $childExpanded ? '90' : '0' }}deg)" viewBox="0 0 20 20" fill="none" stroke="currentColor" aria-hidden="true"><path d="m7 4 6 6-6 6" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" /></svg>
                    <span class="min-w-0 break-words text-sm font-semibold text-slate-900">{{ $childCaption }}</span>
                    @if (! empty($navigationChild['children']))<span class="admin-chip shrink-0">{{ __('admin.content.navigation.children_count', ['count' => count($navigationChild['children'])]) }}</span>@endif
                    @if (! ($navigationChild['is_active'] ?? false))<span class="admin-chip shrink-0">{{ __('admin.common.inactive') }}</span>@endif
                </button>
                <div class="ml-auto flex shrink-0 gap-1">
                    <button type="button" wire:click="moveNestedItem('{{ $childPath }}', -1)" @disabled($loop->first) class="rounded-lg border border-slate-300 px-2 py-1 text-xs disabled:opacity-30" aria-label="{{ __('admin.content.navigation.move_up', ['name' => $childCaption]) }}">↑</button>
                    <button type="button" wire:click="moveNestedItem('{{ $childPath }}', 1)" @disabled($loop->last) class="rounded-lg border border-slate-300 px-2 py-1 text-xs disabled:opacity-30" aria-label="{{ __('admin.content.navigation.move_down', ['name' => $childCaption]) }}">↓</button>
                    <button type="button" wire:click="removeNestedItem('{{ $childPath }}')" class="rounded-lg border border-rose-200 px-2 py-1 text-xs text-rose-700">{{ __('admin.content.navigation.remove') }}</button>
                </div>
            </div>
            @if ($childExpanded)
            <div id="{{ $childDetailsId }}" class="border-t border-slate-200 p-3">
            <div class="grid gap-3 lg:grid-cols-[8rem_1fr_1fr] lg:items-end">
                <div>
                    <label class="mb-1 block text-xs font-semibold text-slate-500">Vrsta</label>
                    <select wire:model.live="form.items.{{ $childPath }}.type" class="admin-search-input w-full rounded-xl border px-3 py-2 text-sm">
                        @foreach (['page' => 'Stranica', 'custom' => 'Poveznica', 'faq' => 'Česta pitanja', 'blog' => 'Blog', 'contact' => 'Kontakt'] as $value => $caption)
                            <option value="{{ $value }}">{{ $caption }}</option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <label class="mb-1 block text-xs font-semibold text-slate-500">Naziv ({{ strtoupper($locale) }})</label>
                    <input wire:model.live="form.items.{{ $childPath }}.label" type="text" class="admin-search-input w-full rounded-xl border px-3 py-2 text-sm">
                    @error('form.items.'.$childPath.'.label')<p class="mt-1 text-xs text-rose-600">{{ $message }}</p>@enderror
                </div>
                <div>
                    @if (($navigationChild['type'] ?? 'page') === 'page')
                        <label class="mb-1 block text-xs font-semibold text-slate-500">Stranica</label>
                        <select wire:model.live="form.items.{{ $childPath }}.page_id" class="admin-search-input w-full rounded-xl border px-3 py-2 text-sm">
                            <option value="0">Odaberi stranicu</option>
                            @foreach ($pageOptions as $option)
                                <option value="{{ $option['id'] }}">{{ $option['label'] }}</option>
                            @endforeach
                        </select>
                        @error('form.items.'.$childPath.'.page_id')<p class="mt-1 text-xs text-rose-600">{{ $message }}</p>@enderror
                    @elseif (($navigationChild['type'] ?? '') === 'custom')
                        <label class="mb-1 block text-xs font-semibold text-slate-500">URL</label>
                        <input wire:model.live="form.items.{{ $childPath }}.url" type="text" class="admin-search-input w-full rounded-xl border px-3 py-2 text-sm" placeholder="/stranica">
                        @error('form.items.'.$childPath.'.url')<p class="mt-1 text-xs text-rose-600">{{ $message }}</p>@enderror
                    @endif
                </div>
            </div>
            <div class="mt-3 flex flex-wrap gap-4 text-xs font-semibold text-slate-700">
                <label><input type="checkbox" wire:model.live="form.items.{{ $childPath }}.is_active" class="mr-1 rounded border-slate-300">Aktivno</label>
                <label><input type="checkbox" wire:model.live="form.items.{{ $childPath }}.show_dropdown" class="mr-1 rounded border-slate-300">Prikaži podstavke</label>
                <label><input type="checkbox" wire:model.live="form.items.{{ $childPath }}.open_in_new_tab" class="mr-1 rounded border-slate-300">Novi prozor</label>
            </div>
            @if (config('storefront.home') === 'lavaggio' && ! empty($navigationChild['children']))
                @php
                    $menuUploadKey = str_replace('.', '_', $childPath);
                    $menuImageUrl = app(\App\Services\Front\NavigationMenuService::class)->categoryMenuImageUrl((string) ($navigationChild['desktop_promo_image_path'] ?? ''));
                    if (isset($desktopPromoUploads[$menuUploadKey]) && $desktopPromoUploads[$menuUploadKey]->isPreviewable()) {
                        $menuImageUrl = $desktopPromoUploads[$menuUploadKey]->temporaryUrl();
                    }
                @endphp
                <div class="mt-3 flex flex-wrap items-center gap-4 rounded-lg border border-slate-200 bg-white p-3">
                    @if ($menuImageUrl !== '')
                        <img src="{{ $menuImageUrl }}" alt="{{ $navigationChild['label'] ?? '' }}" class="h-24 w-32 rounded-lg object-cover">
                    @endif
                    <div class="min-w-0 flex-1">
                        <label class="mb-1 block text-xs font-semibold text-slate-600" for="menu-photo-{{ $menuUploadKey }}">Fotografija u izborniku</label>
                        <input id="menu-photo-{{ $menuUploadKey }}" type="file" wire:model="desktopPromoUploads.{{ $menuUploadKey }}" accept="image/jpeg,image/png,image/webp" class="admin-search-input w-full rounded-xl border px-3 py-2 text-sm">
                        @error('desktopPromoUploads.'.$menuUploadKey)<p class="mt-1 text-xs text-rose-600">{{ $message }}</p>@enderror
                        @if ($menuImageUrl !== '')
                            <button type="button" wire:click="removeNestedMenuImage('{{ $childPath }}')" class="mt-2 text-xs font-semibold text-rose-700">Ukloni fotografiju</button>
                        @endif
                    </div>
                </div>
            @endif
            @if ($navigationDepth < 2 || ! empty($navigationChild['children']))
                @include('livewire.admin.content.navigation.children', ['navigationChildren' => $navigationChild['children'] ?? [], 'parentPath' => $childPath, 'navigationDepth' => $navigationDepth + 1])
            @endif
            </div>
            @endif
        </div>
    @endforeach
</div>
