<?php

namespace App\Services\Integrations\Watchline;

use Brick\Math\BigDecimal;
use RuntimeException;

/** The legacy GATH job consumes a local, headerless SKU;quantity;EUR-price;brand file. */
class GathCsvReader
{
    private const MAX_BYTES = 20971520;

    public function read(string $path): array
    {
        if ($path === '' || str_contains($path, '://') || str_contains($path, "\0") || ! is_file($path) || ! is_readable($path)) {
            throw new RuntimeException('GATH datoteka nije dostupna. Odaberite čitljivu lokalnu CSV datoteku.');
        }

        $handle = @fopen($path, 'rb');
        if ($handle === false) {
            throw new RuntimeException('GATH datoteku nije moguće pročitati.');
        }
        try {
            if (! flock($handle, LOCK_SH)) {
                throw new RuntimeException('GATH datoteka se trenutačno mijenja. Pokušajte ponovno.');
            }
            $bytes = stream_get_contents($handle, self::MAX_BYTES + 1);
            $stat = fstat($handle);
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
        if ($bytes === false || strlen($bytes) > self::MAX_BYTES || trim($bytes) === '') {
            throw new RuntimeException('GATH datoteka je prazna ili prelazi dopuštenih 20 MB.');
        }

        $csv = fopen('php://temp', 'w+b');
        fwrite($csv, str_starts_with($bytes, "\xEF\xBB\xBF") ? substr($bytes, 3) : $bytes);
        rewind($csv);
        $rows = [];
        $fetched = 0;
        $duplicates = 0;
        try {
            while (($columns = fgetcsv($csv, null, ';', '"', '')) !== false) {
                if (count($columns) === 1 && trim((string) $columns[0]) === '') {
                    continue;
                }
                $fetched++;
                $identifier = (string) ($columns[0] ?? '');
                $quantity = trim((string) ($columns[1] ?? ''));
                $price = str_replace(',', '.', trim((string) ($columns[2] ?? '')));
                if (count($columns) < 3 || count($columns) > 4 || trim($identifier) === '' || mb_strlen($identifier) > 160
                    || ! mb_check_encoding($identifier, 'UTF-8') || preg_match('/[\x00-\x1f\x7f]/u', $identifier)
                    || ! preg_match('/^\d{1,10}$/D', $quantity) || (float) $quantity > 2147483647
                    || ! preg_match('/^\d{1,12}(?:\.\d{1,4})?$/D', $price)) {
                    throw new RuntimeException('GATH CSV sadrži neispravan redak '.$fetched.'. Očekuju se SKU, nenegativna cijela količina i cijena u EUR. Nijedna promjena nije provedena.');
                }
                $row = ['identifier' => $identifier, 'quantity' => (int) $quantity, 'price' => (string) BigDecimal::of($price)->toScale(4)];
                $key = self::key($identifier);
                if (isset($rows[$key])) {
                    if ($rows[$key]['quantity'] !== $row['quantity'] || $rows[$key]['price'] !== $row['price']) {
                        throw new RuntimeException('GATH CSV sadrži proturječne retke za istu SKU oznaku. Nijedna promjena nije provedena.');
                    }
                    $duplicates++;
                } else {
                    $rows[$key] = $row;
                }
            }
        } finally {
            fclose($csv);
        }
        if ($rows === []) {
            throw new RuntimeException('GATH CSV ne sadrži artikle. Nijedna promjena nije provedena.');
        }

        return [
            'rows' => $rows, 'fetched_count' => $fetched, 'duplicate_count' => $duplicates,
            'checksum' => hash('sha256', $bytes), 'filename' => basename($path),
            'file_modified_at' => isset($stat['mtime']) ? date(DATE_ATOM, $stat['mtime']) : null,
            'bytes' => strlen($bytes),
        ];
    }

    public static function key(string $identifier): string
    {
        // Match the old MySQL SKU equality: leading spaces are significant;
        // trailing ASCII spaces are ignored by its PAD SPACE collation.
        return 'id:'.mb_strtolower(rtrim($identifier, ' '), 'UTF-8');
    }
}
