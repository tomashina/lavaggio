<?php

namespace App\Services\Pricing;

final class VatInclusiveLine
{
    /**
     * A line discount can leave the average unit price between two cents.
     * Represent that total using the two actual unit prices and their quantities.
     * @return list<array{unit_cents:int,quantity:int,net_cents:int}>
     */
    public static function portions(int $grossCents, int $quantity, float $rate): array
    {
        $unit = intdiv($grossCents, $quantity);
        $extra = $grossCents % $quantity;
        $portions = [];
        foreach ([$unit => $quantity - $extra, $unit + 1 => $extra] as $price => $count) {
            if ($count === 0) {
                continue;
            }
            // FIRA converts a gross unit price to net with three decimal places.
            // With reduced VAT rates its rounding can accumulate across many units.
            $netMills = self::unitNetMills($price, $rate);
            $batchSize = $count;
            while ($batchSize > 1 && (int) round($netMills * $batchSize * (1 + $rate) / 10) !== $price * $batchSize) {
                $batchSize = intdiv($batchSize + 1, 2);
            }
            while ($count > 0) {
                $batch = min($batchSize, $count);
                $portions[] = [
                    'unit_cents' => $price,
                    'quantity' => $batch,
                    'net_cents' => (int) round($netMills * $batch / 10),
                ];
                $count -= $batch;
            }
        }

        return $portions;
    }

    public static function unitNetMills(int $grossCents, float $rate): int
    {
        return (int) round($grossCents * 10 / (1 + $rate));
    }
}
