<?php

namespace App\Console\Commands;

use App\Services\Import\WatchlineMediaManifestService;
use Illuminate\Console\Command;
use InvalidArgumentException;
use Throwable;

class WatchlineMediaManifest extends Command
{
    protected $signature = 'watchline:media-manifest
        {--source-root= : Optional readable original OpenCart image directory; verifies exact files without copying}
        {--output= : New directory below .local/watchline-media; defaults to a unique private run directory}
        {--json : Print aggregate export results as JSON}';

    protected $description = 'Export only referenced Watchline image originals and separate native files; never fetch, copy or delete images';

    public function handle(WatchlineMediaManifestService $service): int
    {
        try {
            $manifest = $service->inspect($this->option('source-root') ?: null);
            $directory = $this->outputDirectory();
            if (! mkdir($directory, 0700, true)) {
                throw new \RuntimeException('Cannot create private manifest directory.');
            }
            foreach ([
                'manifest.json' => json_encode($manifest, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                'opencart-originals.files0' => $service->fileList($manifest),
                'native-public.files0' => $service->fileList($manifest, 'native_public'),
            ] as $filename => $contents) {
                $handle = fopen($directory.'/'.$filename, 'xb');
                if ($handle === false) {
                    throw new \RuntimeException('Cannot create a new manifest file.');
                }
                chmod($directory.'/'.$filename, 0600);
                try {
                    $remaining = $contents;
                    while ($remaining !== '') {
                        $written = fwrite($handle, $remaining);
                        if ($written === false || $written === 0) {
                            throw new \RuntimeException('Cannot finish manifest file.');
                        }
                        $remaining = substr($remaining, $written);
                    }
                } finally {
                    fclose($handle);
                }
            }
            $report = ['directory' => $directory, 'source_root_verified' => $manifest['source_root_verified'],
                'summary' => $manifest['summary'], 'cache_alias_count' => count($manifest['cache_aliases']),
                'excluded_or_rejected_count' => count($manifest['excluded_or_rejected']), 'review_required' => $manifest['review_required'],
                'database_mutated' => false, 'remote_requests' => false, 'media_copied' => false];
            if ($this->option('json')) {
                $this->line(json_encode($report, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
            } else {
                $this->info('Privatni popis slika: '.$directory);
                $this->line('OpenCart originali: '.$manifest['summary']['opencart']['paths'].'; nativne slike: '.$manifest['summary']['native_public']['paths']);
                $this->warn('Prije prijenosa pregledajte manifest, nepotvrđene putanje i cache_aliases. Slike nisu kopirane; baza nije mijenjana.');
            }

            return self::SUCCESS;
        } catch (Throwable $exception) {
            // Never include SQL bindings, content HTML, environment values or source credentials.
            $this->error('Izrada popisa sigurno je zaustavljena ('.class_basename($exception).'). Nema prijenosa slika ni izmjene baze.');

            return self::FAILURE;
        }
    }

    private function outputDirectory(): string
    {
        $base = base_path('.local/watchline-media');
        $output = $this->option('output') ?: $base.'/'.now()->format('Ymd_His').'-'.bin2hex(random_bytes(6));
        if (! str_starts_with($output, '/')) {
            $output = base_path($output);
        }
        if (! str_starts_with($output, $base.'/') || preg_match('/[\x00-\x1f\x7f\\\\]/', $output)
            || count(array_intersect(explode('/', $output), ['.', '..'])) > 0 || file_exists($output) || is_link($output)) {
            throw new InvalidArgumentException('Use a new directory below .local/watchline-media.');
        }
        $cursor = base_path();
        foreach (explode('/', substr($output, strlen(base_path()) + 1)) as $segment) {
            $cursor .= '/'.$segment;
            if (is_link($cursor)) {
                throw new InvalidArgumentException('Manifest directories cannot pass through symlinks.');
            }
        }

        return $output;
    }
}
