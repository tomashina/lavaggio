<?php

namespace App\Console\Commands;

use App\Services\GiftVoucherDeliveryService;
use App\Services\GiftVoucherService;
use Illuminate\Console\Command;

class ProcessGiftVouchers extends Command
{
    protected $signature = 'gift-vouchers:process {--limit=50 : Najveći broj poruka}';

    protected $description = 'Oslobodi istekle rezervacije i pošalji pripremljene poruke poklon bonova';

    public function handle(GiftVoucherService $vouchers, GiftVoucherDeliveryService $delivery): int
    {
        $vouchers->releaseExpiredReservations();
        $stats = $delivery->sendPending((int) $this->option('limit'));
        if ($stats['disabled']) {
            $this->info('Slanje poklon bonova trenutačno je isključeno.');
        } else {
            $this->info("Provjereno: {$stats['checked']}; poslano: {$stats['sent']}; neuspjelo: {$stats['failed']}.");
        }

        return $stats['failed'] > 0 ? self::FAILURE : self::SUCCESS;
    }
}
