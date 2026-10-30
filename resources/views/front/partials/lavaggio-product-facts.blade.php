@php
    $lavaggioPresenter = app(\App\Support\LavaggioProductPresenter::class);
    $contentLabel = $lavaggioPresenter->contentLabel($product, (float) ($pricePresentation['current_gross'] ?? 0));
    $promotionEndsAt = $pricePresentation['promotion_ends_at'] ?? null;
    $listingPrice = $product->listing_price;
    $listedAt = $product->listed_at;
    $remainingSeconds = $promotionEndsAt ? max(0, (int) ceil(now()->diffInSeconds(\Carbon\Carbon::parse($promotionEndsAt), false))) : 0;
    $countdownParts = [
        'days' => ['value' => intdiv($remainingSeconds, 86400), 'label' => 'DANA'],
        'hours' => ['value' => intdiv($remainingSeconds % 86400, 3600), 'label' => 'SATA'],
        'minutes' => ['value' => intdiv($remainingSeconds % 3600, 60), 'label' => 'MINUTA'],
        'seconds' => ['value' => $remainingSeconds % 60, 'label' => 'SEKUNDI'],
    ];
@endphp

<div class="lavaggio-product-facts" data-lavaggio-product-facts>
    @if ($contentLabel)
        <p class="lavaggio-product-content" data-product-content-quantity>{{ $contentLabel }}</p>
    @endif
    @if ($listingPrice !== null && $listedAt)
        <p class="lavaggio-product-listing-price">Cijena na <time datetime="{{ $listedAt->toDateString() }}">{{ $listedAt->format('d.m.Y.') }}</time>: {{ $lavaggioPresenter->money((float) $listingPrice) }}</p>
    @endif
    @if ($promotionEndsAt && $oldPrice)
        <div class="lavaggio-sale-countdown" data-lavaggio-countdown data-ends-at="{{ $promotionEndsAt }}" data-refresh-on-expiry>
            <span class="lavaggio-countdown-title">Akcija traje još</span>
            <div class="lavaggio-countdown-clock" role="timer" aria-label="Preostalo vrijeme akcije" aria-live="off">
                @foreach ($countdownParts as $part => $countdownPart)
                    <span class="lavaggio-countdown-unit">
                        <strong data-countdown-part="{{ $part }}">{{ sprintf('%02d', $countdownPart['value']) }}</strong>
                        <span class="lavaggio-countdown-unit-label">{{ $countdownPart['label'] }}</span>
                    </span>
                @endforeach
            </div>
            <time datetime="{{ $promotionEndsAt }}">Do {{ \Carbon\Carbon::parse($promotionEndsAt)->timezone('Europe/Zagreb')->format('d.m.Y. H:i') }}</time>
        </div>
    @endif
    <p class="lavaggio-product-vat">Jedinična cijena s uključenim PDV-om.</p>
</div>
