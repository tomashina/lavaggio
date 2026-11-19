<?php

namespace App\Support\Lavaggio;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\File;
use RuntimeException;
use XMLWriter;

class PublishedPriceLists
{
    // Identity and outlet designation from Bareda's public FIRA publication of 19 November 2026.
    private const MERCHANT_OIB = '96889385802';
    private const OUTLET = 'LAVAGGIO-CJENIK_Barban-70-52207-Barban_96889385802';

    private function directory(): string
    {
        return storage_path('app/private/lavaggio-price-lists');
    }

    private function index(): array
    {
        $path = $this->directory().'/index.json';

        return is_file($path) ? json_decode(File::get($path), true, flags: JSON_THROW_ON_ERROR) : [];
    }

    /** One lock covers data collection and both files, so a slower run cannot roll prices back. */
    public function publish(RetailPriceLists $source): array
    {
        $dir = $this->directory();
        File::ensureDirectoryExists($dir);
        $lock = fopen($dir.'/publication.lock', 'c');
        if (! $lock || ! flock($lock, LOCK_EX)) {
            throw new RuntimeException('Zaključavanje cjenika nije uspjelo.');
        }
        try {
            $now = CarbonImmutable::now('Europe/Zagreb');
            $index = $this->index();
            $obsolete = $result = [];
            foreach (['roba' => $source->goods(), 'usluge' => $source->services()] as $kind => $rows) {
                if ($rows === []) {
                    throw new RuntimeException('Prazan izvoz: '.$kind);
                }
                $headers = $kind === 'roba' ? RetailPriceLists::GOODS : RetailPriceLists::SERVICES;
                $place = $kind === 'roba' ? 'PRODAJA-PUTEM-INTERNETA' : 'PRAONICA';
                $prefix = self::MERCHANT_OIB.'_'.$place.'-'.self::OUTLET.'_'.strtoupper($kind);
                $hash = hash('sha256', json_encode([$prefix, $headers, $rows], JSON_THROW_ON_ERROR));
                $versions = $index[$kind] ?? [];
                $last = array_key_last($versions);
                if ($last === null || $versions[$last]['hash'] !== $hash) {
                    $id = (int) $last + 1;
                    $name = $prefix.'_'.$id.'_'.$now->format('Ymd_Hi');
                    File::replace($dir.'/'.$name.'.csv', $this->csv($headers, $rows));
                    File::replace($dir.'/'.$name.'.xml', $this->xml($kind, $headers, $rows));
                    if ($last !== null) {
                        $versions[$last]['replaced'] = $now->timestamp;
                    }
                    $versions[$id] = ['name' => $name, 'hash' => $hash, 'published' => $now->timestamp, 'replaced' => null];
                }
                foreach ($versions as $id => $version) {
                    if ($version['replaced'] !== null && $version['replaced'] < $now->subDays(15)->timestamp) {
                        $obsolete[] = $version['name'];
                        unset($versions[$id]);
                    }
                }
                $index[$kind] = $versions;
                $result[$kind] = ['rows' => count($rows), 'version' => array_key_last($versions),
                    'unconfirmed' => array_values(array_map(fn ($r) => $kind === 'roba' ? $r[1] : $r[0],
                        array_filter($rows, static fn ($r) => end($r) === '')))];
            }
            File::replace($dir.'/index.json', json_encode($index, JSON_THROW_ON_ERROR));
            foreach ($obsolete as $name) {
                File::delete([$dir.'/'.$name.'.csv', $dir.'/'.$name.'.xml']);
            }

            return $result;
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    public function archive(): array
    {
        $archive = [];
        foreach ($this->index() as $kind => $versions) {
            foreach (array_reverse($versions, true) as $id => $version) {
                if ($version['replaced'] !== null) {
                    $archive[] = ['kind' => $kind, 'id' => $id, 'date' => CarbonImmutable::createFromTimestamp($version['published'], 'Europe/Zagreb')->format('d.m.Y. H:i')];
                }
            }
        }
        return $archive;
    }

    public function download(string $kind, string $format, ?int $version = null)
    {
        $versions = $this->index()[$kind] ?? [];
        $entry = $versions[$version ?? array_key_last($versions)] ?? null;
        abort_unless($entry, $version === null ? 503 : 404);
        $filename = $entry['name'].'.'.$format;

        return response()->download($this->directory().'/'.$filename, $filename, [
            'Content-Type' => ($format === 'csv' ? 'text/csv' : 'application/xml').'; charset=UTF-8',
            'Cache-Control' => 'public, no-cache',
        ]);
    }

    private function csv(array $headers, array $rows): string
    {
        $stream = fopen('php://temp', 'w+');
        fputcsv($stream, array_values($headers), ';', '"', '', "\r\n");
        foreach ($rows as $row) {
            foreach (array_keys($headers) as $i => $key) {
                if (in_array($key, ['neto_kolicina', 'maloprodajna_cijena', 'cijena_za_jedinicu_mjere', 'bazna_cijena'], true)) {
                    $row[$i] = str_replace('.', ',', (string) $row[$i]);
                }
            }
            fputcsv($stream, $row, ';', '"', '', "\r\n");
        }
        rewind($stream);
        $csv = stream_get_contents($stream);
        fclose($stream);

        return $csv;
    }

    private function xml(string $kind, array $headers, array $rows): string
    {
        $xml = new XMLWriter;
        $xml->openMemory();
        $xml->startDocument('1.0', 'UTF-8');
        $xml->startElement('cjenik');
        $xml->writeAttribute('oib', self::MERCHANT_OIB);
        $xml->writeAttribute('trgovac', 'Bareda d.o.o.');
        $xml->writeAttribute('valuta', 'EUR');
        foreach ($rows as $row) {
            $xml->startElement($kind === 'roba' ? 'roba' : 'usluga');
            foreach (array_combine(array_keys($headers), $row) as $name => $value) {
                $xml->writeElement($name, (string) $value);
            }
            $xml->endElement();
        }
        $xml->endDocument();

        return $xml->outputMemory();
    }
}
