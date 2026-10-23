<?php

namespace App\Console\Commands;

use App\Services\Catalog\StockNotificationService;
use Illuminate\Console\Command;

class SendStockNotifications extends Command
{
    protected $signature = 'stock-notifications:send {--limit= : Najveći broj obavijesti u jednom pokretanju} {--dry-run : Provjeri dostupnost bez slanja i promjene prijava}';

    protected $description = 'Pošalji jednokratne obavijesti za artikle i komplete koji su ponovno dostupni';

    public function handle(StockNotificationService $service): int
    {
        $stats = $service->processReady((int) ($this->option('limit') ?: config('stock_notifications.batch_size', 50)), (bool) $this->option('dry-run'));
        if ($stats['disabled']) {
            $this->info('Slanje obavijesti nije uključeno ili nije postavljen stvarni način slanja e-pošte.');
        } else {
            $this->info("Provjereno: {$stats['checked']}; dostupno: {$stats['ready']}; poslano: {$stats['sent']}; neuspjelo: {$stats['failed']}.");
        }

        return $stats['failed'] > 0 ? self::FAILURE : self::SUCCESS;
    }
}
