<?php

namespace App\Http\Controllers\Front;

use App\Support\Lavaggio\PublishedPriceLists;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class LavaggioPriceListController
{
    public function current(PublishedPriceLists $lists, string $kind, string $format): BinaryFileResponse
    {
        return $lists->download($kind, $format);
    }

    public function archive(PublishedPriceLists $lists, string $kind, int $version, string $format): BinaryFileResponse
    {
        return $lists->download($kind, $format, $version);
    }
}
