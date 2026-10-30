<header class="site-main-header lavaggio-header">
    @include('front.partials.customer-impersonation')

    @if ($topBar['is_enabled'] || $announcementEnabled || \App\Support\StorefrontSocialLinks::resolve($storeSettings['branding']['social'] ?? [], $topBar['socials'] ?? [], true)->isNotEmpty())
        <div class="lavaggio-header-topbar{{ $announcementEnabled ? ' has-announcement' : '' }}">
            <div class="lavaggio-header-topbar-inner">
                <nav class="lavaggio-header-contacts" aria-label="Kontakt podaci">
                    @if ($topBar['is_enabled'])
                        @foreach ($topBar['links'] as $link)
                            @if ($link['is_active'])
                                <a href="{{ $link['url'] }}" @if ($link['open_in_new_tab']) target="_blank" rel="noopener noreferrer" @endif>{{ $link['label'] }}</a>
                            @endif
                        @endforeach
                    @endif
                </nav>
                @if ($announcementEnabled)
                    <div class="lavaggio-header-announcement">
                        @if ($announcementUrl !== '')
                            <a href="{{ $announcementUrl }}" @if ($announcementNewTab) target="_blank" rel="noopener noreferrer" @endif>{{ $announcementText }}</a>
                        @else
                            <span>{{ $announcementText }}</span>
                        @endif
                    </div>
                @endif
                @include('front.partials.herrera-footer-socials', ['socialLinksClass' => 'lavaggio-header-socials'])
            </div>
        </div>
    @endif

    <div class="lavaggio-header-card">
        <a href="{{ route('home') }}" class="lavaggio-header-brand" aria-label="{{ $storeBrandName }}">
            @if ($storeBrandLogoUrl !== '')
                <img src="{{ $storeBrandLogoUrl }}" alt="{{ $storeBrandName }}" width="{{ $storeBrandLogoWidth }}" height="{{ $storeBrandLogoHeight }}" data-store-brand-logo>
            @else
                <span>{{ $storeBrandName }}</span>
            @endif
        </a>

        <nav class="lavaggio-header-navigation watchline-desktop-categories" aria-label="Glavna navigacija">
            @foreach ($mainNavigation as $item)
                @if (($item['type'] ?? '') === 'catalog' || ! empty($item['children']))
                    @include('front.partials.lavaggio-catalog-nav', ['catalogItem' => $item])
                @else
                    <a href="{{ $item['url'] }}" class="lavaggio-header-link" @if (! empty($item['open_in_new_tab'])) target="_blank" rel="noopener noreferrer" @endif @if (request()->url() === $item['url']) aria-current="page" @endif>{{ $item['label'] }}</a>
                @endif
            @endforeach
        </nav>

        <div class="lavaggio-header-actions">
            <button type="button" class="lavaggio-header-action" aria-label="{{ __('ui.front.desktop.search') }}" title="{{ __('ui.front.desktop.search') }}" aria-controls="header-search-panel" aria-expanded="false" data-header-search-toggle>
                <x-fa-icon inline name="magnifying-glass" style="regular" />
            </button>
            <a href="{{ auth()->check() ? route('account.dashboard') : route('front.auth.login') }}" class="lavaggio-header-action" aria-label="{{ auth()->check() ? __('ui.front.desktop.account') : __('Prijava') }}">
                <x-fa-icon inline name="user" style="regular" />
            </a>
            <div class="lavaggio-header-cart header-cart-hover" data-header-cart>
                <a href="{{ route('cart.index') }}" class="lavaggio-header-action header-cart-trigger" aria-label="{{ __('ui.front.desktop.cart') }}: {{ (int) $cartSummary['item_qty'] }}" aria-controls="header-cart-popover" aria-expanded="false" data-header-cart-trigger>
                    <x-fa-icon inline name="bag-shopping" style="regular" />
                    <span class="lavaggio-header-count header-count-badge header-cart-count" data-cart-count>{{ (int) $cartSummary['item_qty'] }}</span>
                </a>
                @include('front.desktop.partials.header-cart-popover', ['cartLines' => $headerCartLines, 'cartSummary' => $cartSummary])
            </div>
            <button type="button" class="lavaggio-header-menu-toggle" aria-label="{{ __('ui.front.desktop.open_navigation') }}" aria-controls="mobile-navigation" aria-expanded="false" data-mobile-menu-open>
                <x-fa-icon inline name="bars" style="regular" />
            </button>
        </div>
    </div>
    <span hidden data-header-rendered></span>
</header>
