@php
    $watchlineLocations = \App\Support\WatchlineWidgetSettings::forBlock(
        ($block ?? null) instanceof \App\Models\Content\ContentBlock ? $block : new \App\Models\Content\ContentBlock(['type' => 'watchline_locations']),
        ($translation ?? null) instanceof \App\Models\Content\ContentBlockTranslation ? $translation : null,
    );
@endphp
<section class="wl-places" aria-labelledby="watchline-places-title" data-watchline-locations>
    <header class="wl-section-heading">
        <div><p class="wl-eyebrow">{{ $watchlineLocations['section_eyebrow'] }}</p><h2 id="watchline-places-title">{!! nl2br(e((string) $watchlineLocations['title'])) !!}</h2></div>
        <p>{!! nl2br(e((string) $watchlineLocations['subtitle'])) !!}</p>
        @if (($watchlineLocations['cta_label'] ?? '') !== '' && ($watchlineLocations['cta_url'] ?? '') !== '')
            <a class="wl-place-link" href="{{ $watchlineLocations['cta_url'] }}">{{ $watchlineLocations['cta_label'] }} <x-fa-icon :name="$watchlineLocations['link_icon']" /></a>
        @endif
    </header>
    <div class="wl-places-grid">
        @foreach (['service', 'shop'] as $watchlineLocation)
            <article class="wl-place">
                <div class="wl-place-photo">
                    @if ($watchlineLocations[$watchlineLocation.'_image_url'] !== '')
                        <img src="{{ \App\Support\WatchlineWidgetSettings::imageUrl($watchlineLocations[$watchlineLocation.'_image_url']) }}" alt="{{ $watchlineLocations[$watchlineLocation.'_image_alt'] }}" width="750" height="470" loading="lazy" decoding="async">
                    @endif
                    <span>{{ $watchlineLocations[$watchlineLocation.'_image_label'] }}</span>
                </div>
                <div class="wl-place-body">
                    <p class="wl-eyebrow">{{ $watchlineLocations[$watchlineLocation.'_eyebrow'] }}</p><h3>{!! nl2br(e((string) $watchlineLocations[$watchlineLocation.'_title'])) !!}</h3>
                    <p class="wl-place-description">{!! nl2br(e((string) $watchlineLocations[$watchlineLocation.'_description'])) !!}</p>
                    <div class="wl-place-details">
                        <p><x-fa-icon :name="$watchlineLocations['location_icon']" style="light" /><span>{!! nl2br(e((string) $watchlineLocations[$watchlineLocation.'_address'])) !!}</span></p>
                        <p><x-fa-icon :name="$watchlineLocations['hours_icon']" style="light" /><span>{!! nl2br(e((string) $watchlineLocations[$watchlineLocation.'_hours_days'])) !!}<br><strong>{{ $watchlineLocations[$watchlineLocation.'_hours_time'] }}</strong></span></p>
                    </div>
                    @if ($watchlineLocations[$watchlineLocation.'_cta_label'] !== '' && $watchlineLocations[$watchlineLocation.'_url'] !== '')
                        <a class="wl-place-link" href="{{ $watchlineLocations[$watchlineLocation.'_url'] }}">{{ $watchlineLocations[$watchlineLocation.'_cta_label'] }} <span>{{ $watchlineLocations[$watchlineLocation.'_cta_detail'] }}</span><x-fa-icon :name="$watchlineLocations['link_icon']" /></a>
                    @endif
                </div>
            </article>
        @endforeach
    </div>
</section>
