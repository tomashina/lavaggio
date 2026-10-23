<?php

namespace App\Console\Commands;

class LibermediaImport extends HerreraImport
{
    protected $signature = 'libermedia:import {--source-connection=libermedia_source} {--prefix=oc_} {--only=catalog,content,customers,billing_addresses,pricing,orders,seo,relations,archive} {--dry-run} {--final-sync}';

    protected $description = 'Import local Libermedia OpenCart data using the existing migration engine';

    public function handle(\App\Services\Import\HerreraOpenCartImportService $importer): int
    {
        $result = parent::handle($importer);
        if ($result === self::SUCCESS && ! $this->option('dry-run')) {
            $this->call('libermedia:curate-blog');
        }
        if ($result !== self::SUCCESS || $this->option('final-sync') || $this->option('prefix') !== 'oc_'
            || $this->option('only') !== 'catalog,content,customers,billing_addresses,pricing,orders,seo,relations,archive') {
            if ($result === self::SUCCESS && ! $this->option('dry-run')) {
                $this->call('libermedia:index-specification-filters');
            }

            return $result;
        }
        $stats = app(\App\Services\Import\LibermediaSupplementImportService::class)->import(
            (string) $this->option('source-connection'), (bool) $this->option('dry-run'),
        );
        $this->table(['Additional records', 'Count'], collect($stats)->map(fn ($v, $k) => [$k, $v])->all());
        if (! $this->option('dry-run')) {
            $this->call('libermedia:normalize-content');
            $this->call('libermedia:import-promotions');
            $this->call('libermedia:index-specification-filters');
        }

        return self::SUCCESS;
    }
}
