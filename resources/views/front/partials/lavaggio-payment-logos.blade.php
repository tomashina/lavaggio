<section class="lavaggio-footer-payments" aria-labelledby="lavaggio-footer-payments-title">
    <h3 id="lavaggio-footer-payments-title">Načini plaćanja</h3>
    <div class="lavaggio-footer-payment-logos">
        <a class="lavaggio-footer-payment-logo lavaggio-footer-payment-logo--gateway" href="https://www.corvuspay.com/" target="_blank" rel="noopener noreferrer" aria-label="CorvusPay">
            <img src="{{ asset('assets/payments/corvus-logo.svg') }}" alt="CorvusPay" width="116" height="24" loading="lazy">
        </a>
        @foreach (['visa-brand' => 'Visa', 'mastercard-brand' => 'Mastercard', 'maestro-brand' => 'Maestro', 'diners-brand' => 'Diners Club', 'applepay' => 'Apple Pay', 'googlepay' => 'Google Pay'] as $paymentAsset => $paymentLabel)
            <span class="lavaggio-footer-payment-logo">
                <img src="{{ asset('assets/payments/'.$paymentAsset.'.svg') }}" alt="{{ $paymentLabel }}" width="{{ match ($paymentAsset) { 'visa-brand' => 74, 'mastercard-brand', 'maestro-brand' => 31, 'diners-brand' => 93, 'applepay' => 38, default => 45 } }}" height="24" loading="lazy">
            </span>
        @endforeach
    </div>
</section>
