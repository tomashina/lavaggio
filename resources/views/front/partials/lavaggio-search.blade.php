<div
    id="header-search-panel"
    class="header-search-panel-shell lavaggio-search"
    data-header-search-panel
    data-header-search-modal
    data-header-search-breakpoint="1023"
    data-header-search-label="{{ __('Pretraga proizvoda') }}"
    aria-hidden="true"
    inert
>
    <form
        method="GET"
        action="{{ route('shop.index') }}"
        class="header-search-form"
        role="search"
        aria-label="{{ __('Pretraga proizvoda') }}"
        autocomplete="off"
        data-header-search-form
        data-autocomplete-enabled="{{ $searchAutocompleteEnabled ? '1' : '0' }}"
        data-autocomplete-endpoint="{{ $searchAutocompleteEnabled ? route('search.autocomplete') : '' }}"
        data-autocomplete-results-label="{{ __('ui.shop.search_autocomplete.results', ['count' => '__COUNT__']) }}"
        data-autocomplete-empty-label="{{ __('ui.shop.search_autocomplete.no_results', ['query' => '__QUERY__']) }}"
        data-autocomplete-loading-label="{{ __('ui.shop.search_autocomplete.searching') }}"
        data-autocomplete-view-all-label="{{ __('ui.shop.search_autocomplete.view_all') }}"
        data-autocomplete-products-label="{{ __('ui.shop.search_autocomplete.groups.products') }}"
        data-autocomplete-categories-label="{{ __('ui.shop.search_autocomplete.groups.categories') }}"
        data-autocomplete-manufacturers-label="{{ __('ui.shop.search_autocomplete.groups.manufacturers') }}"
        data-autocomplete-blog-label="{{ __('ui.shop.search_autocomplete.groups.blog') }}"
        data-autocomplete-b2b-label="{{ __('ui.product.b2b_contract_price') }}"
        data-autocomplete-prompt-label="{{ __('Upišite najmanje 2 znaka za pretragu proizvoda.') }}"
        data-autocomplete-error-label="{{ __('Prijedlozi trenutačno nisu dostupni. Prikažite sve rezultate pretrage.') }}"
    >
        <div class="lavaggio-search-heading">
            <div>
                <span class="lavaggio-search-eyebrow">{{ $storeBrandName }}</span>
                <h2>{{ __('Što tražite?') }}</h2>
            </div>
            <button type="button" class="header-search-panel-close" aria-label="{{ __('Zatvori pretragu') }}" data-header-search-close>
                <x-fa-icon inline name="xmark" style="regular" />
            </button>
        </div>
        <label class="sr-only" for="lavaggio-search-input">{{ __('Pretraga proizvoda') }}</label>
        <input
            id="lavaggio-search-input"
            type="search"
            name="q"
            value="{{ is_scalar(request()->query('q', '')) ? (string) request()->query('q', '') : '' }}"
            placeholder="{{ __('Pretražite proizvode, brendove i kategorije…') }}"
            class="header-search-input"
            aria-controls="lavaggio-search-results"
            maxlength="100"
            autocomplete="off"
            autocapitalize="none"
            autocorrect="off"
            spellcheck="false"
            enterkeyhint="search"
            data-header-search-input
        >
        <button type="submit" class="header-search-submit" aria-label="{{ __('ui.shop.filters.search') }}">
            <x-fa-icon inline name="magnifying-glass" style="regular" />
        </button>
        <div class="header-search-suggestions" data-header-search-suggestions hidden>
            <div class="header-search-suggestions-meta" role="status" aria-live="polite" aria-atomic="true" data-header-search-suggestions-meta></div>
            <div class="header-search-suggestions-loading" role="status" aria-live="polite" data-header-search-loading hidden></div>
            <div class="header-search-suggestions-empty" role="status" aria-live="polite" data-header-search-empty hidden></div>
            <div id="lavaggio-search-results" class="header-search-suggestions-list" data-header-search-suggestions-list></div>
            <div class="header-search-suggestions-footer" data-header-search-footer hidden>
                <a href="{{ route('shop.index') }}" class="header-search-suggestions-link" data-header-search-view-all></a>
            </div>
        </div>
    </form>
</div>
