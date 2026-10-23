<section class="lm-footer-payments" aria-label="Sigurno kartično plaćanje">
    <p>Sigurno plaćanje</p>
    <div class="lm-footer-payment-logos">
        <a class="lm-footer-payment-logo lm-footer-payment-logo--gateway" href="https://www.wspay.info/" target="_blank" rel="noopener noreferrer" aria-label="Monri WSPay – Web Secure Payment Gateway">
            <img src="{{ asset('assets/payments/mono/monri.svg') }}" alt="Monri WSPay" width="60" height="20" loading="lazy">
        </a>
        @foreach (['visa' => 'Visa', 'mastercard' => 'Mastercard', 'maestro' => 'Maestro', 'diners' => 'Diners Club'] as $asset => $label)
            <span class="lm-footer-payment-logo"><img src="{{ asset('assets/payments/mono/'.$asset.'.svg') }}" alt="{{ $label }}" width="52" height="22" loading="lazy"></span>
        @endforeach
    </div>
</section>
