<dialog id="legal-warranty-modal" class="legal-warranty-modal" aria-labelledby="legal-warranty-modal-title" data-legal-warranty-modal>
    <div class="legal-warranty-modal-header">
        <h2 id="legal-warranty-modal-title">{{ __('herrera.warranty.modal_title') }}</h2>
        <button type="button" data-legal-warranty-close aria-label="{{ __('herrera.warranty.close') }}"><x-fa-icon name="xmark" /></button>
    </div>
    <div class="legal-warranty-modal-body">
        @if (config('storefront.home') === 'lavaggio')
            <div class="legal-warranty-explanation">
                <p>Prodavatelj odgovara za materijalne nedostatke koji se pokažu u roku od najmanje dvije godine od predaje robe. To su vaša zakonska prava, neovisna o dodatnom komercijalnom jamstvu.</p>
                <p>O nedostatku nas obavijestite u roku od dva mjeseca od otkrivanja. Imate pravo na besplatan popravak ili zamjenu, a pod zakonskim uvjetima na sniženje cijene ili raskid ugovora. Za ugovore sklopljene od 31. srpnja 2026. popravak jednom produljuje zakonske rokove odgovornosti za 12 mjeseci.</p>
                <p>Za reklamaciju nam se javite putem <a href="{{ route('contact.create') }}">korisničke podrške</a>.</p>
            </div>
        @endif
        <a href="{{ $warranty['asset_url'] }}" target="_blank" rel="noopener noreferrer" title="{{ __('herrera.warranty.view_notice') }}">
            <img src="{{ $warranty['asset_url'] }}" alt="{{ __('herrera.warranty.modal_title') }}" width="595" height="842" loading="lazy">
        </a>
    </div>
    <div class="legal-warranty-modal-footer">
        <a href="{{ $warranty['eu_url'] }}" target="_blank" rel="noopener noreferrer">{{ __('herrera.warranty.eu_information') }} ↗</a>
        <button type="button" data-legal-warranty-close>{{ __('herrera.warranty.close') }}</button>
    </div>
</dialog>
