@php
    $isLavaggioFooter = config('storefront.home') === 'lavaggio';
    $herreraFooterSocials = \App\Support\StorefrontSocialLinks::resolve(
        $storeSettings['branding']['social'] ?? [], $topBar['socials'] ?? [], $isLavaggioFooter
    );
@endphp
@if ($herreraFooterSocials->isNotEmpty())
    <div class="{{ $socialLinksClass ?? 'herrera-footer-socials' }}" aria-label="{{ __('Društvene mreže') }}">
        @foreach ($herreraFooterSocials as $social)
            @php
                $socialName = trim((string) ($social['label'] ?? '')) ?: match ($social['network']) { 'linkedin' => 'LinkedIn', 'twitter' => 'X / Twitter', 'tiktok' => 'TikTok', 'youtube' => 'YouTube', default => ucfirst($social['network']) };
                $socialIcon = match ($social['network']) { 'linkedin' => 'linkedin-in', 'twitter' => 'x-twitter', 'facebook' => 'facebook-f', default => $social['network'] };
                if ($isLavaggioFooter) {
                    $socialIcon = match ($social['network']) { 'linkedin' => 'linkedin', 'facebook' => 'facebook', default => $socialIcon };
                }
            @endphp
            <a href="{{ $social['url'] }}" aria-label="{{ $socialName }}" target="_blank" rel="noopener noreferrer">
                <x-fa-icon :name="$socialIcon" style="brands" :inline="$isLavaggioFooter" />
                @unless ($isLavaggioFooter)<span>{{ $socialName }}</span>@endunless
            </a>
        @endforeach
    </div>
@endif
