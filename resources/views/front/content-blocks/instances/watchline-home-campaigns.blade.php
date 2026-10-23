@php
    $watchlineCampaign = \App\Support\WatchlineWidgetSettings::forBlock(
        ($block ?? null) instanceof \App\Models\Content\ContentBlock ? $block : new \App\Models\Content\ContentBlock(['type' => 'watchline_campaigns']),
        ($translation ?? null) instanceof \App\Models\Content\ContentBlockTranslation ? $translation : null,
    );
    $watchlineCampaignTitleTag = config('storefront.home') === 'watchline' && request()->routeIs('home')
        && !request()->attributes->get('watchline.campaign_heading_rendered', false) ? 'h1' : 'h2';
    if ($watchlineCampaignTitleTag === 'h1') {
        request()->attributes->set('watchline.campaign_heading_rendered', true);
    }
@endphp
<section class="lm-campaign-section" aria-label="{{ $watchlineCampaign['aria_label'] }}" data-libermedia-campaigns>
    <div class="lm-campaign-grid">
        <article class="lm-campaign lm-campaign--bags wl-cluse-campaign" data-watchline-cluse-campaign>
            <div class="lm-campaign-copy">
                <{{ $watchlineCampaignTitleTag }} class="wl-campaign-title">{{ preg_replace('/\s+/u', ' ', trim((string) $watchlineCampaign['cluse_title'])) }}</{{ $watchlineCampaignTitleTag }}>
                <a class="wl-cluse-offer" href="{{ $watchlineCampaign['cluse_url'] }}">{{ $watchlineCampaign['cluse_offer_prefix'] }} <strong>{{ $watchlineCampaign['cluse_offer_highlight'] }}</strong> {{ $watchlineCampaign['cluse_offer_suffix'] }}</a>
                <ul class="wl-cluse-perks">
                    <li><x-fa-icon :name="$watchlineCampaign['perk_icon']" /><span>{{ $watchlineCampaign['cluse_newsletter_prefix'] }} <strong>{{ $watchlineCampaign['cluse_newsletter_highlight'] }}</strong> {{ $watchlineCampaign['cluse_newsletter_suffix'] }} <a href="{{ $watchlineCampaign['cluse_newsletter_url'] }}">{{ $watchlineCampaign['cluse_newsletter_label'] }}</a></span></li>
                    <li><x-fa-icon :name="$watchlineCampaign['perk_icon']" /><span>{{ $watchlineCampaign['cluse_shipping_prefix'] }} <strong>{{ $watchlineCampaign['cluse_shipping_highlight'] }}</strong></span></li>
                </ul>
                @if ($watchlineCampaign['cluse_cta_label'] !== '' && $watchlineCampaign['cluse_url'] !== '')
                    <a class="lm-button" href="{{ $watchlineCampaign['cluse_url'] }}">{{ $watchlineCampaign['cluse_cta_label'] }} <x-fa-icon :name="$watchlineCampaign['arrow_icon']" /></a>
                @endif
            </div>
            <a class="lm-campaign-bag-images" href="{{ $watchlineCampaign['cluse_url'] }}" aria-label="{{ $watchlineCampaign['cluse_image_link_label'] }}">
                @foreach ([1, 2] as $watchlineCampaignImage)
                    @if ($watchlineCampaign['cluse_image_'.$watchlineCampaignImage.'_url'] !== '')
                        <span class="wl-cluse-watch">
                            <img src="{{ $watchlineCampaign['cluse_image_'.$watchlineCampaignImage.'_url'] }}" alt="{{ $watchlineCampaign['cluse_image_'.$watchlineCampaignImage.'_alt'] }}" width="1080" height="1080" loading="eager" fetchpriority="{{ $watchlineCampaignImage === 1 ? 'high' : 'auto' }}" data-watchline-cluse-image="{{ $watchlineCampaignImage }}">
                        </span>
                    @endif
                @endforeach
            </a>
        </article>
        <article class="lm-campaign lm-campaign--meli wl-mens-campaign">
            <div class="lm-campaign-copy">
                <p class="lm-eyebrow">{{ $watchlineCampaign['mens_eyebrow'] }}</p>
                <h2 class="wl-campaign-title">{!! nl2br(e((string) $watchlineCampaign['mens_title'])) !!}</h2>
                <p>{!! nl2br(e((string) $watchlineCampaign['mens_description'])) !!}</p>
                @if ($watchlineCampaign['mens_cta_label'] !== '' && $watchlineCampaign['mens_url'] !== '')
                    <a class="lm-button" href="{{ $watchlineCampaign['mens_url'] }}">{{ $watchlineCampaign['mens_cta_label'] }} <x-fa-icon :name="$watchlineCampaign['arrow_icon']" /></a>
                @endif
            </div>
            @if ($watchlineCampaign['mens_image_url'] !== '')
                <a class="wl-campaign-visual" href="{{ $watchlineCampaign['mens_url'] }}" aria-label="{{ $watchlineCampaign['mens_image_link_label'] }}"><img class="lm-campaign-product lm-meli-product" src="{{ $watchlineCampaign['mens_image_url'] }}" alt="{{ $watchlineCampaign['mens_image_alt'] }}" width="698" height="931" loading="eager"></a>
            @endif
        </article>
        <article class="lm-campaign lm-campaign--montessori wl-sunglasses-campaign">
            <div class="lm-campaign-copy">
                <p class="lm-eyebrow">{{ $watchlineCampaign['sunglasses_eyebrow'] }}</p>
                <h2 class="wl-campaign-title">{!! nl2br(e((string) $watchlineCampaign['sunglasses_title'])) !!}</h2>
                <p>{!! nl2br(e((string) $watchlineCampaign['sunglasses_description'])) !!}</p>
                @if ($watchlineCampaign['sunglasses_cta_label'] !== '' && $watchlineCampaign['sunglasses_url'] !== '')
                    <a class="lm-button" href="{{ $watchlineCampaign['sunglasses_url'] }}">{{ $watchlineCampaign['sunglasses_cta_label'] }} <x-fa-icon :name="$watchlineCampaign['arrow_icon']" /></a>
                @endif
            </div>
            @if ($watchlineCampaign['sunglasses_image_url'] !== '')
                <a class="wl-campaign-visual" href="{{ $watchlineCampaign['sunglasses_url'] }}" aria-label="{{ $watchlineCampaign['sunglasses_image_link_label'] }}"><img class="lm-campaign-product lm-montessori-product" src="{{ $watchlineCampaign['sunglasses_image_url'] }}" alt="{{ $watchlineCampaign['sunglasses_image_alt'] }}" width="900" height="506" loading="eager"></a>
            @endif
        </article>
    </div>
</section>
