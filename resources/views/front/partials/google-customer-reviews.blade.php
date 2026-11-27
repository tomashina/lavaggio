<section id="google-review-invitation" class="mx-auto my-6 max-w-3xl px-4">
    <p class="mb-2 text-sm">Želite ocijeniti kupnju? Otvaranjem pozivnice dijelite s Googleom e-adresu, broj narudžbe, državu i očekivani datum dostave te oznake kupljenih proizvoda.</p>
    <button type="button" class="underline" id="open-google-review-invitation">Otvori pozivnicu za Googleovu anketu</button>
    <p id="google-review-status" role="status" class="mt-2 text-sm" hidden>Googleova se pozivnica nije učitala. Možete pokušati ponovo.</p>
</section>
@push('scripts')
    <script>
        (() => {
            const invitation = document.getElementById('google-review-invitation');
            const button = document.getElementById('open-google-review-invitation');
            const status = document.getElementById('google-review-status');
            let loading = false;
            let requestedByCustomer = false;
            function load() {
                if (loading || (!requestedByCustomer && window.cookieMarketingAllowed !== true)) return;
                loading = true;
                status.hidden = true;
                window.renderOptIn = () => window.gapi.load('surveyoptin', () => {
                    if (!requestedByCustomer && window.cookieMarketingAllowed !== true) {
                        loading = false;
                        return;
                    }
                    window.gapi.surveyoptin.render(@js($invitation));
                    invitation.hidden = true;
                });
                if (window.gapi?.load) {
                    window.renderOptIn();
                    return;
                }
                const script = document.createElement('script');
                script.async = true;
                script.src = 'https://apis.google.com/js/platform.js?onload=renderOptIn';
                script.onerror = () => { loading = false; status.hidden = false; script.remove(); };
                document.head.appendChild(script);
            }
            button.addEventListener('click', () => { requestedByCustomer = true; load(); });
            document.addEventListener('shop:consent', load);
            load();
        })();
    </script>
@endpush
