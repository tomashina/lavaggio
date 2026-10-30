@php
    $legalPanels = [
        ['key' => 'information', 'label' => 'Informacije o proizvodu', 'content' => (string) ($translation?->description ?: $translation?->excerpt), 'html' => true],
        ['key' => 'ingredients', 'label' => 'Sastojci', 'content' => $product->ingredients, 'html' => false],
        ['key' => 'hazards', 'label' => 'Napomene o opasnosti', 'content' => $product->hazard_notes, 'html' => false],
        ['key' => 'manufacturer', 'label' => 'Proizvođač / uvoznik', 'content' => $product->manufacturer_importer, 'html' => false],
        ['key' => 'origin', 'label' => 'Zemlja podrijetla', 'content' => $product->country_of_origin, 'html' => false],
    ];
@endphp
<section id="product-description" class="lavaggio-product-legal" data-product-detail-section aria-label="Informacije o proizvodu">
    @foreach ($legalPanels as $panel)
        <details class="lavaggio-legal-panel" data-lavaggio-legal-panel="{{ $panel['key'] }}" @if($loop->first) open @endif>
            <summary><span>{{ $panel['label'] }}</span><span class="lavaggio-legal-toggle" aria-hidden="true">+</span></summary>
            <div class="lavaggio-legal-content">
                @if (trim((string) $panel['content']) !== '')
                    @if ($panel['html'])
                        <div class="product-detail-description-content">{!! $panel['content'] !!}</div>
                    @else
                        <p>{{ $panel['content'] }}</p>
                    @endif
                @else
                    <p class="lavaggio-legal-missing">Informacija još nije dostupna.</p>
                @endif
            </div>
        </details>
    @endforeach
</section>
