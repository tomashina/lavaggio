<?php

namespace App\Services\Integrations\Fira;

use App\Services\Settings\SystemSettingsService;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

class FiraSettingsService
{
    public const ENDPOINT = 'https://app.fira.finance/api/v1/webshop/order/custom';

    public const PRODUCTS_ENDPOINT = 'https://app.fira.finance/api/v1/webshop/erp/products';

    public const PRICE_LIST_URL = 'https://app.fira.finance/api/v1/public/price-list/1-8rfbZo0XHscXF0CEq5_zNcoRSltl7B';

    public function __construct(private readonly SystemSettingsService $settings) {}

    public function options(): array
    {
        return array_replace([
            'domain' => 'lavaggio.hr',
            'document_type' => 'PONUDA',
            'point_of_sale_registered' => false,
            'fira_sequence_confirmed' => false,
            'send_enabled' => false,
            'auto_card_invoices_enabled' => false,
            'price_sync_enabled' => false,
        ], (array) $this->settings->get('lavaggio_fira', []), [
            'sequence' => 'WS-1',
            'einvoice_sequence' => 'WS-2',
        ]);
    }

    public function save(array $options, #[\SensitiveParameter] string $secret = ''): void
    {
        // Older callers cannot opt into automatic fiscalization by omitting this new field.
        $options = array_replace(['auto_card_invoices_enabled' => false, 'price_sync_enabled' => false], $options);
        $validated = Validator::make($options, [
            'document_type' => ['required', Rule::in(['PONUDA', 'FISKALNI_RAČUN'])],
            'point_of_sale_registered' => ['required', 'boolean'],
            'fira_sequence_confirmed' => ['required', 'boolean'],
            'send_enabled' => ['required', 'boolean'],
            'auto_card_invoices_enabled' => ['required', 'boolean'],
            'price_sync_enabled' => ['required', 'boolean'],
        ])->validate();
        Validator::make(['secret' => $secret], ['secret' => ['nullable', 'string', 'max:4096', 'not_regex:/[\r\n]/']])->validate();
        $validated['auto_card_invoices_enabled'] = (bool) $validated['auto_card_invoices_enabled'];
        if ($validated['auto_card_invoices_enabled'] && ($validated['document_type'] !== 'FISKALNI_RAČUN'
            || ! $validated['send_enabled'] || ! $validated['point_of_sale_registered'] || ! $validated['fira_sequence_confirmed'])) {
            throw \Illuminate\Validation\ValidationException::withMessages([
                'form.auto_card_invoices_enabled' => 'Automatska izrada zahtijeva odabrani fiskalni račun, uključenu izradu dokumenata te potvrđeno prodajno mjesto i fiskalnu konfiguraciju.',
            ]);
        }
        if ($validated['send_enabled'] && $validated['document_type'] === 'FISKALNI_RAČUN'
            && (! $validated['point_of_sale_registered'] || ! $validated['fira_sequence_confirmed'])) {
            throw \Illuminate\Validation\ValidationException::withMessages([
                'form.send_enabled' => 'Prvo prijavite prodajno mjesto Poreznoj i potvrdite slijed WS-1 u FIRA-i.',
            ]);
        }
        $entries = ['lavaggio_fira' => ['domain' => 'lavaggio.hr', 'sequence' => 'WS-1', 'einvoice_sequence' => 'WS-2', ...$validated]];
        if (trim($secret) !== '') {
            $entries['lavaggio_fira_secret'] = Crypt::encryptString(trim($secret));
        }
        $this->settings->putMany($entries);
    }

    /** The checkbox is an opt-in; all transport/fiscal guards still apply when issuing. */
    public function autoCardInvoicesEnabled(): bool
    {
        return (bool) $this->options()['auto_card_invoices_enabled'];
    }

    public function secret(): string
    {
        try {
            $encrypted = (string) $this->settings->get('lavaggio_fira_secret', '');

            return $encrypted === '' ? '' : Crypt::decryptString($encrypted);
        } catch (\Throwable) {
            return '';
        }
    }

    public function blockers(): array
    {
        $options = $this->options();
        $blockers = [];
        if (config('storefront.home') !== 'lavaggio') {
            $blockers[] = 'FIRA povezivanje dostupno je samo za Lavaggio.';
        }
        if (app()->environment('local')) {
            $blockers[] = 'Lokalni pregled: slanje dokumenata je isključeno.';
        }
        if (! in_array($options['document_type'], ['PONUDA', 'FISKALNI_RAČUN'], true)) {
            $blockers[] = 'Odaberite podržanu vrstu dokumenta u FIRA postavkama.';
        }
        if ($options['document_type'] === 'FISKALNI_RAČUN' && ! $options['point_of_sale_registered']) {
            $blockers[] = 'Prodajno mjesto još nije potvrđeno kao prijavljeno Poreznoj.';
        }
        if ($options['document_type'] === 'FISKALNI_RAČUN' && ! $options['fira_sequence_confirmed']) {
            $blockers[] = 'U FIRA-i treba postaviti i potvrditi slijed WS-1.';
        }
        if (! $options['send_enabled']) {
            $blockers[] = 'Izrada odabrane vrste dokumenta nije uključena.';
        }
        if ($this->secret() === '') {
            $blockers[] = 'Nedostaje tajni ključ za FIRA-u.';
        }

        return $blockers;
    }
}
