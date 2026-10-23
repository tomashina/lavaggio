@php
    $watchlineAssurance = \App\Support\WatchlineWidgetSettings::forBlock(
        ($block ?? null) instanceof \App\Models\Content\ContentBlock ? $block : new \App\Models\Content\ContentBlock(['type' => 'watchline_assurance']),
        ($translation ?? null) instanceof \App\Models\Content\ContentBlockTranslation ? $translation : null,
    );
    $watchlineAssuranceDescription = (string) $watchlineAssurance['subtitle'];
    $watchlineAssuranceHighlight = (string) $watchlineAssurance['subtitle_highlight'];
    $watchlineAssuranceDescriptionParts = $watchlineAssuranceHighlight !== ''
        ? explode($watchlineAssuranceHighlight, $watchlineAssuranceDescription, 2)
        : [$watchlineAssuranceDescription];
@endphp
<section class="lm-assurance" aria-labelledby="lm-assurance-title" data-libermedia-assurance>
    <div class="lm-assurance-heading">
        @if ($watchlineAssurance['seal_image_url'] !== '')
            <span class="lm-assurance-seal"><img src="{{ $watchlineAssurance['seal_image_url'] }}" alt="{{ $watchlineAssurance['seal_image_alt'] }}" width="48" height="48" @if ($watchlineAssurance['seal_image_alt'] === '') aria-hidden="true" @endif></span>
        @endif
        <p class="lm-eyebrow">{{ $watchlineAssurance['eyebrow'] }}</p>
        <h2 id="lm-assurance-title">{!! nl2br(e((string) $watchlineAssurance['title'])) !!}</h2>
        <p>{!! nl2br(e($watchlineAssuranceDescriptionParts[0])) !!}@if (count($watchlineAssuranceDescriptionParts) === 2)<strong>{!! nl2br(e($watchlineAssuranceHighlight)) !!}</strong>{!! nl2br(e($watchlineAssuranceDescriptionParts[1])) !!}@endif</p>
        @if (($watchlineAssurance['cta_label'] ?? '') !== '' && ($watchlineAssurance['cta_url'] ?? '') !== '')
            <a href="{{ $watchlineAssurance['cta_url'] }}">{{ $watchlineAssurance['cta_label'] }} <x-fa-icon :name="$watchlineAssurance['link_icon']" /></a>
        @endif
    </div>
    <div class="lm-assurance-points">
        @foreach ([1, 2, 3] as $watchlineAssurancePoint)
            <div>
                <x-fa-icon :name="$watchlineAssurance['point_'.$watchlineAssurancePoint.'_icon']" style="regular" />
                <h3>{!! nl2br(e((string) $watchlineAssurance['point_'.$watchlineAssurancePoint.'_title'])) !!}</h3>
                <p>{!! nl2br(e((string) $watchlineAssurance['point_'.$watchlineAssurancePoint.'_description'])) !!}</p>
                @if ($watchlineAssurance['point_'.$watchlineAssurancePoint.'_cta_label'] !== '' && $watchlineAssurance['point_'.$watchlineAssurancePoint.'_url'] !== '')
                    <a href="{{ $watchlineAssurance['point_'.$watchlineAssurancePoint.'_url'] }}">{{ $watchlineAssurance['point_'.$watchlineAssurancePoint.'_cta_label'] }} <x-fa-icon :name="$watchlineAssurance['link_icon']" /></a>
                @endif
            </div>
        @endforeach
    </div>
</section>
