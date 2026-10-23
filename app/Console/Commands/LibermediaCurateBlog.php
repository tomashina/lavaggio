<?php

namespace App\Console\Commands;

use App\Services\Import\LibermediaBlogCategoryService;
use Illuminate\Console\Command;

class LibermediaCurateBlog extends Command
{
    protected $signature = 'libermedia:curate-blog';

    protected $description = 'Apply the verified editable Libermedia blog categories once, preserving later admin changes';

    public function handle(LibermediaBlogCategoryService $categories): int
    {
        $this->info('Dodane uredničke poveznice članaka: '.$categories->apply());

        return self::SUCCESS;
    }
}
