<?php

namespace App\Support;

final class LibermediaPaymentDescription
{
    public static function forConsumers(string $description): string
    {
        return str_replace(
            'Za tvrtke šaljemo ponudu.',
            'Plaćanje izvršite uplatom na račun prema navedenim podacima.',
            $description,
        );
    }
}
