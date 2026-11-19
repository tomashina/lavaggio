<?php

namespace App\Console\Commands;

use App\Support\Lavaggio\PublishedPriceLists;
use App\Support\Lavaggio\RetailPriceLists;
use Illuminate\Console\Command;

class LavaggioPublishPriceLists extends Command
{
    protected $signature = 'lavaggio:publish-price-lists';
    protected $description = 'Objavi izmijenjene cjenike i sačuvaj prethodne verzije 15 dana od zamjene';

    public function handle(PublishedPriceLists $publication, RetailPriceLists $source): int
    {
        if (config('storefront.home') !== 'lavaggio') {
            $this->error('Nije Lavaggio trgovina.');
            return self::FAILURE;
        }
        $this->line(json_encode($publication->publish($source), JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));

        return self::SUCCESS;
    }
}
