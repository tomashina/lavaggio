<div class="space-y-6">
    <div class="admin-panel admin-search-panel p-6">
        <div class="flex flex-col gap-4">
            <div>
                <h1 class="text-xl font-semibold tracking-tight">{{ __('admin.content.faq.manager.title') }}</h1>
                <p class="mt-1 text-sm text-slate-600">{{ __('admin.content.faq.manager.subtitle') }}</p>
                <p class="mt-2 text-xs text-slate-500">{{ __('admin.content.faq.manager.items_per_page') }}: <span class="admin-chip">{{ $perPage }}</span></p>
            </div>

            <div class="flex w-full min-w-0 flex-col gap-3 lg:flex-row lg:items-end">
                <div class="grid min-w-0 flex-1 gap-3 sm:grid-cols-2 xl:grid-cols-[minmax(0,1fr)_10rem_9rem_8rem]">
                    <div>
                        <label for="faq-list-search" class="mb-1 block text-xs font-semibold uppercase tracking-[0.14em] text-slate-500">{{ __('admin.common.search') }}</label>
                        <div class="relative">
                            <input id="faq-list-search" type="text" wire:model.live.debounce.300ms="search" placeholder="{{ __('admin.content.faq.manager.search_placeholder') }}" class="admin-search-input w-full rounded-xl border px-3 py-2 pr-10 text-sm" />
                            @if ($search !== '')
                                <button type="button" wire:click="$set('search', '')" aria-label="{{ __('admin.common.clear_search') }}" class="absolute inset-y-0 right-0 flex w-10 items-center justify-center rounded-r-xl text-lg text-slate-500 hover:text-slate-900">
                                    <span aria-hidden="true">×</span>
                                </button>
                            @endif
                        </div>
                    </div>
                    <div>
                        <label for="faq-list-group" class="mb-1 block text-xs font-semibold uppercase tracking-[0.14em] text-slate-500">{{ __('admin.content.faq.manager.group') }}</label>
                        <select id="faq-list-group" wire:model.live="group" data-tom-select class="admin-search-input admin-select w-full rounded-xl border px-3 py-2 text-sm">
                            <option value="all">{{ __('admin.content.faq.manager.all_groups') }}</option>
                            @foreach ($this->groupOptions as $groupCode)
                                <option value="{{ $groupCode }}">{{ $faqGroupLabels[$groupCode] ?? $groupCode }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div>
                        <label for="faq-list-state" class="mb-1 block text-xs font-semibold uppercase tracking-[0.14em] text-slate-500">{{ __('admin.common.state') }}</label>
                        <select id="faq-list-state" wire:model.live="state" data-tom-select data-tom-no-search="1" class="admin-search-input admin-select w-full rounded-xl border px-3 py-2 text-sm">
                            <option value="active">{{ __('admin.common.active') }}</option>
                            <option value="inactive">{{ __('admin.common.inactive') }}</option>
                            <option value="all">{{ __('admin.common.all') }}</option>
                        </select>
                    </div>
                    <div>
                        <label for="faq-list-locale" class="mb-1 block text-xs font-semibold uppercase tracking-[0.14em] text-slate-500">{{ __('admin.common.locale') }}</label>
                        <select id="faq-list-locale" wire:model.live="locale" data-tom-select data-tom-no-search="1" class="admin-search-input admin-select w-full rounded-xl border px-3 py-2 text-sm lowercase">
                            @foreach ($adminLocaleOptions as $localeOption)
                                <option value="{{ $localeOption }}">{{ $localeOption }}</option>
                            @endforeach
                        </select>
                    </div>
                </div>
                <a href="{{ route('admin.content.faqs.create', ['locale' => $locale]) }}" class="inline-flex shrink-0 items-center justify-center whitespace-nowrap rounded-xl bg-cyan-700 px-4 py-2 text-sm font-semibold text-white hover:bg-cyan-800">
                    {{ __('admin.common.create') }}
                </a>
            </div>
        </div>
    </div>

    @if (config('storefront.home') === 'lavaggio')
        <section class="admin-panel p-5" aria-label="Teme pitanja i odgovora">
            <div class="mb-4 flex flex-wrap items-center justify-between gap-3"><div><h2 class="admin-section-title">Teme pitanja i odgovora</h2><p class="mt-1 text-sm text-slate-600">Dodajte naslove tema i uredite njihov redoslijed. Na pojedinom pitanju odaberite kojoj temi pripada.</p></div><button type="button" wire:click="addGroup" class="rounded-xl border border-slate-300 px-3 py-2 text-sm font-semibold">Dodaj temu</button></div>
            @foreach ($faqGroups as $groupIndex => $faqGroup)
                <div class="mb-3 grid items-start gap-3 sm:grid-cols-[minmax(0,1fr)_8rem_auto]" wire:key="faq-group-{{ $faqGroup['code'] }}">
                    <div><label class="mb-1 block text-xs font-semibold" for="faq-group-title-{{ $groupIndex }}">Naslov teme</label><input id="faq-group-title-{{ $groupIndex }}" type="text" wire:model="faqGroups.{{ $groupIndex }}.title" class="w-full rounded-xl border-slate-300 text-sm">@error('faqGroups.'.$groupIndex.'.title')<p class="text-xs text-rose-600">{{ $message }}</p>@enderror</div>
                    <div><label class="mb-1 block text-xs font-semibold" for="faq-group-order-{{ $groupIndex }}">Redni broj</label><input id="faq-group-order-{{ $groupIndex }}" type="number" min="0" max="9999" wire:model="faqGroups.{{ $groupIndex }}.sort_order" class="w-full rounded-xl border-slate-300 text-sm">@error('faqGroups.'.$groupIndex.'.sort_order')<p class="text-xs text-rose-600">{{ $message }}</p>@enderror</div>
                    <button type="button" wire:click="removeGroup({{ $groupIndex }})" class="mt-6 rounded-xl border border-rose-200 px-3 py-2 text-sm font-semibold text-rose-700">Ukloni</button>
                </div>
            @endforeach
            @error('faqGroups')<p class="mb-3 text-sm text-rose-600">{{ $message }}</p>@enderror
            <button type="button" wire:click="saveGroups" class="rounded-xl bg-cyan-700 px-4 py-2 text-sm font-semibold text-white">Spremi teme</button>
        </section>
    @endif

    <div class="admin-panel admin-panel-soft p-5">
        <h2 class="admin-section-title">{{ __('admin.common.items') }}</h2>

        <div class="mt-4 overflow-x-auto">
            <table class="admin-items-table min-w-full text-sm">
                <thead class="text-slate-600">
                    <tr>
                        <th class="px-3 py-2 text-left font-semibold">{{ __('admin.content.faq.manager.table.faq') }}</th>
                        <th class="px-3 py-2 text-left font-semibold">{{ __('admin.content.faq.manager.table.slug') }}</th>
                        <th class="px-3 py-2 text-center font-semibold">{{ __('admin.content.faq.manager.table.group') }}</th>
                        <th class="px-3 py-2 text-center font-semibold">{{ __('admin.content.faq.manager.table.comments') }}</th>
                        <th class="px-3 py-2 text-center font-semibold">{{ __('admin.content.faq.manager.table.featured') }}</th>
                        <th class="px-3 py-2 text-center font-semibold">{{ __('admin.content.faq.manager.table.state') }}</th>
                        <th class="px-3 py-2 text-right font-semibold">{{ __('admin.content.faq.manager.table.actions') }}</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                    @forelse ($rows as $row)
                        @php $tr = $row->translations->first(); @endphp
                        <tr>
                            <td class="px-3 py-2 text-slate-800">
                                <div class="font-medium">{{ $tr?->question ?? __('admin.content.faq.manager.missing_question') }}</div>
                                <div class="text-xs text-slate-500">{{ $row->code }}</div>
                            </td>
                            <td class="px-3 py-2 font-mono text-xs text-slate-700">{{ $tr?->slug ?? '-' }}</td>
                            <td class="px-3 py-2 text-center text-slate-700">{{ $faqGroupLabels[$row->group_code] ?? $row->group_code }}</td>
                            <td class="px-3 py-2 text-center text-slate-700">{{ $row->comments_count }}</td>
                            <td class="px-3 py-2 text-center">
                                <span class="rounded-full px-2.5 py-1 text-xs font-semibold {{ $row->is_featured ? 'bg-cyan-100 text-cyan-800' : 'bg-slate-200 text-slate-700' }}">
                                    {{ $row->is_featured ? __('admin.common.yes') : __('admin.common.no') }}
                                </span>
                            </td>
                            <td class="px-3 py-2 text-center">
                                <span class="rounded-full px-2.5 py-1 text-xs font-semibold {{ $row->is_active ? 'bg-emerald-100 text-emerald-800' : 'bg-slate-200 text-slate-700' }}">
                                    {{ $row->is_active ? __('admin.common.active') : __('admin.common.inactive') }}
                                </span>
                            </td>
                            <td class="px-3 py-2">
                                <div class="flex justify-end gap-2">
                                    <a href="{{ route('admin.content.faqs.edit', ['faq' => $row->id, 'locale' => $locale]) }}" class="rounded-lg border border-slate-300 px-2 py-1 text-xs font-semibold text-slate-700 hover:bg-slate-100">
                                        {{ __('admin.common.edit') }}
                                    </a>
                                    <button
                                        type="button"
                                        wire:click="delete({{ (int) $row->id }})"
                                        wire:confirm="{{ __('admin.content.faq.manager.confirm_delete', ['name' => $tr?->question ?? $row->code]) }}"
                                        class="rounded-lg border border-rose-300 px-2 py-1 text-xs font-semibold text-rose-700 hover:bg-rose-50"
                                    >
                                        {{ __('admin.common.delete') }}
                                    </button>
                                </div>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="7" class="px-3 py-8 text-center text-sm text-slate-500">{{ $search !== '' || $group !== 'all' || $state !== 'all' ? __('admin.common.no_search_results') : __('admin.content.faq.manager.empty') }}</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        <div class="mt-4">
            {{ $rows->links() }}
        </div>
    </div>
</div>
