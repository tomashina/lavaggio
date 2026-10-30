<?php

namespace App\Services\Payments;

use Com\Tecnick\Barcode\Barcode;
use InvalidArgumentException;

class EpcQrService
{
    /** EPC069-12 v3.1: UTF-8, SCT version 002, level M, at most 331 bytes. */
    public function payload(array $snapshot): string
    {
        $iban = strtoupper(preg_replace('/\s+/', '', (string) ($snapshot['receiver_iban'] ?? '')));
        $name = $this->line((string) ($snapshot['receiver_name'] ?? ''));
        $amount = (int) ($snapshot['amount_cents'] ?? 0);
        if (! $this->validIban($iban) || $name === '' || mb_strlen($name) > 70
            || ($snapshot['currency'] ?? 'EUR') !== 'EUR' || $amount < 1 || $amount > 99999999999) {
            throw new InvalidArgumentException('Incomplete or invalid SEPA payment data.');
        }
        $reference = $this->line((string) ($snapshot['reference'] ?? ''));
        $model = 'HR'.preg_replace('/^HR/i', '', $this->line((string) ($snapshot['model'] ?? '00')));
        $description = $this->line((string) ($snapshot['description'] ?? ''));
        $suffix = trim($model.' '.$reference);
        $remittance = trim(mb_substr($description, 0, max(0, 139 - mb_strlen($suffix))).' '.$suffix);
        $purpose = strtoupper($this->line((string) ($snapshot['purpose_code'] ?? '')));
        $purpose = preg_match('/^[A-Z]{4}$/', $purpose) ? $purpose : '';
        $payload = implode("\n", ['BCD', '002', '1', 'SCT', '', $name, $iban,
            'EUR'.number_format($amount / 100, 2, '.', ''), $purpose, '', $remittance]);
        if (strlen($payload) > 331) {
            throw new InvalidArgumentException('SEPA QR data exceeds 331 bytes.');
        }

        return $payload;
    }

    public function png(array $snapshot): string
    {
        return (new Barcode)->getBarcodeObj('QRCODE,M', $this->payload($snapshot), -6, -6, 'black', [-4, -4, -4, -4])
            ->setBackgroundColor('white')->getPngData();
    }

    public function validIban(string $iban): bool
    {
        $iban = strtoupper(preg_replace('/\s+/', '', $iban));
        if (! preg_match('/^[A-Z]{2}[0-9]{2}[A-Z0-9]{11,30}$/', $iban)
            || (str_starts_with($iban, 'HR') && strlen($iban) !== 21)) {
            return false;
        }
        $rearranged = substr($iban, 4).substr($iban, 0, 4);
        $remainder = 0;
        foreach (str_split($rearranged) as $character) {
            $digits = ctype_alpha($character) ? (string) (ord($character) - 55) : $character;
            foreach (str_split($digits) as $digit) {
                $remainder = ($remainder * 10 + (int) $digit) % 97;
            }
        }

        return $remainder === 1;
    }

    private function line(string $value): string
    {
        return trim(preg_replace('/[\r\n\x00-\x1f]+/u', ' ', $value));
    }
}
