<?php

namespace App\Support;

/** Explicitly reviewed data upgrades; also read by the deployment preflight without booting Laravel. */
final class LavaggioDeployMigrations
{
    public const FILES = [
        'database/migrations/2026_11_04_113000_add_lavaggio_home_problems_widget.php',
        'database/migrations/2026_11_04_120000_sync_lavaggio_november_feedback.php',
        'database/migrations/2026_11_04_120100_sync_lavaggio_approved_presentation.php',
        'database/migrations/2026_11_06_090000_add_lavaggio_payment_information.php',
        'database/migrations/2026_11_09_090000_restore_lavaggio_service_pricing_date.php',
        'database/migrations/2026_11_09_110000_create_lavaggio_fira_documents_table.php',
        'database/migrations/2026_11_12_120000_create_box_now_shipments.php',
    ];
}
