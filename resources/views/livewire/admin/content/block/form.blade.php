<div class="space-y-6">
    <div class="admin-panel admin-search-panel p-6">
        <div class="flex flex-col gap-3 sm:flex-row sm:items-end sm:justify-between">
            <div>
                <p class="text-xs font-semibold uppercase tracking-[0.18em] text-slate-500">{{ __('Content / Blocks v2') }}</p>
                <h1 class="mt-2 text-2xl font-semibold tracking-tight text-slate-900">{{ $this->isEdit ? __('Edit Block') : __('Create Block') }}</h1>
                <p class="mt-2 text-sm text-slate-600">{{ __('Simple builder: choose type, set slot, pick items, and publish.') }}</p>
            </div>
            <div class="flex items-center gap-2">
                <span class="admin-chip">{{ __('Locale:') }} {{ $form['locale'] }}</span>
                <button type="button" wire:click="backToList" class="rounded-xl border border-slate-300 bg-white px-4 py-2 text-sm font-semibold text-slate-700 hover:bg-slate-100">{{ __('Back to List') }}</button>
            </div>
        </div>
    </div>

    <form wire:submit="save" class="space-y-6">
        @if ($this->isWatchlineWidget)
            <details class="admin-panel p-4">
                <summary class="cursor-pointer text-sm font-semibold text-slate-600">Napredne postavke widgeta</summary>
                <div class="mt-4 space-y-6">
        @endif
        <div class="admin-panel admin-form-panel p-6">
            <p class="admin-section-title">{{ __('Core') }}</p>

            <div class="mt-4 grid gap-3 md:grid-cols-4">
                <div>
                    <label class="mb-1 block text-xs font-semibold uppercase tracking-[0.14em] text-slate-500">{{ __('Code') }}</label>
                    <input type="text" wire:model="form.code" class="w-full rounded-xl border border-slate-300 px-3 py-2 text-sm font-mono" />
                    @error('form.code') <p class="mt-1 text-xs text-rose-600">{{ $message }}</p> @enderror
                </div>

                <div class="md:col-span-2">
                    <label class="mb-1 block text-xs font-semibold uppercase tracking-[0.14em] text-slate-500">{{ __('Name') }}</label>
                    <input type="text" wire:model="form.name" class="w-full rounded-xl border border-slate-300 px-3 py-2 text-sm" />
                    @error('form.name') <p class="mt-1 text-xs text-rose-600">{{ $message }}</p> @enderror
                </div>

                <div>
                    <label class="mb-1 block text-xs font-semibold uppercase tracking-[0.14em] text-slate-500">{{ __('Type') }}</label>
                    <select wire:model.live="form.type" data-tom-select data-tom-no-search="1" class="admin-select w-full rounded-xl border border-slate-300 px-3 py-2 text-sm">
                        @foreach ($types as $typeKey => $typeLabel)
                            <option value="{{ $typeKey }}" @selected(($form['type'] ?? '') === $typeKey)>{{ $typeLabel }}</option>
                        @endforeach
                    </select>
                    @error('form.type') <p class="mt-1 text-xs text-rose-600">{{ $message }}</p> @enderror
                </div>
            </div>

            <div class="mt-3 grid gap-3 md:grid-cols-2">
                <div>
                    <label class="mb-1 block text-xs font-semibold uppercase tracking-[0.14em] text-slate-500">{{ __('admin.common.locale') }}</label>
                    <select wire:model.live="form.locale" data-tom-select data-tom-no-search="1" class="admin-select w-full rounded-xl border border-slate-300 px-3 py-2 text-sm lowercase">
                        @foreach ($adminLocaleOptions as $localeOption)
                            <option value="{{ $localeOption }}">{{ $localeOption }}</option>
                        @endforeach
                    </select>
                    @error('form.locale') <p class="mt-1 text-xs text-rose-600">{{ $message }}</p> @enderror
                </div>

                <div class="flex items-end">
                    <button
                        type="button"
                        wire:click="$toggle('form.is_active')"
                        class="admin-switch"
                        data-state="{{ $form['is_active'] ? 'on' : 'off' }}"
                        role="switch"
                        aria-checked="{{ $form['is_active'] ? 'true' : 'false' }}"
                        aria-label="{{ __('Toggle block active state') }}"
                    >
                        <span class="admin-switch-track"><span class="admin-switch-thumb"></span></span>
                        <span class="admin-switch-label">{{ $form['is_active'] ? __('admin.common.active') : __('admin.common.inactive') }}</span>
                    </button>
                </div>
            </div>
        </div>

        <div class="admin-panel admin-form-panel p-6">
            <p class="admin-section-title">{{ __('Slot (Placement)') }}</p>

            <div class="mt-4 grid gap-3 md:grid-cols-5">
                <div>
                    <label class="mb-1 block text-xs font-semibold uppercase tracking-[0.14em] text-slate-500">{{ __('Placement') }}</label>
                    <select wire:model="form.slot_placement" data-tom-select data-tom-no-search="1" class="admin-select w-full rounded-xl border border-slate-300 px-3 py-2 text-sm">
                        @foreach ($placements as $placementKey => $placementLabel)
                            <option value="{{ $placementKey }}" @selected(($form['slot_placement'] ?? '') === $placementKey)>{{ $placementLabel }}</option>
                        @endforeach
                    </select>
                </div>

                <div>
                    <label class="mb-1 block text-xs font-semibold uppercase tracking-[0.14em] text-slate-500">{{ __('Surface') }}</label>
                    <select wire:model="form.slot_frontend_variant" data-tom-select data-tom-no-search="1" class="admin-select w-full rounded-xl border border-slate-300 px-3 py-2 text-sm">
                        @foreach ($frontendVariants as $frontendVariantKey => $frontendVariantLabel)
                            <option value="{{ $frontendVariantKey }}" @selected(($form['slot_frontend_variant'] ?? 'all') === $frontendVariantKey)>{{ $frontendVariantLabel }}</option>
                        @endforeach
                    </select>
                    @error('form.slot_frontend_variant') <p class="mt-1 text-xs text-rose-600">{{ $message }}</p> @enderror
                </div>

                <div>
                    <label class="mb-1 block text-xs font-semibold uppercase tracking-[0.14em] text-slate-500">{{ __('Target Type') }}</label>
                    <select wire:model="form.slot_target_type" data-tom-select data-tom-no-search="1" class="admin-select w-full rounded-xl border border-slate-300 px-3 py-2 text-sm">
                        @foreach ($targetTypes as $targetTypeKey => $targetTypeLabel)
                            <option value="{{ $targetTypeKey }}" @selected((string) ($form['slot_target_type'] ?? '') === (string) $targetTypeKey)>{{ $targetTypeLabel }}</option>
                        @endforeach
                    </select>
                    @error('form.slot_target_type') <p class="mt-1 text-xs text-rose-600">{{ $message }}</p> @enderror
                </div>

                <div>
                    <label class="mb-1 block text-xs font-semibold uppercase tracking-[0.14em] text-slate-500">{{ __('Target Ref') }}</label>
                    <input type="text" wire:model="form.slot_target_ref" class="w-full rounded-xl border border-slate-300 px-3 py-2 text-sm" placeholder="{{ __('slug or id') }}" />
                </div>

                <div>
                    <label class="mb-1 block text-xs font-semibold uppercase tracking-[0.14em] text-slate-500">{{ __('Sort Order') }}</label>
                    <input type="number" min="0" wire:model="form.slot_sort_order" class="w-full rounded-xl border border-slate-300 px-3 py-2 text-sm" />
                </div>
            </div>

            <div class="mt-3 grid gap-3 md:grid-cols-3">
                <div>
                    <label class="mb-1 block text-xs font-semibold uppercase tracking-[0.14em] text-slate-500">{{ __('Starts At') }}</label>
                    <input type="datetime-local" wire:model="form.slot_starts_at" class="w-full rounded-xl border border-slate-300 px-3 py-2 text-sm" />
                    @error('form.slot_starts_at') <p class="mt-1 text-xs text-rose-600">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label class="mb-1 block text-xs font-semibold uppercase tracking-[0.14em] text-slate-500">{{ __('Ends At') }}</label>
                    <input type="datetime-local" wire:model="form.slot_ends_at" class="w-full rounded-xl border border-slate-300 px-3 py-2 text-sm" />
                    @error('form.slot_ends_at') <p class="mt-1 text-xs text-rose-600">{{ $message }}</p> @enderror
                </div>
                <div class="flex items-end">
                    <button
                        type="button"
                        wire:click="$toggle('form.slot_is_active')"
                        class="admin-switch"
                        data-state="{{ $form['slot_is_active'] ? 'on' : 'off' }}"
                        role="switch"
                        aria-checked="{{ $form['slot_is_active'] ? 'true' : 'false' }}"
                        aria-label="{{ __('Toggle slot active state') }}"
                    >
                        <span class="admin-switch-track"><span class="admin-switch-thumb"></span></span>
                        <span class="admin-switch-label">{{ $form['slot_is_active'] ? __('Slot Active') : __('Slot Inactive') }}</span>
                    </button>
                </div>
            </div>
        </div>

        @if ($this->isWatchlineWidget)
                </div>
            </details>
        @endif

        <div class="grid gap-6 {{ $this->isWatchlineWidget ? '' : 'xl:grid-cols-2' }}">
            <div class="admin-panel admin-form-panel p-6">
                <p class="admin-section-title">{{ __('Content') }}</p>

                @if (! in_array(($form['type'] ?? ''), ['watchline_campaigns', 'watchline_benefits'], true))
                <div class="mt-4 grid gap-3 md:grid-cols-2">
                    <div>
                        <label class="mb-1 block text-xs font-semibold uppercase tracking-[0.14em] text-slate-500">{{ __('Title') }}</label>
                        <input type="text" wire:model="form.title" class="w-full rounded-xl border border-slate-300 px-3 py-2 text-sm" />
                    </div>
                    <div>
                        <label class="mb-1 block text-xs font-semibold uppercase tracking-[0.14em] text-slate-500">{{ __('Subtitle') }}</label>
                        <input type="text" wire:model="form.subtitle" class="w-full rounded-xl border border-slate-300 px-3 py-2 text-sm" />
                    </div>
                </div>

                <div class="mt-3 grid gap-3 md:grid-cols-2">
                    <div>
                        <label class="mb-1 block text-xs font-semibold uppercase tracking-[0.14em] text-slate-500">{{ __('CTA Label') }}</label>
                        <input type="text" wire:model="form.cta_label" class="w-full rounded-xl border border-slate-300 px-3 py-2 text-sm" />
                    </div>
                    <div>
                        <label class="mb-1 block text-xs font-semibold uppercase tracking-[0.14em] text-slate-500">{{ __('CTA URL') }}</label>
                        <input type="text" wire:model="form.cta_url" class="w-full rounded-xl border border-slate-300 px-3 py-2 text-sm" placeholder="{{ __('/shop or https://...') }}" />
                    </div>
                </div>
                @endif

                @if ($this->isWatchlineWidget)
                    <p class="mt-3 text-sm text-slate-500">Uredite tekstove, slike i poveznice ovog widgeta.</p>
                    <div class="mt-4 grid gap-4 md:grid-cols-2">
                        @foreach ($this->watchlineFields as $fieldKey => $field)
                            @if (! empty($field['group']))
                                <h3 class="border-t border-slate-200 pt-5 text-sm font-semibold text-slate-900 md:col-span-2">{{ $field['group'] }}</h3>
                            @endif
                            <div wire:key="watchline-field-{{ $form['type'] }}-{{ $fieldKey }}" class="{{ ($field['type'] ?? '') === 'textarea' ? 'md:col-span-2' : '' }}">
                                @if (($field['type'] ?? '') === 'checkbox')
                                    <label class="inline-flex items-center gap-2 text-sm text-slate-700">
                                        <input type="checkbox" @if ($fieldKey === 'popular_only') wire:model.live="form.watchline.{{ $fieldKey }}" @else wire:model="form.watchline.{{ $fieldKey }}" @endif class="rounded border-slate-300 text-cyan-700 focus:ring-cyan-500">
                                        <span>{{ $field['label'] }}</span>
                                    </label>
                                    @if ($fieldKey === 'popular_only')
                                        <p class="mt-1 text-xs leading-relaxed text-slate-500">Popularno je ručna oznaka na artiklu. <a href="{{ route('admin.products') }}" class="font-semibold text-cyan-700 underline underline-offset-2 hover:text-cyan-900">Otvori artikle</a></p>
                                        @if ($this->popularFilterHasNoProducts)
                                            <p role="status" class="mt-2 rounded-lg border border-amber-200 bg-amber-50 px-3 py-2 text-xs leading-relaxed text-amber-900">Nijedan aktivan artikl nema oznaku Popularno. Widget se neće prikazati dok ne označite artikle ili isključite ovaj filter.</p>
                                        @endif
                                    @endif
                                @else
                                    <label for="watchline-field-{{ $fieldKey }}" class="mb-1 block text-xs font-semibold uppercase tracking-wide text-slate-500">{{ $field['label'] }}</label>
                                    @if (in_array(($field['type'] ?? ''), ['categories', 'manufacturers'], true))
                                        @php
                                            $categoryFilter = $field['type'] === 'categories';
                                            $filterOptions = $categoryFilter ? $this->watchlineCategoryFilterOptions : $this->watchlineManufacturerFilterOptions;
                                            $filterSearch = $categoryFilter ? 'watchlineCategoryFilterSearch' : 'watchlineManufacturerFilterSearch';
                                            $chosenFilterIds = (array) ($form['watchline'][$fieldKey] ?? []);
                                        @endphp
                                        <input id="watchline-field-{{ $fieldKey }}" type="search" wire:model.live.debounce.300ms="{{ $filterSearch }}" placeholder="{{ $categoryFilter ? 'Potražite kategoriju…' : 'Potražite brand…' }}" class="w-full rounded-xl border border-slate-300 px-3 py-2 text-sm">
                                        <div class="mt-2 max-h-56 space-y-1 overflow-y-auto rounded-xl border border-slate-200 bg-slate-50 p-2">
                                            @forelse ($filterOptions as $filterOption)
                                                <label wire:key="watchline-filter-{{ $fieldKey }}-{{ $filterOption['id'] }}" class="flex cursor-pointer items-center gap-2 rounded-lg px-2 py-2 text-sm {{ in_array($filterOption['id'], $chosenFilterIds) ? 'bg-cyan-50 text-cyan-900' : 'bg-white text-slate-700' }}">
                                                    <input type="checkbox" value="{{ $filterOption['id'] }}" wire:model.live="form.watchline.{{ $fieldKey }}" class="rounded border-slate-300 text-cyan-700 focus:ring-cyan-500">
                                                    <span>{{ $filterOption['label'] }} @if (! $filterOption['is_active'])<small class="text-slate-400">(neaktivno)</small>@endif</span>
                                                </label>
                                            @empty
                                                <p class="p-2 text-sm text-slate-500">Nema rezultata pretrage.</p>
                                            @endforelse
                                        </div>
                                        <div class="mt-2 flex flex-wrap items-center justify-between gap-2 text-xs text-slate-500">
                                            <span>{{ $chosenFilterIds === [] ? 'Bez odabira — uključena je cijela ponuda.' : 'Odabrano: '.count($chosenFilterIds) }}</span>
                                            @if ($chosenFilterIds !== [])
                                                <button type="button" wire:click="clearWatchlineFilter('{{ $fieldKey }}')" class="font-semibold text-cyan-700 hover:text-cyan-900">Poništi odabir</button>
                                            @endif
                                        </div>
                                    @elseif (($field['type'] ?? '') === 'select')
                                        <select id="watchline-field-{{ $fieldKey }}" wire:model.live="form.watchline.{{ $fieldKey }}" class="w-full rounded-xl border border-slate-300 px-3 py-2 text-sm">
                                            @foreach (($field['options'] ?? []) as $optionValue => $optionLabel)
                                                <option value="{{ $optionValue }}">{{ $optionLabel }}</option>
                                            @endforeach
                                        </select>
                                        @if (in_array(($form['type'] ?? ''), ['watchline_products', 'lavaggio_products'], true) && $fieldKey === 'source')
                                            <p class="mt-2 text-xs leading-relaxed text-slate-500">
                                                @if (($form['watchline']['source'] ?? '') === 'new_products')
                                                    Prikazuje artikle kojima je u katalogu uključena oznaka „Novo u ponudi”.
                                                @elseif (($form['watchline']['source'] ?? '') === 'latest')
                                                    Prikazuje najnovije dodane artikle iz odabranih kategorija i brandova.
                                                @elseif (($form['watchline']['source'] ?? '') === 'filtered')
                                                    Artikli se biraju automatski iz označenih kategorija i brandova. Nije ih potrebno dodavati pojedinačno.
                                                @elseif (($form['watchline']['source'] ?? 'manual') === 'manual')
                                                    Ručno odaberite artikle ispod. Odabrane kategorije i brandovi dodatno sužavaju taj odabir.
                                                    Ako nema ručno odabranih artikala, odabir kategorije ili branda uključuje automatski prikaz.
                                                @else
                                                    Ponudu možete dodatno suziti odabirom kategorija i brandova.
                                                @endif
                                            </p>
                                        @endif
                                    @elseif (($field['type'] ?? '') === 'textarea')
                                        <textarea id="watchline-field-{{ $fieldKey }}" rows="3" wire:model="form.watchline.{{ $fieldKey }}" class="w-full rounded-xl border border-slate-300 px-3 py-2 text-sm"></textarea>
                                    @elseif (($field['type'] ?? '') === 'number')
                                        <input id="watchline-field-{{ $fieldKey }}" type="number" min="{{ $field['min'] ?? 1 }}" max="{{ $field['max'] ?? 50 }}" wire:model="form.watchline.{{ $fieldKey }}" class="w-full rounded-xl border border-slate-300 px-3 py-2 text-sm">
                                    @elseif (($field['type'] ?? '') === 'image')
                                        <input id="watchline-field-{{ $fieldKey }}" type="file" accept="image/jpeg,image/png,image/webp" wire:model="watchlineImageUploads.{{ $fieldKey }}" class="block w-full rounded-lg border border-slate-300 bg-white p-2 text-sm text-slate-600 file:mr-3 file:rounded-md file:border-0 file:bg-cyan-50 file:px-3 file:py-2 file:text-cyan-800">
                                        <p class="mt-1 text-xs text-slate-500">JPG, PNG ili WebP, do 5 MB.</p>
                                        <p wire:loading wire:target="watchlineImageUploads.{{ $fieldKey }}" class="mt-1 text-xs text-cyan-700">Slika se učitava…</p>
                                        @error('watchlineImageUploads.'.$fieldKey)<p class="mt-1 text-xs text-rose-600">{{ $message }}</p>@enderror
                                        @php
                                            $pendingImage = $watchlineImageUploads[$fieldKey] ?? null;
                                            $previewImage = $pendingImage instanceof \Livewire\Features\SupportFileUploads\TemporaryUploadedFile && ! $errors->has('watchlineImageUploads.'.$fieldKey)
                                                ? $pendingImage->temporaryUrl()
                                                : \App\Support\WatchlineWidgetSettings::safeUrl($form['watchline'][$fieldKey] ?? '', true);
                                        @endphp
                                        @if ($previewImage !== '')
                                            <img src="{{ $previewImage }}" alt="Pregled odabrane slike" width="160" height="100" class="mt-2 h-24 max-w-full rounded-lg border border-slate-200 bg-white object-contain" loading="lazy">
                                        @endif
                                        <details class="mt-2 text-xs text-slate-500">
                                            <summary class="cursor-pointer">Ili upotrijebite postojeću poveznicu slike</summary>
                                            <input type="text" aria-label="{{ $field['label'] }} — poveznica" wire:model.live.debounce.500ms="form.watchline.{{ $fieldKey }}" placeholder="/image/catalog/... ili https://..." class="mt-2 w-full rounded-xl border border-slate-300 px-3 py-2 text-sm">
                                        </details>
                                    @else
                                        <input id="watchline-field-{{ $fieldKey }}" type="text" @if (($field['type'] ?? '') === 'icon') list="watchline-widget-icons" @endif wire:model="form.watchline.{{ $fieldKey }}" class="w-full rounded-xl border border-slate-300 px-3 py-2 text-sm">
                                    @endif
                                @endif
                                @error('form.watchline.'.$fieldKey)<p class="mt-1 text-xs text-rose-600">{{ $message }}</p>@enderror
                                @error('form.watchline.'.$fieldKey.'.*')<p class="mt-1 text-xs text-rose-600">{{ $message }}</p>@enderror
                            </div>
                        @endforeach
                    </div>
                    <datalist id="watchline-widget-icons">
                        @foreach (['check' => 'Kvačica', 'heart' => 'Srce', 'comments' => 'Razgovor', 'truck-fast' => 'Dostava', 'credit-card' => 'Plaćanje', 'badge-check' => 'Potvrda', 'screwdriver-wrench' => 'Servis', 'location-dot' => 'Lokacija', 'clock' => 'Radno vrijeme', 'rotate-left' => 'Povrat', 'arrow-right' => 'Strelica desno', 'arrow-up-right' => 'Dijagonalna strelica'] as $iconName => $iconLabel)
                            <option value="{{ $iconName }}">{{ $iconLabel }}</option>
                        @endforeach
                    </datalist>
                @endif

                @if (in_array(($form['type'] ?? ''), ['five_star_reviews_carousel', 'blogs_carousel', 'watchline_reviews', 'watchline_blog', 'watchline_products', 'lavaggio_reviews', 'lavaggio_blog', 'lavaggio_products'], true))
                    <div class="mt-3">
                        <label class="mb-1 block text-xs font-semibold uppercase tracking-[0.14em] text-slate-500">
                            @if (in_array(($form['type'] ?? ''), ['blogs_carousel', 'watchline_blog', 'lavaggio_blog'], true))
                                {{ __('Number of blog posts to show') }}
                            @elseif (in_array(($form['type'] ?? ''), ['watchline_products', 'lavaggio_products'], true))
                                {{ __('Number of products to show') }}
                            @else
                                {{ __('Number of comments to show') }}
                            @endif
                        </label>
                        <input type="number" min="1" max="50" wire:model="form.items_limit" class="w-full rounded-xl border border-slate-300 px-3 py-2 text-sm md:max-w-[220px]" />
                        @error('form.items_limit') <p class="mt-1 text-xs text-rose-600">{{ $message }}</p> @enderror

                        @if (in_array(($form['type'] ?? ''), ['blogs_carousel', 'watchline_blog', 'lavaggio_blog'], true))
                            <div class="mt-2 md:max-w-[220px]">
                                <label class="mb-1 block text-xs font-semibold uppercase tracking-[0.14em] text-slate-500">{{ __('Blog source') }}</label>
                                <select wire:model="form.blog_source" data-tom-select data-tom-no-search="1" class="admin-select w-full rounded-xl border border-slate-300 px-3 py-2 text-sm">
                                    <option value="latest">{{ __('Latest') }}</option>
                                    <option value="featured">{{ __('Featured only') }}</option>
                                </select>
                            </div>
                        @elseif (in_array(($form['type'] ?? ''), ['five_star_reviews_carousel', 'watchline_reviews', 'lavaggio_reviews'], true))
                            <label class="inline-flex items-center gap-2">
                                <input type="checkbox" wire:model="form.reviews_featured_only" class="h-4 w-4 border-slate-300 text-slate-900 focus:ring-0">
                                <span class="text-xs font-semibold uppercase tracking-[0.14em] text-slate-600">{{ __('Featured comments only') }}</span>
                            </label>
                        @endif
                    </div>
                @endif

                @if (($form['type'] ?? '') === 'material_craftsmanship')
                    <div class="mt-5 border-t border-slate-200 pt-5">
                        <p class="admin-section-title">{{ __('Material Widget Texts') }}</p>
                        <p class="mt-1 text-xs text-slate-500">{{ __('These fields control the two material cards and their icon labels.') }}</p>

                        <div class="mt-4">
                            <label class="mb-1 block text-xs font-semibold uppercase tracking-[0.14em] text-slate-500">{{ __('Expand Label') }}</label>
                            <input type="text" wire:model="form.material_craftsmanship.expand_label" class="w-full rounded-xl border border-slate-300 px-3 py-2 text-sm" />
                            @error('form.material_craftsmanship.expand_label') <p class="mt-1 text-xs text-rose-600">{{ $message }}</p> @enderror
                        </div>

                        <div class="mt-4 grid gap-4">
                            @foreach (['micromodal' => __('Micromodal'), 'giza' => __('Giza pamuk')] as $materialKey => $materialLabel)
                                <div class="rounded-xl border border-slate-200 bg-slate-50 p-4">
                                    <p class="text-sm font-semibold text-slate-900">{{ $materialLabel }}</p>

                                    <div class="mt-3 grid gap-3 md:grid-cols-2">
                                        <div>
                                            <label class="mb-1 block text-xs font-semibold uppercase tracking-[0.14em] text-slate-500">{{ __('Eyebrow') }}</label>
                                            <input type="text" wire:model="form.material_craftsmanship.materials.{{ $materialKey }}.eyebrow" class="w-full rounded-xl border border-slate-300 px-3 py-2 text-sm" />
                                        </div>
                                        <div>
                                            <label class="mb-1 block text-xs font-semibold uppercase tracking-[0.14em] text-slate-500">{{ __('Card Title') }}</label>
                                            <input type="text" wire:model="form.material_craftsmanship.materials.{{ $materialKey }}.title" class="w-full rounded-xl border border-slate-300 px-3 py-2 text-sm" />
                                        </div>
                                    </div>

                                    <div class="mt-3">
                                        <label class="mb-1 block text-xs font-semibold uppercase tracking-[0.14em] text-slate-500">{{ __('Intro Text') }}</label>
                                        <textarea rows="2" wire:model="form.material_craftsmanship.materials.{{ $materialKey }}.intro" class="w-full rounded-xl border border-slate-300 px-3 py-2 text-sm"></textarea>
                                    </div>

                                    <div class="mt-3 grid gap-3 md:grid-cols-2">
                                        <div>
                                            <label class="mb-1 block text-xs font-semibold uppercase tracking-[0.14em] text-slate-500">{{ __('Expanded Text 1') }}</label>
                                            <textarea rows="4" wire:model="form.material_craftsmanship.materials.{{ $materialKey }}.body_1" class="w-full rounded-xl border border-slate-300 px-3 py-2 text-sm"></textarea>
                                        </div>
                                        <div>
                                            <label class="mb-1 block text-xs font-semibold uppercase tracking-[0.14em] text-slate-500">{{ __('Expanded Text 2') }}</label>
                                            <textarea rows="4" wire:model="form.material_craftsmanship.materials.{{ $materialKey }}.body_2" class="w-full rounded-xl border border-slate-300 px-3 py-2 text-sm"></textarea>
                                        </div>
                                    </div>

                                    <div class="mt-3 grid gap-3 md:grid-cols-3">
                                        @for ($bulletIndex = 0; $bulletIndex < 3; $bulletIndex++)
                                            <div>
                                                <label class="mb-1 block text-xs font-semibold uppercase tracking-[0.14em] text-slate-500">{{ __('Icon Label') }} {{ $bulletIndex + 1 }}</label>
                                                <input type="text" wire:model="form.material_craftsmanship.materials.{{ $materialKey }}.bullets.{{ $bulletIndex }}" class="w-full rounded-xl border border-slate-300 px-3 py-2 text-sm" />
                                            </div>
                                        @endfor
                                    </div>
                                </div>
                            @endforeach
                        </div>
                    </div>
                @endif
            </div>

            @if (! $this->isWatchlineWidget)
            <div class="admin-panel admin-form-panel p-6">
                <p class="admin-section-title">{{ __('Style & Background') }}</p>

                <div class="mt-4">
                    <label class="mb-1 block text-xs font-semibold uppercase tracking-[0.14em] text-slate-500">{{ __('Custom Classes') }}</label>
                    <input type="text" wire:model="form.custom_classes" class="w-full rounded-xl border border-slate-300 px-3 py-2 text-sm" placeholder="{{ __('extra utility classes') }}" />
                </div>

                <div class="mt-3">
                    <label class="mb-1 block text-xs font-semibold uppercase tracking-[0.14em] text-slate-500">{{ __('Background CSS') }}</label>
                    <textarea rows="4" wire:model="form.bg_css" class="w-full rounded-xl border border-slate-300 px-3 py-2 font-mono text-xs" placeholder="{{ __('background-color:#0f172a; color:white;') }}"></textarea>
                    <p class="mt-1 text-xs text-slate-500">{{ __('If a background image is uploaded, it is applied first, then this CSS is appended.') }}</p>
                </div>
            </div>
            @endif
        </div>

        @if (($form['type'] ?? '') === 'category_products_carousel')
            <div class="admin-panel admin-form-panel p-6">
                <p class="admin-section-title">{{ __('Product display settings') }}</p>
                <p class="mt-1 text-xs text-slate-500">{{ __('Control how many products are loaded, which brands are included, and how products are sorted.') }}</p>

                <div class="mt-4 grid gap-4 md:grid-cols-2">
                    <div>
                        <label class="mb-1 block text-xs font-semibold uppercase tracking-[0.14em] text-slate-500">{{ __('Number of products to show') }}</label>
                        <input type="number" min="1" max="50" wire:model="form.items_limit" class="w-full rounded-xl border border-slate-300 px-3 py-2 text-sm" />
                        @error('form.items_limit') <p class="mt-1 text-xs text-rose-600">{{ $message }}</p> @enderror
                    </div>

                    <div>
                        <label class="mb-1 block text-xs font-semibold uppercase tracking-[0.14em] text-slate-500">{{ __('Product sorting') }}</label>
                        <select wire:model.live="form.product_sort" data-tom-select data-tom-no-search="1" class="admin-select w-full rounded-xl border border-slate-300 px-3 py-2 text-sm">
                            @foreach ($this->categoryProductSortOptions as $sortKey => $sortLabel)
                                <option value="{{ $sortKey }}" @selected(($form['product_sort'] ?? 'category_order') === $sortKey)>{{ $sortLabel }}</option>
                            @endforeach
                        </select>
                        @error('form.product_sort') <p class="mt-1 text-xs text-rose-600">{{ $message }}</p> @enderror
                    </div>
                </div>

                <div class="mt-5">
                    <div class="flex flex-wrap items-center justify-between gap-2">
                        <div>
                            <label class="block text-xs font-semibold uppercase tracking-[0.14em] text-slate-500">{{ __('Brand filter') }}</label>
                            <p class="mt-1 text-xs text-slate-500">{{ __('Leave all brands unchecked to include products from every brand.') }}</p>
                        </div>
                        @if (count((array) ($form['manufacturer_ids'] ?? [])) > 0)
                            <button type="button" wire:click="clearManufacturerFilters" class="text-xs font-semibold text-cyan-700 hover:text-cyan-900">
                                {{ __('Clear brand filter') }} ({{ count((array) ($form['manufacturer_ids'] ?? [])) }})
                            </button>
                        @endif
                    </div>

                    <input
                        type="text"
                        wire:model.live.debounce.300ms="manufacturerFilterSearch"
                        class="mt-3 w-full rounded-xl border border-slate-300 px-3 py-2 text-sm"
                        placeholder="{{ __('Search brands...') }}"
                    />

                    <div class="mt-2 max-h-56 overflow-y-auto rounded-xl border border-slate-200 bg-slate-50 p-3">
                        <div class="grid gap-2 sm:grid-cols-2 xl:grid-cols-3">
                            @forelse ($this->manufacturerFilterOptions as $manufacturer)
                                <label wire:key="category-product-manufacturer-{{ $manufacturer['id'] }}" class="flex cursor-pointer items-center gap-2 rounded-lg bg-white px-3 py-2 text-sm text-slate-700 shadow-sm ring-1 ring-slate-200">
                                    <input
                                        type="checkbox"
                                        value="{{ $manufacturer['id'] }}"
                                        wire:model="form.manufacturer_ids"
                                        class="rounded border-slate-300 text-cyan-700 focus:ring-cyan-500"
                                    />
                                    <span>
                                        {{ $manufacturer['label'] }}
                                        @if (! $manufacturer['is_active'])
                                            <span class="text-xs text-slate-400">({{ __('inactive') }})</span>
                                        @endif
                                    </span>
                                </label>
                            @empty
                                <p class="text-sm text-slate-500 sm:col-span-2 xl:col-span-3">{{ __('No brands found.') }}</p>
                            @endforelse
                        </div>
                    </div>
                    @error('form.manufacturer_ids') <p class="mt-1 text-xs text-rose-600">{{ $message }}</p> @enderror
                    @error('form.manufacturer_ids.*') <p class="mt-1 text-xs text-rose-600">{{ $message }}</p> @enderror
                </div>
            </div>
        @endif

        @if (($form['type'] ?? '') === 'featured_categories')
            <div class="admin-panel admin-form-panel p-6">
                <p class="admin-section-title">{{ __('Prikaz kategorija') }}</p>
                <div class="mt-4 md:max-w-md">
                    <label for="featured-category-source" class="mb-1 block text-xs font-semibold uppercase tracking-[0.14em] text-slate-500">{{ __('Kategorije u modulu') }}</label>
                    <select id="featured-category-source" wire:model.live="form.category_source" class="admin-select w-full rounded-xl border border-slate-300 px-3 py-2 text-sm">
                        <option value="all_root">{{ __('Sve glavne kategorije') }}</option>
                        <option value="manual">{{ __('Ručni odabir i redoslijed') }}</option>
                    </select>
                    <p class="mt-2 text-xs text-slate-500">{{ __('Sve glavne kategorije prikazuju se prema redoslijedu u katalogu. Ručnim odabirom možete složiti vlastiti popis i redoslijed.') }}</p>
                    @error('form.category_source') <p class="mt-1 text-xs text-rose-600">{{ $message }}</p> @enderror
                </div>
            </div>
        @endif

        @if ($this->isItemBlock && !(($form['type'] ?? '') === 'featured_categories' && ($form['category_source'] ?? 'manual') === 'all_root') && (! in_array(($form['type'] ?? ''), ['watchline_products', 'watchline_brands', 'lavaggio_products', 'lavaggio_brands'], true) || ($form['watchline']['source'] ?? 'manual') === 'manual'))
            <div class="admin-panel admin-form-panel p-6">
                <p class="admin-section-title">
                    @if (($form['type'] ?? '') === 'category_products_carousel')
                        {{ __('Source category') }}
                    @elseif (($form['type'] ?? '') === 'featured_categories')
                        {{ __('Featured Categories') }}
                    @elseif (($form['type'] ?? '') === 'popular_brands')
                        {{ __('Popular Brands') }}
                    @else
                        {{ __('Selected Items') }}
                    @endif
                </p>
                <p class="mt-1 text-xs text-slate-500">
                    @if (($form['type'] ?? '') === 'category_products_carousel')
                        {{ __('Choose one category. Products are loaded automatically from it and its subcategories.') }}
                    @elseif (($form['type'] ?? '') === 'featured_categories')
                        {{ __('Choose and order the categories shown in this module.') }}
                    @elseif (($form['type'] ?? '') === 'popular_brands')
                        {{ __('Choose and order the brands shown in this module.') }}
                    @elseif (in_array(($form['type'] ?? ''), ['watchline_blog', 'lavaggio_blog'], true))
                        Odaberite članke i složite njihov redoslijed. Ostavite prazno za automatski odabir članaka.
                    @else
                        {{ __('Choose items and order them. No JSON IDs needed.') }}
                    @endif
                </p>

                <div class="mt-4 grid gap-3 md:grid-cols-[1fr_auto] md:items-end">
                    <div>
                        <label class="mb-1 block text-xs font-semibold uppercase tracking-[0.14em] text-slate-500">{{ __('Available') }}</label>
                        <input
                            type="text"
                            wire:model.live.debounce.300ms="pickerSearch"
                            class="mb-2 w-full rounded-xl border border-slate-300 px-3 py-2 text-sm"
                            placeholder="{{ __('Search all items from database...') }}"
                        />
                        @if (in_array(($form['type'] ?? ''), ['watchline_products', 'lavaggio_products'], true))
                            <p class="mb-2 text-xs leading-relaxed text-slate-500">Prikazano je najviše 100 rezultata. Za ostale artikle upišite naziv ili šifru.</p>
                        @endif
                        <select wire:key="picker-select-{{ md5(($this->currentItemType ?? 'none').'|'.$pickerSearch.'|'.($this->itemOptions->count())) }}" wire:model="pickerItemId" class="admin-select w-full rounded-xl border border-slate-300 px-3 py-2 text-sm">
                            <option value="">{{ __('Select item...') }}</option>
                            @foreach ($this->itemOptions as $option)
                                <option value="{{ $option['id'] }}">{{ $option['label'] }}</option>
                            @endforeach
                        </select>
                    </div>
                    <button type="button" wire:click="addSelectedItem" class="h-10 rounded-xl bg-cyan-700 px-4 text-sm font-semibold text-white hover:bg-cyan-800">{{ __('Add Item') }}</button>
                </div>

                <div class="mt-4 space-y-2">
                    @forelse ($this->selectedItems as $row)
                        <div class="flex items-center justify-between gap-2 rounded-xl border border-slate-200 bg-slate-50 px-3 py-2">
                            <div class="text-sm text-slate-800">{{ $row['label'] }}</div>
                            <div class="inline-flex items-center gap-1">
                                <button type="button" wire:click="moveSelectedItemUp({{ $row['index'] }})" class="rounded-lg border border-slate-300 px-2 py-1 text-xs font-semibold text-slate-700 hover:bg-slate-100">{{ __('Up') }}</button>
                                <button type="button" wire:click="moveSelectedItemDown({{ $row['index'] }})" class="rounded-lg border border-slate-300 px-2 py-1 text-xs font-semibold text-slate-700 hover:bg-slate-100">{{ __('Down') }}</button>
                                <button type="button" wire:click="removeSelectedItem({{ $row['id'] }})" class="rounded-lg border border-rose-200 px-2 py-1 text-xs font-semibold text-rose-700 hover:bg-rose-50">{{ __('Remove') }}</button>
                            </div>
                        </div>
                    @empty
                        <div class="rounded-xl border border-dashed border-slate-300 bg-slate-50 p-4 text-sm text-slate-500">{{ __('No items selected.') }}</div>
                    @endforelse
                    @error('form.selected_item_ids') <p class="mt-1 text-xs text-rose-600">{{ $message }}</p> @enderror
                </div>
            </div>
        @endif

        @if ($blockId)
            <livewire:admin.media.manager
                :model-class="\App\Models\Content\ContentBlock::class"
                :model-id="$blockId"
                :locale="$form['locale']"
                :wire:key="'content-block-media-manager-'.($blockId ?? 'new').'-'.$form['locale']"
            />
        @endif

        <div class="admin-form-actions flex items-center gap-2 pt-2">
            <button type="submit" class="rounded-xl bg-cyan-700 px-4 py-2 text-sm font-semibold text-white hover:bg-cyan-800">
                {{ $this->isEdit ? __('Update Block') : __('Create Block') }}
            </button>
            <button type="button" wire:click="backToList" class="rounded-xl border border-slate-300 px-4 py-2 text-sm font-semibold text-slate-700 hover:bg-slate-100">
                {{ __('Cancel') }}
            </button>
        </div>
    </form>
</div>
