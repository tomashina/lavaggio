@if (config('gift_vouchers.enabled', true))
    @php
        $giftVoucherContext = $giftVoucherContext ?? 'cart';
        $giftVoucherCode = (string) ($giftVoucherCode ?? '');
        $giftVoucherId = 'gift-voucher-'.$giftVoucherContext;
        $giftVoucherDetached = $giftVoucherContext === 'checkout';
        $giftVoucherApplied = (float) ($giftVoucherQuote['amount'] ?? $checkoutTotals['gift_voucher_total'] ?? 0) > 0;
    @endphp
    <details class="gift-voucher-redemption" data-gift-voucher-redemption data-gift-apply-form="{{ $giftVoucherId }}-apply" data-gift-remove-form="{{ $giftVoucherId }}-remove" @if($giftVoucherCode !== '' || $errors->has('gift_voucher_code')) open @endif>
        <summary><x-fa-icon name="gift" /><span>Imate poklon bon?</span><x-fa-icon name="chevron-down" /></summary>
        <div class="gift-voucher-redemption-content">
            <p>Unesite kod sa svog digitalnog poklon bona.</p>
            @if ($giftVoucherDetached)<div>@else<form id="{{ $giftVoucherId }}-apply" method="POST" action="{{ route('cart.gift-voucher.apply') }}" data-gift-voucher-apply>@csrf<input type="hidden" name="return_to" value="{{ $giftVoucherContext }}">@endif
                <label for="{{ $giftVoucherId }}-code">Kod poklon bona <span class="store-form-required" aria-hidden="true">*</span></label>
                <div class="gift-voucher-code-row">
                    <input id="{{ $giftVoucherId }}-code" form="{{ $giftVoucherId }}-apply" type="text" name="gift_voucher_code" value="{{ old('gift_voucher_code', $giftVoucherCode) }}" maxlength="80" autocomplete="off" autocapitalize="characters" spellcheck="false" placeholder="LIBER-…" required @error('gift_voucher_code') aria-invalid="true" @enderror>
                    <button type="submit" form="{{ $giftVoucherId }}-apply" class="gift-voucher-apply-button">Primijeni</button>
                </div>
            @if ($giftVoucherDetached)</div>@else</form>@endif
            <p class="gift-voucher-redemption-status {{ $errors->has('gift_voucher_code') || ($giftVoucherCode !== '' && !$giftVoucherApplied) ? 'is-error' : '' }}" data-gift-voucher-status role="status" aria-live="polite" @if($giftVoucherCode === '' && !$errors->has('gift_voucher_code')) hidden @endif>{{ $errors->first('gift_voucher_code') ?: ($giftVoucherCode !== '' ? ($giftVoucherApplied ? 'Poklon bon je primijenjen.' : 'Poklon bon trenutačno nije dostupan. Provjerite kod ili ga uklonite za nastavak.') : '') }}</p>
            <div data-gift-voucher-remove-control @if($giftVoucherCode === '') hidden @endif>
                @if (!$giftVoucherDetached)<form id="{{ $giftVoucherId }}-remove" method="POST" action="{{ route('cart.gift-voucher.remove') }}" data-gift-voucher-remove>@csrf @method('DELETE')<input type="hidden" name="return_to" value="{{ $giftVoucherContext }}">@endif
                <button type="submit" form="{{ $giftVoucherId }}-remove" class="gift-voucher-remove-button">Ukloni poklon bon</button>
                @if (!$giftVoucherDetached)</form>@endif
            </div>
        </div>
    </details>
@endif
