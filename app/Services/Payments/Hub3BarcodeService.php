<?php

namespace App\Services\Payments;

use App\Support\Hub3Pdf417;
use InvalidArgumentException;

class Hub3BarcodeService
{
    /** HUB EUR specification v6: 14 LF-terminated fields, amounts in cents. */
    public function payload(array $snapshot): string
    {
        $iban = strtoupper(preg_replace('/\s+/', '', (string) ($snapshot['receiver_iban'] ?? '')));
        $amount = (int) ($snapshot['amount_cents'] ?? 0);
        if (! str_starts_with($iban, 'HR') || ! app(EpcQrService::class)->validIban($iban)
            || ($snapshot['currency'] ?? 'EUR') !== 'EUR' || $amount < 1 || $amount > 999999999999999) {
            throw new InvalidArgumentException('Incomplete or invalid HUB3 payment data.');
        }
        $model = strtoupper(preg_replace('/^HR/i', '', (string) ($snapshot['model'] ?? '00')));
        if (! preg_match('/^[0-9]{2}$/', $model)) {
            throw new InvalidArgumentException('Invalid Croatian reference model.');
        }
        $fields = [
            'HRVHUB30', 'EUR', str_pad((string) $amount, 15, '0', STR_PAD_LEFT),
            $this->field($snapshot['sender_name'] ?? '', 30), $this->field($snapshot['sender_street'] ?? '', 27),
            $this->field($snapshot['sender_place'] ?? '', 27), $this->field($snapshot['receiver_name'] ?? '', 25),
            $this->field($snapshot['receiver_street'] ?? '', 25), $this->field($snapshot['receiver_place'] ?? '', 27),
            $iban, 'HR'.$model, $this->field($snapshot['reference'] ?? '', 22),
            $this->field($snapshot['purpose_code'] ?? '', 4), $this->field($snapshot['description'] ?? '', 35),
        ];

        return implode("\n", $fields)."\n";
    }

    public function png(array $snapshot): string
    {
        $code = new Hub3Pdf417($this->payload($snapshot), -3, -3);
        $rows = $columns = $errorLevel = 0;
        $code->getCodewords($rows, $columns, $errorLevel);
        if ($columns !== 9 || $rows * 3 + 4 > 102) {
            throw new InvalidArgumentException('HUB3 data exceeds the permitted barcode height.');
        }

        return $code->setBackgroundColor('white')->getPngData();
    }

    private function field(mixed $value, int $length): string
    {
        $value = preg_replace('/[^0-9A-Za-zČĆĐŠŽčćđšž ,.:+?\x{0027}\/()\-]/u', ' ', (string) $value);

        return mb_substr(trim(preg_replace('/\s+/u', ' ', $value)), 0, $length);
    }
}
