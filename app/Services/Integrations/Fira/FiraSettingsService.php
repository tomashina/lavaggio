<?php

namespace App\Services\Integrations\Fira;

use App\Services\Settings\SystemSettingsService;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

class FiraSettingsService
{
    public const ENDPOINT = 'https://app.fira.finance/api/v1/webshop/order/custom';

    public function __construct(private readonly SystemSettingsService $settings) {}

    public function options(): array
    {
        return array_replace([
            'domain' => 'lavaggio.hr',
            'sequence' => '1-web-1',
            'document_type' => 'PONUDA',
            'point_of_sale_registered' => false,
            'fira_sequence_confirmed' => false,
            'send_enabled' => false,
        ], (array) $this->settings->get('lavaggio_fira', []));
    }

    public function save(array $options, #[\SensitiveParameter] string $secret = ''): void
    {
        $validated = Validator::make($options, [
            'document_type' => ['required', Rule::in(['PONUDA', 'FISKALNI_RAČUN'])],
            'point_of_sale_registered' => ['required', 'boolean'],
            'fira_sequence_confirmed' => ['required', 'boolean'],
            'send_enabled' => ['required', 'boolean'],
        ])->validate();
        Validator::make(['secret' => $secret], ['secret' => ['nullable', 'string', 'max:4096', 'not_regex:/[\r\n]/']])->validate();
        if ($validated['send_enabled'] && $validated['document_type'] === 'FISKALNI_RAČUN'
            && (! $validated['point_of_sale_registered'] || ! $validated['fira_sequence_confirmed'])) {
            throw \Illuminate\Validation\ValidationException::withMessages([
                'form.send_enabled' => 'Prvo prijavite prodajno mjesto Poreznoj i potvrdite slijed 1-web-1 u FIRA-i.',
            ]);
        }
        $entries = ['lavaggio_fira' => ['domain' => 'lavaggio.hr', 'sequence' => '1-web-1', ...$validated]];
        if (trim($secret) !== '') {
            $entries['lavaggio_fira_secret'] = Crypt::encryptString(trim($secret));
        }
        $this->settings->putMany($entries);
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
            $blockers[] = 'U FIRA-i treba postaviti i potvrditi slijed 1-web-1.';
        }
        if (! $options['send_enabled']) {
            $blockers[] = 'Ručna izrada odabrane vrste dokumenta nije uključena.';
        }
        if ($this->secret() === '') {
            $blockers[] = 'Nedostaje tajni ključ za FIRA-u.';
        }

        return $blockers;
    }
}
