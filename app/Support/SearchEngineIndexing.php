<?php

namespace App\Support;

use Illuminate\Http\Request;

class SearchEngineIndexing
{
    public static function shouldBlock(Request $request): bool
    {
        $host = strtolower($request->getHost());

        return ! app()->environment('production')
            || $host === 'localhost'
            || str_ends_with($host, '.localhost')
            || str_ends_with($host, '.test')
            || filter_var(trim($host, '[]'), FILTER_VALIDATE_IP) !== false
            || in_array($host, ['herrera.herrera.hr', 'libermedia.agmedia.rocks'], true);
    }
}
