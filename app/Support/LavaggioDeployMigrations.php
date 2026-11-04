<?php

namespace App\Support;

/** Explicitly reviewed data upgrades; also read by the deployment preflight without booting Laravel. */
final class LavaggioDeployMigrations
{
    public const FILES = [
        'database/migrations/2026_11_04_113000_add_lavaggio_home_problems_widget.php',
    ];
}
