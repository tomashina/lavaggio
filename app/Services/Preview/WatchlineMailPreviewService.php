<?php

namespace App\Services\Preview;

use App\Models\Content\Support\ContactMessage;
use App\Models\Sales\ContractWithdrawal;
use App\Models\Sales\GiftVoucher;
use App\Models\Sales\Offer\CustomerOffer;
use App\Models\Sales\Order\Order;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Support\Carbon;

/**
 * Renders real mail views with fixed, unsaved examples. It never builds an
 * order payment, creates an authentication token, or invokes a mail transport.
 */
class WatchlineMailPreviewService
{
    public function available(): bool
    {
        return app()->environment(['local', 'testing']) && config('storefront.home') === 'watchline';
    }

    /** @return array<string, array{label: string, group: string, description: string, inactive?: bool}> */
    public function samples(): array
    {
        return [
            'order-customer' => ['label' => 'Potvrda narudžbe kupcu', 'group' => 'Narudžbe', 'description' => 'Kartično plaćanje, dvije stavke i popust.'],
            'order-admin' => ['label' => 'Nova narudžba trgovini', 'group' => 'Narudžbe', 'description' => 'Administratorska obavijest o novoj narudžbi.'],
            'order-bank' => ['label' => 'Narudžba s uplatom na račun', 'group' => 'Narudžbe', 'description' => 'Ogledni PDF417 barkod s oznakom NIJE ZA UPLATU; bankovni podaci nisu važeći.'],
            'order-locker' => ['label' => 'Narudžba s BOX NOW dostavom', 'group' => 'Narudžbe', 'description' => 'Sažetak odabranog paketomata.'],
            'gift-purchase' => ['label' => 'Kupnja poklon bona · čeka uplatu', 'group' => 'Poklon bonovi', 'description' => 'Potvrda prije uplate s oglednim PDF417 barkodom koji nije za plaćanje.'],
            'gift-paid' => ['label' => 'Kupnja poklon bona · plaćeno', 'group' => 'Poklon bonovi', 'description' => 'Potvrda kupnje nakon uplate.'],
            'gift-delivered' => ['label' => 'Poklon bon primatelju', 'group' => 'Poklon bonovi', 'description' => 'Osobna poruka i izmišljeni kod bona koji nije moguće iskoristiti.'],
            'newsletter' => ['label' => 'Dobrodošlica i newsletter popust', 'group' => 'Obavijesti', 'description' => 'Ogledni kupon dobrodošlice od 10 %.'],
            'restock' => ['label' => 'Artikl ponovno dostupan', 'group' => 'Obavijesti', 'description' => 'Jednokratna obavijest o dostupnosti artikla.'],
            'review-request' => ['label' => 'Poziv na recenziju', 'group' => 'Automatske poruke', 'description' => 'Molba za ocjenu kupljenih artikala.'],
            'abandoned-cart' => ['label' => 'Podsjetnik na košaricu', 'group' => 'Automatske poruke', 'description' => 'Ogledna košarica s jednokratnim popustom.'],
            'withdrawal-receipt' => ['label' => 'Raskid ugovora · potvrda kupcu', 'group' => 'Povrati i kontakt', 'description' => 'Potvrda zaprimljenog zahtjeva s uputama za povrat.'],
            'withdrawal-admin' => ['label' => 'Raskid ugovora · obavijest trgovini', 'group' => 'Povrati i kontakt', 'description' => 'Zahtjev za raskid s oglednim podacima kupca.'],
            'contact' => ['label' => 'Kontaktni upit', 'group' => 'Povrati i kontakt', 'description' => 'Ogledni upit poslan iz kontaktne forme.'],
            'legacy-return' => ['label' => 'Stariji zahtjev za povrat', 'group' => 'Povrati i kontakt', 'description' => 'Pregled zadržane obavijesti starijeg obrasca.'],
            'password-reset' => ['label' => 'Obnova lozinke', 'group' => 'Korisnički račun', 'description' => 'Prava obavijest za obnovu lozinke bez aktivne poveznice ili tokena.'],
            'email-verification' => ['label' => 'Potvrda e-mail adrese', 'group' => 'Korisnički račun', 'description' => 'Prava obavijest za potvrdu adrese bez potpisane poveznice.'],
            'customer-offer' => ['label' => 'Ponuda kupcu · neaktivni modul', 'group' => 'Neaktivni modul', 'description' => 'Predložak ponude postoji, a modul ponuda u Watchline trgovini nije aktivan.', 'inactive' => true],
        ];
    }

    public function render(string $key): string
    {
        abort_unless($this->available() && isset($this->samples()[$key]), 404);

        $previousLocale = app()->getLocale();
        app()->setLocale('hr');

        try {
            return $this->renderSample($key);
        } finally {
            app()->setLocale($previousLocale);
        }
    }

    private function renderSample(string $key): string
    {
        if (str_starts_with($key, 'order-')) {
            return view('emails.orders.notification', $this->orderData($key))->render();
        }

        if (in_array($key, ['gift-purchase', 'gift-paid'], true)) {
            $order = new Order([
                'order_number' => 'OGLEDNI-BON-001', 'grand_total' => 50,
                'paid_at' => $key === 'gift-paid' ? Carbon::parse('2026-10-25 10:00:00') : null,
            ]);

            return view('emails.gift-vouchers.purchase', [
                'order' => $order, 'voucher' => $this->voucher(), 'purchaseUrl' => $this->exampleUrl(),
                'bankTransfer' => $key === 'gift-paid' ? null : $this->bankTransfer(50),
            ])->render();
        }

        if ($key === 'gift-delivered') {
            return view('emails.gift-vouchers.delivered', ['voucher' => $this->voucher()])->render();
        }

        if ($key === 'newsletter') {
            return view('emails.newsletter.coupon', [
                'coupon_code' => 'OGLEDNI-KOD-NE-VAZI', 'store_name' => 'Watchline', 'shop_url' => $this->exampleUrl(),
                'logo_url' => asset('assets/brand/watchline-logo.svg'), 'watchline' => true, 'discount_percent' => 10,
            ])->render();
        }

        if ($key === 'restock') {
            return view('emails.stock-notifications.available', [
                'targetName' => 'Ogledni ženski ručni sat', 'targetUrl' => $this->exampleUrl(),
                'unsubscribeUrl' => $this->exampleUrl(), 'isBundle' => false,
            ])->render();
        }

        if (in_array($key, ['review-request', 'abandoned-cart'], true)) {
            return view('emails.watchline.automation', $this->automationData($key))->render();
        }

        if (in_array($key, ['withdrawal-admin', 'withdrawal-receipt'], true)) {
            return view('emails.withdrawals.'.($key === 'withdrawal-admin' ? 'admin' : 'receipt'), [
                'withdrawal' => $this->withdrawal(), 'storeName' => 'Watchline',
                'returnAddress' => "Watchline · ogledna adresa\nUlica primjera 12, 10000 Zagreb",
                'instructions' => 'Ogledne upute: zaštitite artikl prije slanja. Ovaj primjer nije stvarni zahtjev za povrat.',
                'adminUrl' => $this->exampleUrl(),
            ])->render();
        }

        if (in_array($key, ['contact', 'legacy-return'], true)) {
            return view('emails.watchline.contact', [
                'contact' => $this->contact($key), 'isReturn' => $key === 'legacy-return',
            ])->render();
        }

        if ($key === 'password-reset') {
            $notification = new class('preview-only-not-valid') extends ResetPassword
            {
                protected function resetUrl($notifiable): string
                {
                    return url('/preview/watchline-mail#ogled');
                }
            };

            return $this->renderNotification($notification);
        }

        if ($key === 'email-verification') {
            $notification = new class extends VerifyEmail
            {
                protected function verificationUrl($notifiable): string
                {
                    return url('/preview/watchline-mail#ogled');
                }
            };

            return $this->renderNotification($notification);
        }

        $offer = new CustomerOffer([
            'locale' => 'hr', 'customer_snapshot' => ['company' => 'Ogledni kupac'],
            'title' => 'Ogledna ponuda', 'number' => 'OGLEDNA-PONUDA-001',
            'valid_until' => '2026-11-25', 'intro' => 'Ovo je pregled neaktivnog modula. Ponuda nije spremljena niti poslana.',
        ]);

        return view('emails.customer-offers.offer', ['offer' => $offer])->render();
    }

    private function renderNotification(ResetPassword|VerifyEmail $notification): string
    {
        $notifiable = new class
        {
            public string $name = 'Ogledni kupac';

            public string $email = 'kupac@example.invalid';

            public function getEmailForPasswordReset(): string
            {
                return $this->email;
            }

            public function getEmailForVerification(): string
            {
                return $this->email;
            }
        };

        $mail = $notification->toMail($notifiable);

        // A custom production callback may build its own URL. Replace its
        // action only in this unsent message, without changing global callbacks.
        $mail->actionUrl = $this->exampleUrl();
        foreach (['action_url', 'actionUrl', 'url', 'reset_url', 'resetUrl', 'verification_url', 'verificationUrl'] as $field) {
            if (array_key_exists($field, $mail->viewData)) {
                $mail->viewData[$field] = $this->exampleUrl();
            }
        }

        return (string) $mail->render();
    }

    /** @return array<string, mixed> */
    private function orderData(string $key): array
    {
        $totals = ['subtotal' => 208.80, 'discount' => 20.88, 'shipping' => 4.50, 'payment_fee' => 0, 'tax' => 0, 'grand_total' => 192.42];

        return [
            'store_name' => 'Watchline', 'logo_url' => asset('assets/brand/watchline-logo.svg'),
            'legal_warranty' => ['enabled' => true, 'email_enabled' => true, 'asset_url' => $this->exampleUrl(), 'eu_url' => $this->exampleUrl()],
            'order_number' => 'OGLEDNA-001', 'variant' => $key === 'order-admin' ? 'admin' : 'customer',
            'customer_name' => 'Ogledni kupac', 'customer_contact' => '', 'customer_email' => 'kupac@example.invalid',
            'customer_phone' => '+385 00 000 000', 'placed_at' => '25.10.2026. 10:00',
            'payment_method' => $key === 'order-bank' ? 'Uplata na račun' : 'Kartično plaćanje',
            'shipping_method' => $key === 'order-locker' ? 'BOX NOW paketomat' : 'GLS dostava',
            'billing_address' => 'Ulica primjera 12, 10000 Zagreb, HR',
            'shipping_address' => 'Ulica primjera 12, 10000 Zagreb, HR',
            'customer_note' => 'Ogledna napomena: molim poklon pakiranje.', 'currency' => 'EUR',
            'items' => [
                ['name' => 'Ogledni ženski ručni sat', 'sku' => 'OGLED-SAT-001', 'option_label' => 'Boja: zlatna', 'quantity' => 1, 'unit_price' => '129,00 €', 'line_total' => '129,00 €', 'product_url' => $this->exampleUrl(), 'image_url' => asset('assets/brand/watchline-favicon.svg')],
                ['name' => 'Ogledna ogrlica', 'sku' => 'OGLED-NAKIT-002', 'option_label' => '', 'quantity' => 2, 'unit_price' => '39,90 €', 'line_total' => '79,80 €', 'product_url' => $this->exampleUrl(), 'image_url' => ''],
            ],
            'totals' => array_map(fn ($value) => number_format($value, 2, ',', '.').' €', $totals),
            'totals_raw' => $totals,
            'bank_transfer' => $key === 'order-bank' ? $this->bankTransfer(192.42) : null,
            'box_now' => $key === 'order-locker' ? ['locker_id' => 'OGLED', 'locker_name' => 'Ogledni paketomat', 'address_line_1' => 'Ulica primjera 12', 'postal_code' => '10000', 'city' => 'Zagreb'] : null,
        ];
    }

    private function voucher(): GiftVoucher
    {
        $voucher = new GiftVoucher([
            'recipient_name' => 'Ogledna primateljica', 'recipient_email' => 'primatelj@example.invalid',
            'sender_name' => 'Ogledni darivatelj', 'initial_amount' => 50, 'balance' => 50,
            'message' => "Za tvoj poseban dan!\nOvo je ogledna poruka poklon bona.", 'expires_at' => '2027-10-25',
        ]);
        $voucher->storeCode('OGLEDNI-BON-NE-VAZI');

        return $voucher;
    }

    private function withdrawal(): ContractWithdrawal
    {
        return new ContractWithdrawal([
            'reference' => 'OGLEDNI-POVRAT-001', 'submitted_at' => '2026-10-25 10:00:00',
            'full_name' => 'Ogledni kupac', 'email' => 'kupac@example.invalid', 'phone' => '+385 00 000 000',
            'address_line' => 'Ulica primjera 12', 'postal_code' => '10000', 'city' => 'Zagreb', 'country_code' => 'HR',
            'order_number' => 'OGLEDNA-001', 'contract_date' => '2026-10-20', 'received_date' => '2026-10-22',
            'items' => 'Ogledni ženski ručni sat · 1 komad',
            'note' => 'Ogledna napomena uz zahtjev za povrat.',
            'declaration' => 'Ovim putem izjavljujem da jednostrano raskidam ugovor za oglednu narudžbu OGLEDNA-001.',
        ]);
    }

    private function contact(string $key): ContactMessage
    {
        return new ContactMessage([
            'name' => 'Ogledni kupac', 'email' => 'kupac@example.invalid', 'phone' => '+385 00 000 000',
            'subject' => $key === 'legacy-return' ? 'Ogledni zahtjev za povrat · OGLEDNA-001' : 'Upit o odabiru sata',
            'message' => $key === 'legacy-return'
                ? "Molim informacije o povratu oglednog artikla.\nNarudžba: OGLEDNA-001."
                : "Poštovani, zanima me odgovara li ovaj model manjem zapešću.\nHvala na pomoći!",
            'created_at' => '2026-10-25 10:00:00',
        ]);
    }

    /** @return array<string, mixed> */
    private function automationData(string $key): array
    {
        $isReview = $key === 'review-request';

        return [
            'subject' => $isReview ? 'Kako vam se sviđa vaš odabir?' : 'Vaš odabir još je u košarici',
            'heading' => $isReview ? 'Podijelite svoje iskustvo' : 'Još razmišljate o svom odabiru?',
            'intro' => $isReview ? 'Vaše mišljenje pomaže drugim kupcima pri odabiru. Ocijenite artikle iz svoje ogledne narudžbe.' : 'Artikle iz svoje ogledne košarice možete još jednom pogledati.',
            'products' => [['name' => 'Ogledni ženski ručni sat', 'url' => $this->exampleUrl(), 'quantity' => 1], ['name' => 'Ogledna ogrlica', 'url' => $this->exampleUrl(), 'quantity' => 2]],
            'coupon' => $isReview ? null : ['discount' => 10, 'code' => 'OGLEDNI-KOD-NE-VAZI', 'expires' => '01.11.2026.'],
            'action_url' => $this->exampleUrl(), 'action_label' => $isReview ? 'Ocijeni artikle' : 'Pogledaj košaricu',
            'unsubscribe_url' => $this->exampleUrl(),
        ];
    }

    /** @return array<string, mixed> */
    private function bankTransfer(float $amount): array
    {
        return [
            'receiver_name' => 'OGLEDNI PODACI · NIJE ZA UPLATU', 'receiver_iban' => 'HR00 0000 0000 0000 0000 0',
            'model' => 'HR00', 'reference' => 'OGLEDNA-001', 'amount' => $amount,
            'description' => 'Ogledni podaci, nije moguće izvršiti uplatu.',
            // Static PDF417 encodes only non-payment demo text; no payment service is called.
            'qr_image_base64' => base64_encode((string) file_get_contents(public_path('assets/previews/watchline-payment-demo.png'))),
            'qr_image_mime' => 'image/png', 'qr_preview_only' => true,
        ];
    }

    private function exampleUrl(): string
    {
        return url('/preview/watchline-mail#ogled');
    }
}
