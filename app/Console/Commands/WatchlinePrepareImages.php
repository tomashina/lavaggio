<?php

namespace App\Console\Commands;

use App\Services\Import\WatchlineImageVariantService;
use Illuminate\Console\Command;
use InvalidArgumentException;
use Throwable;

class WatchlinePrepareImages extends Command
{
    protected $signature = 'watchline:prepare-images
        {--limit=200 : Maximum active products, 0 explicitly selects all active products}
        {--product-ids= : Optional comma-separated active product IDs to prioritize}
        {--apply : Encode selected local images and publish the private index; never changes catalog records}
        {--json : Print aggregate results as JSON}';

    protected $description = 'Plan or prepare local WebP160/480/960 variants only for selected active main/hover originals';

    public function handle(WatchlineImageVariantService $service): int
    {
        try {
            $limit = (string) $this->option('limit');
            $raw = trim((string) $this->option('product-ids'));
            if (! ctype_digit($limit) || (int) $limit > 10000
                || ($raw !== '' && (! preg_match('/\A[1-9][0-9]*(?:,[1-9][0-9]*)*\z/D', $raw) || strlen($raw) > 50000))) {
                throw new InvalidArgumentException('Use a bounded nonnegative limit and comma-separated positive IDs.');
            }
            $ids = $raw === '' ? [] : array_values(array_unique(array_map('intval', explode(',', $raw))));
            $selection = $service->select((int) $limit, $ids);
            $report = ['selected_active_products' => $selection['products'], 'unavailable_references' => $selection['unavailable_references']]
                + $service->process($selection['paths'], (bool) $this->option('apply'));
            if ($this->option('json')) {
                $this->line(json_encode($report, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT));
            } else {
                $this->info($report['dry_run'] ? 'Plan pripreme slika; ništa nije zapisano.' : 'Lokalne izvedenice slika pripremljene.');
                $this->line('Aktivni artikli: '.$report['selected_active_products'].'; originali: '.$report['selected_paths'].'; nove izvedenice: '.$report['generated_files'].'; postojeće: '.$report['reused_files']);
                $this->line('Originali i podaci kataloga ostaju sačuvani. Prikaz izvedenica uključuje se zasebnom postavkom.');
            }

            return self::SUCCESS;
        } catch (Throwable $exception) {
            $this->error('Priprema slika sigurno je zaustavljena ('.class_basename($exception).'). Nema uvoza kataloga ni mrežnih zahtjeva.');

            return self::FAILURE;
        }
    }
}
