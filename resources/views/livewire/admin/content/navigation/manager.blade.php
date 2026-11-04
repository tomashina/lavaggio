<div class="space-y-6">
    @php
        $navigationItemLabel = function (array $item) use ($pageOptions, $categoryOptions): string {
            $label = trim((string) ($item['label'] ?? ''));
            if ($label !== '') return $label;
            $type = (string) ($item['type'] ?? 'custom');
            if ($type === 'page') $label = (string) (collect($pageOptions)->firstWhere('id', (int) ($item['page_id'] ?? 0))['label'] ?? '');
            if ($type === 'category') $label = (string) (collect($categoryOptions)->firstWhere('id', (int) ($item['category_id'] ?? 0))['label'] ?? '');
            return $label !== '' ? $label : (string) __('admin.content.navigation.type_'.$type);
        };
        $navigationTabs = ['menu' => __('admin.content.navigation.tab_menu')];
        if (config('storefront.home') === 'lavaggio') $navigationTabs['images'] = __('admin.content.navigation.tab_images');
        $navigationTabs['appearance'] = __('admin.content.navigation.tab_appearance');
        $navigationTabs['top_bar'] = __('admin.content.navigation.tab_top_bar');
    @endphp
    <div class="admin-panel admin-search-panel p-6">
        <div class="flex flex-col gap-4 lg:flex-row lg:items-end lg:justify-between">
            <div>
                <h1 class="text-xl font-semibold tracking-tight">{{ __('admin.content.navigation.title') }}</h1>
                <p class="mt-1 text-sm text-slate-600">{{ __('admin.content.navigation.subtitle') }}</p>
            </div>
            <div class="flex flex-wrap items-end gap-3">
                <div class="w-28">
                    <label for="navigation-locale" class="mb-1 block text-xs font-semibold uppercase tracking-[0.14em] text-slate-500">{{ __('admin.common.locale') }}</label>
                    <select id="navigation-locale" wire:model.live="locale" data-tom-select data-tom-no-search="1" class="admin-search-input admin-select w-full rounded-xl border px-3 py-2 text-sm lowercase">
                        @foreach ($adminLocaleOptions as $localeOption)
                            <option value="{{ $localeOption }}">{{ $localeOption }}</option>
                        @endforeach
                    </select>
                </div>
                <button type="button" wire:click="save" wire:loading.attr="disabled" wire:target="save,categoryMenuUploads,desktopPromoUploads" class="rounded-xl bg-cyan-700 px-4 py-2 text-sm font-semibold text-white hover:bg-cyan-800 disabled:opacity-50">{{ __('admin.content.navigation.save') }}</button>
            </div>
        </div>
    </div>

    <div class="admin-panel p-2">
        <div
            class="flex flex-wrap gap-2"
            role="tablist"
            aria-label="{{ __('admin.content.navigation.editor_sections') }}"
            x-data
            x-on:keydown.arrow-right.prevent="const tabs = Array.from($el.querySelectorAll('[role=tab]')); const next = tabs[(tabs.indexOf(document.activeElement) + 1) % tabs.length]; next.focus(); next.click()"
            x-on:keydown.arrow-left.prevent="const tabs = Array.from($el.querySelectorAll('[role=tab]')); const next = tabs[(tabs.indexOf(document.activeElement) + tabs.length - 1) % tabs.length]; next.focus(); next.click()"
            x-on:keydown.home.prevent="const first = $el.querySelector('[role=tab]'); first.focus(); first.click()"
            x-on:keydown.end.prevent="const tabs = $el.querySelectorAll('[role=tab]'); const last = tabs[tabs.length - 1]; last.focus(); last.click()"
        >
            @foreach ($navigationTabs as $tab => $caption)
                <button type="button" role="tab" id="navigation-tab-{{ $tab }}" aria-controls="navigation-panel-{{ $tab }}" aria-selected="{{ $activeTab === $tab ? 'true' : 'false' }}" tabindex="{{ $activeTab === $tab ? '0' : '-1' }}" wire:click="setTab('{{ $tab }}')" class="rounded-lg px-4 py-2 text-sm font-semibold transition {{ $activeTab === $tab ? 'bg-slate-900 text-white' : 'text-slate-600 hover:bg-slate-100' }}">{{ $caption }}</button>
            @endforeach
        </div>
    </div>

    @if ($errors->any())
        <div class="rounded-xl border border-rose-200 bg-rose-50 px-4 py-3 text-sm text-rose-700" role="alert">{{ __('admin.content.navigation.validation_hint') }}</div>
    @endif

    @if ($activeTab === 'menu')
    <div id="navigation-panel-menu" role="tabpanel" aria-labelledby="navigation-tab-menu" class="admin-panel admin-panel-soft p-5">
        <div class="flex flex-wrap items-start justify-between gap-3">
            <div>
                <h2 class="admin-section-title">{{ __('admin.content.navigation.tab_menu') }}</h2>
                <p class="mt-1 text-sm text-slate-600">{{ __('admin.content.navigation.menu_hint') }}</p>
            </div>
            <details class="max-w-full">
                <summary class="cursor-pointer rounded-lg border border-cyan-200 bg-cyan-50 px-3 py-2 text-sm font-semibold text-cyan-800">{{ __('admin.content.navigation.add_item') }}</summary>
                <div class="mt-3 flex flex-wrap gap-2">
                <button type="button" wire:click="addCatalogItem" class="rounded-xl border border-amber-300 bg-amber-50 px-3 py-2 text-xs font-semibold text-amber-900 hover:bg-amber-100">{{ __('admin.content.navigation.add_catalog') }}</button>
                <button type="button" wire:click="addCategoryItem" class="rounded-xl border border-slate-300 px-3 py-2 text-xs font-semibold text-slate-700 hover:bg-slate-100">{{ __('admin.content.navigation.add_category') }}</button>
                <button type="button" wire:click="addPageItem" class="rounded-xl border border-slate-300 px-3 py-2 text-xs font-semibold text-slate-700 hover:bg-slate-100">{{ __('admin.content.navigation.add_page') }}</button>
                <button type="button" wire:click="addBlogItem" class="rounded-xl border border-slate-300 px-3 py-2 text-xs font-semibold text-slate-700 hover:bg-slate-100">{{ __('admin.content.navigation.add_blog') }}</button>
                <button type="button" wire:click="addContactItem" class="rounded-xl border border-slate-300 px-3 py-2 text-xs font-semibold text-slate-700 hover:bg-slate-100">{{ __('admin.content.navigation.add_contact') }}</button>
                <button type="button" wire:click="addFaqItem" class="rounded-xl border border-slate-300 px-3 py-2 text-xs font-semibold text-slate-700 hover:bg-slate-100">{{ __('admin.content.navigation.add_faq') }}</button>
                <button type="button" wire:click="addCustomItem" class="rounded-xl border border-cyan-200 bg-cyan-50 px-3 py-2 text-xs font-semibold text-cyan-800 hover:bg-cyan-100">{{ __('admin.content.navigation.add_custom') }}</button>
                </div>
            </details>
        </div>

        @if (empty($form['items']))
            <div class="mt-4 rounded-xl border border-dashed border-slate-300 bg-white p-6 text-sm text-slate-500">
                {{ __('admin.content.navigation.empty') }}
            </div>
        @else
            <div class="mt-4 space-y-3">
                @foreach ($form['items'] as $index => $item)
                    @php
                        $itemExpanded = ! empty($expandedItems[(string) $index]);
                        $itemCaption = $navigationItemLabel($item);
                    @endphp
                    <div class="overflow-hidden rounded-xl border border-slate-200 bg-white" wire:key="navigation-item-{{ $index }}-{{ $item['type'] ?? '' }}-{{ $item['page_id'] ?? 0 }}-{{ $item['category_id'] ?? 0 }}">
                        <div class="flex flex-wrap items-center gap-2 px-4 py-3">
                            <button type="button" wire:click="toggleItem('{{ $index }}')" aria-expanded="{{ $itemExpanded ? 'true' : 'false' }}" aria-controls="navigation-item-details-{{ $index }}" class="flex w-full min-w-0 flex-wrap items-center gap-3 text-left sm:w-auto sm:flex-1">
                                <svg class="h-4 w-4 shrink-0 text-slate-500" style="transform: rotate({{ $itemExpanded ? '90' : '0' }}deg)" viewBox="0 0 20 20" fill="none" stroke="currentColor" aria-hidden="true"><path d="m7 4 6 6-6 6" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" /></svg>
                                <span class="min-w-0 break-words font-semibold text-slate-900">{{ $itemCaption }}</span>
                                @if (! empty($item['children']))<span class="admin-chip shrink-0">{{ __('admin.content.navigation.children_count', ['count' => count($item['children'])]) }}</span>@endif
                                @if (! ($item['is_active'] ?? false))<span class="admin-chip shrink-0">{{ __('admin.common.inactive') }}</span>@endif
                            </button>
                            <div class="ml-auto flex shrink-0 gap-1">
                                <button type="button" wire:click="moveUp({{ $index }})" @disabled($loop->first) aria-label="{{ __('admin.content.navigation.move_up', ['name' => $itemCaption]) }}" class="rounded-lg border border-slate-300 px-2 py-1 text-xs font-semibold text-slate-700 hover:bg-slate-100 disabled:opacity-30">↑</button>
                                <button type="button" wire:click="moveDown({{ $index }})" @disabled($loop->last) aria-label="{{ __('admin.content.navigation.move_down', ['name' => $itemCaption]) }}" class="rounded-lg border border-slate-300 px-2 py-1 text-xs font-semibold text-slate-700 hover:bg-slate-100 disabled:opacity-30">↓</button>
                                <button type="button" wire:click="removeItem({{ $index }})" class="rounded-lg border border-rose-200 px-2 py-1 text-xs font-semibold text-rose-700 hover:bg-rose-50">{{ __('admin.content.navigation.remove') }}</button>
                            </div>
                        </div>
                        @if ($itemExpanded)
                        <div id="navigation-item-details-{{ $index }}" class="border-t border-slate-200 p-4">
                        <div class="grid gap-3 lg:grid-cols-[10rem_1fr_1fr] lg:items-end">
                            <div>
                                <label class="mb-1 block text-xs font-semibold uppercase tracking-[0.14em] text-slate-500">{{ __('admin.content.navigation.type') }}</label>
                                <select wire:model.live="form.items.{{ $index }}.type" data-tom-select data-tom-no-search="1" class="admin-search-input admin-select w-full rounded-xl border px-3 py-2 text-sm">
                                    <option value="catalog">{{ __('admin.content.navigation.type_catalog') }}</option>
                                    <option value="category">{{ __('admin.content.navigation.type_category') }}</option>
                                    <option value="page">{{ __('admin.content.navigation.type_page') }}</option>
                                    <option value="blog">{{ __('admin.content.navigation.type_blog') }}</option>
                                    <option value="contact">{{ __('admin.content.navigation.type_contact') }}</option>
                                    <option value="faq">{{ __('admin.content.navigation.type_faq') }}</option>
                                    <option value="custom">{{ __('admin.content.navigation.type_custom') }}</option>
                                </select>
                            </div>

                            <div>
                                <label class="mb-1 block text-xs font-semibold uppercase tracking-[0.14em] text-slate-500">{{ __('admin.content.navigation.label') }}</label>
                                <input type="text" wire:model.live="form.items.{{ $index }}.label" class="admin-search-input w-full rounded-xl border px-3 py-2 text-sm" placeholder="{{ __('admin.content.navigation.label_placeholder') }}" />
                                @error('form.items.'.$index.'.label') <p class="mt-1 text-xs text-rose-600">{{ $message }}</p> @enderror
                            </div>

                            @if (($item['type'] ?? '') === 'category')
                                <div>
                                    <label class="mb-1 block text-xs font-semibold uppercase tracking-[0.14em] text-slate-500">{{ __('admin.content.navigation.category') }}</label>
                                    <select wire:model.live="form.items.{{ $index }}.category_id" data-tom-select class="admin-search-input admin-select w-full rounded-xl border px-3 py-2 text-sm">
                                        <option value="0">{{ __('admin.content.navigation.select_category') }}</option>
                                        @foreach ($categoryOptions as $option)
                                            <option value="{{ $option['id'] }}">{{ $option['label'] }}</option>
                                        @endforeach
                                    </select>
                                    @error('form.items.'.$index.'.category_id') <p class="mt-1 text-xs text-rose-600">{{ $message }}</p> @enderror
                                </div>
                            @elseif (($item['type'] ?? '') === 'page')
                                <div>
                                    <label class="mb-1 block text-xs font-semibold uppercase tracking-[0.14em] text-slate-500">{{ __('admin.content.navigation.page') }}</label>
                                    <select wire:model.live="form.items.{{ $index }}.page_id" data-tom-select class="admin-search-input admin-select w-full rounded-xl border px-3 py-2 text-sm">
                                        <option value="0">{{ __('admin.content.navigation.select_page') }}</option>
                                        @foreach ($pageOptions as $option)
                                            <option value="{{ $option['id'] }}">{{ $option['label'] }}</option>
                                        @endforeach
                                    </select>
                                    @error('form.items.'.$index.'.page_id') <p class="mt-1 text-xs text-rose-600">{{ $message }}</p> @enderror
                                </div>
                            @elseif (($item['type'] ?? '') === 'custom')
                                <div>
                                    <label class="mb-1 block text-xs font-semibold uppercase tracking-[0.14em] text-slate-500">{{ __('admin.content.navigation.url') }}</label>
                                    <input type="text" wire:model.live="form.items.{{ $index }}.url" class="admin-search-input w-full rounded-xl border px-3 py-2 text-sm" placeholder="{{ __('admin.content.navigation.url_placeholder') }}" />
                                    @error('form.items.'.$index.'.url') <p class="mt-1 text-xs text-rose-600">{{ $message }}</p> @enderror
                                </div>
                            @else
                                <div class="rounded-xl border border-dashed border-slate-200 bg-slate-50 px-3 py-2 text-xs text-slate-500">
                                    {{ __('admin.content.navigation.system_route_hint') }}
                                </div>
                            @endif

                        </div>

                        <div class="mt-3 grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
                            <label class="inline-flex items-center gap-2 text-xs font-semibold text-slate-700">
                                <input type="checkbox" wire:model.live="form.items.{{ $index }}.is_active" class="rounded border-slate-300 text-cyan-700 focus:ring-cyan-500" />
                                {{ __('admin.content.navigation.is_active') }}
                            </label>

                            <label class="inline-flex items-center gap-2 text-xs font-semibold text-slate-700">
                                <input type="checkbox" wire:model.live="form.items.{{ $index }}.show_dropdown" class="rounded border-slate-300 text-cyan-700 focus:ring-cyan-500" />
                                {{ __('admin.content.navigation.show_dropdown') }}
                            </label>

                            <label class="inline-flex items-center gap-2 text-xs font-semibold text-slate-700">
                                <input type="checkbox" wire:model.live="form.items.{{ $index }}.is_highlighted" class="rounded border-slate-300 text-cyan-700 focus:ring-cyan-500" />
                                {{ __('admin.content.navigation.is_highlighted') }}
                            </label>

                            <label class="inline-flex items-center gap-2 text-xs font-semibold text-slate-700">
                                <input type="checkbox" wire:model.live="form.items.{{ $index }}.open_in_new_tab" class="rounded border-slate-300 text-cyan-700 focus:ring-cyan-500" />
                                {{ __('admin.content.navigation.open_new_tab') }}
                            </label>

                            <div>
                                <label for="navigation-icon-{{ $index }}" class="mb-1 block text-xs font-semibold uppercase tracking-[0.14em] text-slate-500">{{ __('admin.content.navigation.icon') }}</label>
                                <select id="navigation-icon-{{ $index }}" wire:model.live="form.items.{{ $index }}.icon" data-tom-select data-tom-no-search="1" class="admin-search-input admin-select w-full rounded-xl border px-3 py-2 text-sm">
                                    <option value="">{{ __('admin.content.navigation.icon_none') }}</option>
                                    <option value="ticket">Poklon bon</option>
                                    <option value="gift">{{ __('admin.content.navigation.icon_gift') }}</option>
                                </select>
                                @error('form.items.'.$index.'.icon') <p class="mt-1 text-xs text-rose-600">{{ $message }}</p> @enderror
                            </div>

                            <label class="inline-flex items-center gap-2 text-xs font-semibold text-slate-700">
                                <input type="checkbox" wire:model.live="form.items.{{ $index }}.align_right" class="rounded border-slate-300 text-cyan-700 focus:ring-cyan-500" />
                                {{ __('admin.content.navigation.align_right') }}
                            </label>

                            <div>
                                <label class="mb-1 block text-xs font-semibold uppercase tracking-[0.14em] text-slate-500">{{ __('admin.common.sort') }}</label>
                                <input type="number" min="0" max="9999" wire:model.live="form.items.{{ $index }}.sort_order" class="admin-search-input w-full rounded-xl border px-3 py-2 text-sm" />
                            </div>
                        </div>

                        @if (! in_array(($item['type'] ?? ''), ['catalog', 'category'], true))
                            @include('livewire.admin.content.navigation.children', ['navigationChildren' => $item['children'] ?? [], 'parentPath' => (string) $index, 'navigationDepth' => 0])
                        @endif

                        @if (($item['show_dropdown'] ?? false) && in_array(($item['type'] ?? ''), ['catalog', 'category'], true))
                            @php
                                $currentPromoPath = (string) ($item['desktop_promo_image_path'] ?? '');
                                $currentPromoUrl = $currentPromoPath !== '' ? \Illuminate\Support\Facades\Storage::disk('public')->url($currentPromoPath) : '';
                            @endphp
                            <div class="mt-4 rounded-xl border border-slate-200 bg-slate-50 p-3">
                                <p class="text-xs font-semibold uppercase tracking-[0.14em] text-slate-500">Desktop Mega Promo</p>
                                <div class="mt-2 grid gap-3 lg:grid-cols-2">
                                    <div class="lg:col-span-2">
                                        <label class="mb-1 block text-xs font-semibold uppercase tracking-[0.14em] text-slate-500">Promo Image</label>
                                        <input type="file" wire:model="desktopPromoUploads.{{ $index }}" accept="image/*" class="admin-search-input w-full rounded-xl border px-3 py-2 text-sm" />
                                        @error('desktopPromoUploads.'.$index) <p class="mt-1 text-xs text-rose-600">{{ $message }}</p> @enderror
                                        @if ($currentPromoUrl !== '')
                                            <div class="mt-2 flex items-center gap-3">
                                                <img src="{{ $currentPromoUrl }}" alt="Mega promo" class="h-16 w-28 rounded-md object-cover">
                                                <button type="button" wire:click="clearDesktopPromoImage({{ $index }})" class="rounded-md border border-rose-200 px-2 py-1 text-xs font-semibold text-rose-700 hover:bg-rose-50">Makni sliku</button>
                                            </div>
                                        @endif
                                    </div>
                                    <div>
                                        <label class="mb-1 block text-xs font-semibold uppercase tracking-[0.14em] text-slate-500">Title</label>
                                        <input type="text" wire:model.live="form.items.{{ $index }}.desktop_promo_title" class="admin-search-input w-full rounded-xl border px-3 py-2 text-sm" placeholder="Nova kolekcija" />
                                        @error('form.items.'.$index.'.desktop_promo_title') <p class="mt-1 text-xs text-rose-600">{{ $message }}</p> @enderror
                                    </div>
                                    <div>
                                        <label class="mb-1 block text-xs font-semibold uppercase tracking-[0.14em] text-slate-500">Subtitle</label>
                                        <input type="text" wire:model.live="form.items.{{ $index }}.desktop_promo_subtitle" class="admin-search-input w-full rounded-xl border px-3 py-2 text-sm" placeholder="Istaknuti komadi sezone" />
                                        @error('form.items.'.$index.'.desktop_promo_subtitle') <p class="mt-1 text-xs text-rose-600">{{ $message }}</p> @enderror
                                    </div>
                                    <div>
                                        <label class="mb-1 block text-xs font-semibold uppercase tracking-[0.14em] text-slate-500">CTA Label</label>
                                        <input type="text" wire:model.live="form.items.{{ $index }}.desktop_promo_cta_label" class="admin-search-input w-full rounded-xl border px-3 py-2 text-sm" placeholder="Pogledaj više" />
                                        @error('form.items.'.$index.'.desktop_promo_cta_label') <p class="mt-1 text-xs text-rose-600">{{ $message }}</p> @enderror
                                    </div>
                                    <div>
                                        <label class="mb-1 block text-xs font-semibold uppercase tracking-[0.14em] text-slate-500">CTA URL</label>
                                        <input type="text" wire:model.live="form.items.{{ $index }}.desktop_promo_cta_url" class="admin-search-input w-full rounded-xl border px-3 py-2 text-sm" placeholder="/shop ili https://..." />
                                        @error('form.items.'.$index.'.desktop_promo_cta_url') <p class="mt-1 text-xs text-rose-600">{{ $message }}</p> @enderror
                                    </div>
                                </div>
                            </div>
                        @endif
                        </div>
                        @endif
                    </div>
                @endforeach
            </div>
        @endif

    </div>
    @endif

    @if ($activeTab === 'images' && config('storefront.home') === 'lavaggio')
        <section id="navigation-panel-images" role="tabpanel" class="admin-panel admin-panel-soft p-5" aria-labelledby="navigation-tab-images">
            <h2 id="category-menu-images-title" class="admin-section-title">Slike kategorija u Web shop izborniku</h2>
            <p class="mt-1 text-sm text-slate-600">Za svaku glavnu kategoriju odaberite sliku koja se prikazuje desno u izborniku. Kategorije se automatski preuzimaju iz kataloga.</p>

            <div class="mt-4 space-y-3">
                @forelse ($categoryImageOptions as $categoryImage)
                    @php
                        $categoryId = $categoryImage['id'];
                        $pendingUpload = $categoryMenuUploads[$categoryId] ?? null;
                        $previewUrl = $categoryImage['image_url'];
                        if ($pendingUpload instanceof \Livewire\Features\SupportFileUploads\TemporaryUploadedFile && ! $errors->has('categoryMenuUploads.'.$categoryId)) {
                            $previewUrl = $pendingUpload->temporaryUrl();
                        }
                    @endphp
                    <div wire:key="category-menu-image-{{ $categoryId }}" class="overflow-hidden rounded-xl border border-slate-200 bg-white">
                        <button type="button" wire:click="toggleCategoryImage({{ $categoryId }})" aria-expanded="{{ ! empty($expandedCategories[$categoryId]) ? 'true' : 'false' }}" aria-controls="category-menu-image-details-{{ $categoryId }}" class="flex w-full items-center gap-3 px-4 py-3 text-left">
                            <svg class="h-4 w-4 shrink-0 text-slate-500" style="transform: rotate({{ ! empty($expandedCategories[$categoryId]) ? '90' : '0' }}deg)" viewBox="0 0 20 20" fill="none" stroke="currentColor" aria-hidden="true"><path d="m7 4 6 6-6 6" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" /></svg>
                            <span class="min-w-0 flex-1 font-semibold text-slate-900">{{ $categoryImage['label'] }}</span>
                            <span class="text-xs text-slate-500">{{ $previewUrl !== '' ? __('admin.content.navigation.image_set') : __('admin.content.navigation.image_missing') }}</span>
                        </button>
                        @if (! empty($expandedCategories[$categoryId]))
                        <div id="category-menu-image-details-{{ $categoryId }}" class="flex flex-col gap-4 border-t border-slate-200 p-4 sm:flex-row">
                            <div class="flex h-28 w-36 shrink-0 items-center justify-center overflow-hidden rounded-lg border border-slate-200 bg-slate-50">
                                @if ($previewUrl !== '')
                                    <img src="{{ $previewUrl }}" alt="{{ ($form['category_images'][$categoryId]['image_alt'] ?? '') ?: $categoryImage['label'] }}" class="h-full w-full object-cover" />
                                @else
                                    <span class="px-3 text-center text-xs text-slate-500">Slika nije postavljena</span>
                                @endif
                            </div>
                            <div class="min-w-0 flex-1 space-y-3">
                                <div>
                                    <label for="category-menu-upload-{{ $categoryId }}" class="mb-1 block text-xs font-semibold text-slate-700">Slika kategorije</label>
                                    <input id="category-menu-upload-{{ $categoryId }}" type="file" wire:model="categoryMenuUploads.{{ $categoryId }}" accept="image/jpeg,image/png,image/webp,image/gif" class="admin-search-input w-full rounded-xl border px-3 py-2 text-sm" />
                                    <p class="mt-1 text-xs text-slate-500">Preporučen omjer 4:3. JPG, PNG ili WebP, do 4 MB.</p>
                                    <span wire:loading wire:target="categoryMenuUploads.{{ $categoryId }}" class="text-xs text-slate-500">Učitavanje slike…</span>
                                    @error('categoryMenuUploads.'.$categoryId) <p class="mt-1 text-xs text-rose-600">{{ $message }}</p> @enderror
                                </div>
                                <div>
                                    <label for="category-menu-alt-{{ $categoryId }}" class="mb-1 block text-xs font-semibold text-slate-700">Opis slike (alt)</label>
                                    <input id="category-menu-alt-{{ $categoryId }}" type="text" wire:model="form.category_images.{{ $categoryId }}.image_alt" maxlength="255" class="admin-search-input w-full rounded-xl border px-3 py-2 text-sm" placeholder="{{ $categoryImage['label'] }}" />
                                    @error('form.category_images.'.$categoryId.'.image_alt') <p class="mt-1 text-xs text-rose-600">{{ $message }}</p> @enderror
                                </div>
                                @if ($previewUrl !== '')
                                    <button type="button" wire:click="clearCategoryMenuImage({{ $categoryId }})" class="rounded-md border border-rose-200 px-2 py-1 text-xs font-semibold text-rose-700 hover:bg-rose-50">Ukloni sliku</button>
                                @endif
                            </div>
                        </div>
                        @endif
                    </div>
                @empty
                    <p class="text-sm text-slate-500">Dodajte glavne kategorije u katalog kako biste im postavili slike.</p>
                @endforelse
            </div>
            <p class="mt-4 text-xs text-slate-500">Promjene slika spremite gumbom za spremanje navigacije na dnu stranice.</p>
        </section>
    @endif

    @if ($activeTab === 'appearance')
    <div id="navigation-panel-appearance" role="tabpanel" aria-labelledby="navigation-tab-appearance" class="admin-panel admin-panel-soft p-5">
        <div>
            <h2 class="admin-section-title">{{ __('admin.content.navigation.appearance_title') }}</h2>
            <p class="mt-1 text-sm text-slate-600">{{ __('admin.content.navigation.appearance_subtitle') }}</p>
        </div>

        <div class="mt-4 grid gap-4 sm:grid-cols-2 xl:grid-cols-4 2xl:grid-cols-8">
            <div>
                <label class="mb-1 block text-xs font-semibold uppercase tracking-[0.14em] text-slate-500">{{ __('admin.content.navigation.container_width') }}</label>
                <input type="number" min="960" max="1920" step="10" wire:model="form.appearance.container_width" class="admin-search-input w-full rounded-xl border px-3 py-2 text-sm" />
                @error('form.appearance.container_width') <p class="mt-1 text-xs text-rose-600">{{ $message }}</p> @enderror
            </div>
            <div>
                <label class="mb-1 block text-xs font-semibold uppercase tracking-[0.14em] text-slate-500">{{ __('admin.content.navigation.header_content_width') }}</label>
                <input type="number" min="960" max="1920" step="10" wire:model="form.appearance.header_content_width" class="admin-search-input w-full rounded-xl border px-3 py-2 text-sm" />
                @error('form.appearance.header_content_width') <p class="mt-1 text-xs text-rose-600">{{ $message }}</p> @enderror
            </div>
            <div>
                <label class="mb-1 block text-xs font-semibold uppercase tracking-[0.14em] text-slate-500">{{ __('admin.content.navigation.item_height') }}</label>
                <input type="number" min="44" max="84" wire:model="form.appearance.item_height" class="admin-search-input w-full rounded-xl border px-3 py-2 text-sm" />
                @error('form.appearance.item_height') <p class="mt-1 text-xs text-rose-600">{{ $message }}</p> @enderror
            </div>
            <div>
                <label class="mb-1 block text-xs font-semibold uppercase tracking-[0.14em] text-slate-500">{{ __('admin.content.navigation.font_size') }}</label>
                <input type="number" min="13" max="20" wire:model="form.appearance.font_size" class="admin-search-input w-full rounded-xl border px-3 py-2 text-sm" />
                @error('form.appearance.font_size') <p class="mt-1 text-xs text-rose-600">{{ $message }}</p> @enderror
            </div>
            <div>
                <label class="mb-1 block text-xs font-semibold uppercase tracking-[0.14em] text-slate-500">{{ __('admin.content.navigation.logo_height') }}</label>
                <input type="number" min="48" max="82" wire:model="form.appearance.logo_height" class="admin-search-input w-full rounded-xl border px-3 py-2 text-sm" />
                @error('form.appearance.logo_height') <p class="mt-1 text-xs text-rose-600">{{ $message }}</p> @enderror
            </div>
            <div>
                <label class="mb-1 block text-xs font-semibold uppercase tracking-[0.14em] text-slate-500">{{ __('admin.content.navigation.background_color') }}</label>
                <div class="flex items-center gap-2">
                    <input type="color" wire:model.live="form.appearance.background_color" class="h-10 w-12 cursor-pointer rounded-lg border border-slate-300 bg-white p-1" />
                    <input type="text" wire:model="form.appearance.background_color" class="admin-search-input min-w-0 flex-1 rounded-xl border px-3 py-2 text-sm uppercase" />
                </div>
                @error('form.appearance.background_color') <p class="mt-1 text-xs text-rose-600">{{ $message }}</p> @enderror
            </div>
            <div>
                <label class="mb-1 block text-xs font-semibold uppercase tracking-[0.14em] text-slate-500">{{ __('admin.content.navigation.text_color') }}</label>
                <div class="flex items-center gap-2">
                    <input type="color" wire:model.live="form.appearance.text_color" class="h-10 w-12 cursor-pointer rounded-lg border border-slate-300 bg-white p-1" />
                    <input type="text" wire:model="form.appearance.text_color" class="admin-search-input min-w-0 flex-1 rounded-xl border px-3 py-2 text-sm uppercase" />
                </div>
                @error('form.appearance.text_color') <p class="mt-1 text-xs text-rose-600">{{ $message }}</p> @enderror
            </div>
            <div>
                <label class="mb-1 block text-xs font-semibold uppercase tracking-[0.14em] text-slate-500">{{ __('admin.content.navigation.highlight_color') }}</label>
                <div class="flex items-center gap-2">
                    <input type="color" wire:model.live="form.appearance.highlight_color" class="h-10 w-12 cursor-pointer rounded-lg border border-slate-300 bg-white p-1" />
                    <input type="text" wire:model="form.appearance.highlight_color" class="admin-search-input min-w-0 flex-1 rounded-xl border px-3 py-2 text-sm uppercase" />
                </div>
                @error('form.appearance.highlight_color') <p class="mt-1 text-xs text-rose-600">{{ $message }}</p> @enderror
            </div>
        </div>
    </div>
    @endif

    @if ($activeTab === 'top_bar')
    <div id="navigation-panel-top_bar" role="tabpanel" aria-labelledby="navigation-tab-top_bar" class="admin-panel admin-panel-soft p-5">
        <div class="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
            <div>
                <h2 class="admin-section-title">{{ __('admin.content.navigation.top_bar_title') }}</h2>
                <p class="mt-1 text-sm text-slate-600">{{ __('admin.content.navigation.top_bar_subtitle') }}</p>
            </div>
            <label class="inline-flex items-center gap-2 text-xs font-semibold text-slate-700">
                <input type="checkbox" wire:model.live="form.top_bar.is_enabled" class="rounded border-slate-300 text-cyan-700 focus:ring-cyan-500" />
                {{ __('admin.content.navigation.top_bar_enabled') }}
            </label>
        </div>

        <div class="mt-4 grid gap-4 sm:grid-cols-2 xl:grid-cols-5">
            <div>
                <label class="mb-1 block text-xs font-semibold uppercase tracking-[0.14em] text-slate-500">{{ __('admin.content.navigation.top_bar_height') }}</label>
                <input type="number" min="28" max="50" wire:model="form.top_bar.height" class="admin-search-input w-full rounded-xl border px-3 py-2 text-sm" />
                @error('form.top_bar.height') <p class="mt-1 text-xs text-rose-600">{{ $message }}</p> @enderror
            </div>
            <div>
                <label class="mb-1 block text-xs font-semibold uppercase tracking-[0.14em] text-slate-500">{{ __('admin.content.navigation.top_bar_font_size') }}</label>
                <input type="number" min="11" max="16" wire:model="form.top_bar.font_size" class="admin-search-input w-full rounded-xl border px-3 py-2 text-sm" />
                @error('form.top_bar.font_size') <p class="mt-1 text-xs text-rose-600">{{ $message }}</p> @enderror
            </div>
            @foreach (['background_color', 'text_color', 'border_color'] as $colorField)
                <div>
                    <label class="mb-1 block text-xs font-semibold uppercase tracking-[0.14em] text-slate-500">{{ __('admin.content.navigation.top_bar_'.$colorField) }}</label>
                    <div class="flex items-center gap-2">
                        <input type="color" wire:model.live="form.top_bar.{{ $colorField }}" class="h-10 w-12 cursor-pointer rounded-lg border border-slate-300 bg-white p-1" />
                        <input type="text" wire:model="form.top_bar.{{ $colorField }}" class="admin-search-input min-w-0 flex-1 rounded-xl border px-3 py-2 text-sm uppercase" />
                    </div>
                    @error('form.top_bar.'.$colorField) <p class="mt-1 text-xs text-rose-600">{{ $message }}</p> @enderror
                </div>
            @endforeach
        </div>

        <div class="mt-5 grid gap-5 xl:grid-cols-[1.5fr_1fr]">
            <section>
                <div class="flex items-center justify-between gap-3">
                    <h3 class="text-sm font-semibold text-slate-900">{{ __('admin.content.navigation.top_bar_links') }}</h3>
                    <button type="button" wire:click="addTopBarLink" class="rounded-lg border border-cyan-200 bg-cyan-50 px-2.5 py-1.5 text-xs font-semibold text-cyan-800 hover:bg-cyan-100">
                        {{ __('admin.content.navigation.top_bar_add_link') }}
                    </button>
                </div>
                <div class="mt-3 space-y-2">
                    @forelse ($form['top_bar']['links'] as $index => $link)
                        <div class="grid gap-2 rounded-xl border border-slate-200 bg-white p-3 lg:grid-cols-[1fr_1.7fr_5rem_auto] lg:items-end" wire:key="top-bar-link-{{ $index }}">
                            <div>
                                <label class="mb-1 block text-xs font-semibold uppercase tracking-[0.14em] text-slate-500">{{ __('admin.content.navigation.label') }}</label>
                                <input type="text" wire:model="form.top_bar.links.{{ $index }}.label" class="admin-search-input w-full rounded-xl border px-3 py-2 text-sm" />
                                @error('form.top_bar.links.'.$index.'.label') <p class="mt-1 text-xs text-rose-600">{{ $message }}</p> @enderror
                            </div>
                            <div>
                                <label class="mb-1 block text-xs font-semibold uppercase tracking-[0.14em] text-slate-500">{{ __('admin.content.navigation.url') }}</label>
                                <input type="text" wire:model="form.top_bar.links.{{ $index }}.url" class="admin-search-input w-full rounded-xl border px-3 py-2 text-sm" />
                                @error('form.top_bar.links.'.$index.'.url') <p class="mt-1 text-xs text-rose-600">{{ $message }}</p> @enderror
                            </div>
                            <div>
                                <label class="mb-1 block text-xs font-semibold uppercase tracking-[0.14em] text-slate-500">{{ __('admin.common.sort') }}</label>
                                <input type="number" min="0" max="9999" wire:model="form.top_bar.links.{{ $index }}.sort_order" class="admin-search-input w-full rounded-xl border px-3 py-2 text-sm" />
                                @error('form.top_bar.links.'.$index.'.sort_order') <p class="mt-1 text-xs text-rose-600">{{ $message }}</p> @enderror
                            </div>
                            <button type="button" wire:click="removeTopBarLink({{ $index }})" class="rounded-lg border border-rose-200 px-2.5 py-2 text-xs font-semibold text-rose-700 hover:bg-rose-50">
                                {{ __('admin.content.navigation.remove') }}
                            </button>
                            <div class="flex flex-wrap gap-4 lg:col-span-4">
                                <label class="inline-flex items-center gap-2 text-xs font-semibold text-slate-700">
                                    <input type="checkbox" wire:model="form.top_bar.links.{{ $index }}.is_active" class="rounded border-slate-300 text-cyan-700 focus:ring-cyan-500" />
                                    {{ __('admin.content.navigation.is_active') }}
                                </label>
                                <label class="inline-flex items-center gap-2 text-xs font-semibold text-slate-700">
                                    <input type="checkbox" wire:model="form.top_bar.links.{{ $index }}.open_in_new_tab" class="rounded border-slate-300 text-cyan-700 focus:ring-cyan-500" />
                                    {{ __('admin.content.navigation.open_new_tab') }}
                                </label>
                            </div>
                        </div>
                    @empty
                        <p class="rounded-xl border border-dashed border-slate-300 bg-white p-4 text-sm text-slate-500">{{ __('admin.content.navigation.top_bar_links_empty') }}</p>
                    @endforelse
                </div>
            </section>

            <section>
                <div class="flex items-center justify-between gap-3">
                    <h3 class="text-sm font-semibold text-slate-900">{{ __('admin.content.navigation.top_bar_socials') }}</h3>
                    <button type="button" wire:click="addSocialLink" class="rounded-lg border border-cyan-200 bg-cyan-50 px-2.5 py-1.5 text-xs font-semibold text-cyan-800 hover:bg-cyan-100">
                        {{ __('admin.content.navigation.top_bar_add_social') }}
                    </button>
                </div>
                <div class="mt-3 space-y-2">
                    @forelse ($form['top_bar']['socials'] as $index => $social)
                        <div class="grid gap-2 rounded-xl border border-slate-200 bg-white p-3 sm:grid-cols-[9rem_1fr_5rem_auto] sm:items-end" wire:key="top-bar-social-{{ $index }}">
                            <div>
                                <label class="mb-1 block text-xs font-semibold uppercase tracking-[0.14em] text-slate-500">{{ __('admin.content.navigation.top_bar_network') }}</label>
                                <select wire:model="form.top_bar.socials.{{ $index }}.network" class="admin-search-input admin-select w-full rounded-xl border px-3 py-2 text-sm">
                                    <option value="facebook">Facebook</option>
                                    <option value="youtube">YouTube</option>
                                    <option value="instagram">Instagram</option>
                                    <option value="tiktok">TikTok</option>
                                    <option value="linkedin">LinkedIn</option>
                                    <option value="twitter">X / Twitter</option>
                                </select>
                            </div>
                            <div>
                                <label class="mb-1 block text-xs font-semibold uppercase tracking-[0.14em] text-slate-500">{{ __('admin.content.navigation.url') }}</label>
                                <input type="text" wire:model="form.top_bar.socials.{{ $index }}.url" class="admin-search-input w-full rounded-xl border px-3 py-2 text-sm" />
                                @error('form.top_bar.socials.'.$index.'.url') <p class="mt-1 text-xs text-rose-600">{{ $message }}</p> @enderror
                            </div>
                            <div>
                                <label class="mb-1 block text-xs font-semibold uppercase tracking-[0.14em] text-slate-500">{{ __('admin.common.sort') }}</label>
                                <input type="number" min="0" max="9999" wire:model="form.top_bar.socials.{{ $index }}.sort_order" class="admin-search-input w-full rounded-xl border px-3 py-2 text-sm" />
                                @error('form.top_bar.socials.'.$index.'.sort_order') <p class="mt-1 text-xs text-rose-600">{{ $message }}</p> @enderror
                            </div>
                            <button type="button" wire:click="removeSocialLink({{ $index }})" class="rounded-lg border border-rose-200 px-2.5 py-2 text-xs font-semibold text-rose-700 hover:bg-rose-50">
                                {{ __('admin.content.navigation.remove') }}
                            </button>
                            <div class="sm:col-span-4">
                                <label class="mb-1 block text-xs font-semibold uppercase tracking-[0.14em] text-slate-500">{{ __('Naziv profila (opcionalno)') }}</label>
                                <input type="text" wire:model="form.top_bar.socials.{{ $index }}.label" maxlength="120" placeholder="Facebook Hrvatska" class="admin-search-input w-full rounded-xl border px-3 py-2 text-sm" />
                            </div>
                            <label class="inline-flex items-center gap-2 text-xs font-semibold text-slate-700 sm:col-span-4">
                                <input type="checkbox" wire:model="form.top_bar.socials.{{ $index }}.is_active" class="rounded border-slate-300 text-cyan-700 focus:ring-cyan-500" />
                                {{ __('admin.content.navigation.is_active') }}
                            </label>
                        </div>
                    @empty
                        <p class="rounded-xl border border-dashed border-slate-300 bg-white p-4 text-sm text-slate-500">{{ __('admin.content.navigation.top_bar_socials_empty') }}</p>
                    @endforelse
                </div>
            </section>
        </div>
    </div>
    @endif

    <div class="admin-panel sticky bottom-4 flex flex-wrap items-center justify-between gap-3 p-4">
        <p class="text-xs text-slate-500">{{ __('admin.content.navigation.save_all_hint') }}</p>
        <button type="button" wire:click="save" wire:loading.attr="disabled" wire:target="save,categoryMenuUploads,desktopPromoUploads" class="rounded-xl bg-cyan-700 px-4 py-2 text-sm font-semibold text-white hover:bg-cyan-800 disabled:opacity-50">{{ __('admin.content.navigation.save') }}</button>
    </div>
</div>
