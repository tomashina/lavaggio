<div class="space-y-6">
    <div class="admin-panel admin-search-panel p-6">
        <div class="flex flex-col gap-4 2xl:flex-row 2xl:items-end 2xl:justify-between">
            <div>
                <h1 class="text-xl font-semibold tracking-tight">{{ __('admin.content.pages.manager.title') }}</h1>
                <p class="mt-1 text-sm text-slate-600">{{ __('admin.content.pages.manager.subtitle') }}</p>
                <p class="mt-2 text-xs text-slate-500">{{ __('admin.content.pages.manager.items_per_page') }}: <span class="admin-chip">{{ $perPage }}</span></p>
            </div>

            <div class="flex w-full min-w-0 flex-col gap-3 sm:flex-row sm:items-end 2xl:max-w-2xl">
                <div class="grid min-w-0 flex-1 gap-3 sm:grid-cols-[minmax(0,1fr)_8rem]">
                    <div>
                        <label for="page-list-search" class="mb-1 block text-xs font-semibold uppercase tracking-[0.14em] text-slate-500">{{ __('admin.common.search') }}</label>
                        <div class="relative">
                            <input
                                id="page-list-search"
                                type="text"
                                wire:model.live.debounce.300ms="search"
                                placeholder="{{ __('admin.content.pages.manager.search_placeholder') }}"
                                class="admin-search-input w-full rounded-xl border px-3 py-2 pr-10 text-sm"
                            />
                            @if ($search !== '')
                                <button type="button" wire:click="$set('search', '')" aria-label="{{ __('admin.common.clear_search') }}" class="absolute inset-y-0 right-0 flex w-10 items-center justify-center rounded-r-xl text-lg text-slate-500 hover:text-slate-900">
                                    <span aria-hidden="true">×</span>
                                </button>
                            @endif
                        </div>
                    </div>
                    <div>
                        <label for="page-list-locale" class="mb-1 block text-xs font-semibold uppercase tracking-[0.14em] text-slate-500">{{ __('admin.common.locale') }}</label>
                        <select id="page-list-locale" wire:model.live="locale" data-tom-select data-tom-no-search="1" class="admin-search-input admin-select w-full rounded-xl border px-3 py-2 text-sm lowercase">
                              @foreach ($adminLocaleOptions as $localeOption)
                                <option value="{{ $localeOption }}">{{ $localeOption }}</option>
                            @endforeach
                        </select>
                    </div>
                </div>
                <a href="{{ route('admin.content.pages.create', ['locale' => $locale]) }}" class="inline-flex shrink-0 items-center justify-center whitespace-nowrap rounded-xl bg-cyan-700 px-4 py-2 text-sm font-semibold text-white hover:bg-cyan-800">
                    {{ __('admin.common.create') }}
                </a>
            </div>
        </div>
    </div>

    <div class="admin-panel admin-panel-soft p-5">
        <h2 class="admin-section-title">{{ __('admin.common.items') }}</h2>

        <div class="mt-4 overflow-x-auto">
            <table class="admin-items-table min-w-full text-sm">
                <thead class="text-slate-600">
                    <tr>
                        <th class="px-3 py-2 text-left font-semibold">{{ __('admin.content.pages.manager.table.page') }}</th>
                        <th class="px-3 py-2 text-left font-semibold">{{ __('admin.content.pages.manager.table.slug') }}</th>
                        <th class="px-3 py-2 text-center font-semibold">{{ __('admin.content.pages.manager.table.layout') }}</th>
                        <th class="px-3 py-2 text-center font-semibold">{{ __('admin.content.pages.manager.table.categories') }}</th>
                        <th class="px-3 py-2 text-center font-semibold">{{ __('admin.content.pages.manager.table.state') }}</th>
                        <th class="px-3 py-2 text-right font-semibold">{{ __('admin.content.pages.manager.table.actions') }}</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                    @forelse ($rows as $row)
                        @php $tr = $row->translations->first(); @endphp
                        <tr>
                            <td class="px-3 py-2 text-slate-800">
                                <div class="font-medium">{{ $tr?->title ?? __('admin.content.pages.manager.missing_title') }}</div>
                                <div class="text-xs text-slate-500">{{ $row->code }}</div>
                            </td>
                            <td class="px-3 py-2 font-mono text-xs text-slate-700">{{ $tr?->slug ?? '-' }}</td>
                            <td class="px-3 py-2 text-center text-slate-700">{{ $row->layout }}</td>
                            <td class="px-3 py-2 text-center text-slate-700">{{ $row->categories_count }}</td>
                            <td class="px-3 py-2 text-center">
                                <span class="rounded-full px-2.5 py-1 text-xs font-semibold {{ $row->is_active ? 'bg-emerald-100 text-emerald-800' : 'bg-slate-200 text-slate-700' }}">
                                    {{ $row->is_active ? __('admin.common.active') : __('admin.common.inactive') }}
                                </span>
                            </td>
                            <td class="px-3 py-2">
                                <div class="flex justify-end gap-2">
                                <a href="{{ route('admin.content.pages.edit', ['page' => $row->id, 'locale' => $locale]) }}" class="rounded-lg border border-slate-300 px-2 py-1 text-xs font-semibold text-slate-700 hover:bg-slate-100">
                                    {{ __('admin.common.edit') }}
                                </a>
                                <button
                                    type="button"
                                    wire:click="delete({{ (int) $row->id }})"
                                    wire:confirm="{{ __('admin.content.pages.manager.confirm_delete', ['name' => $tr?->title ?? $row->code]) }}"
                                    class="rounded-lg border border-rose-300 px-2 py-1 text-xs font-semibold text-rose-700 hover:bg-rose-50"
                                >
                                    {{ __('admin.common.delete') }}
                                </button>
                                </div>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="6" class="px-3 py-8 text-center text-sm text-slate-500">{{ $search !== '' ? __('admin.common.no_search_results') : __('admin.content.pages.manager.empty') }}</td>
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
