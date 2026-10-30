<?php

namespace Database\Seeders;

use App\Models\Catalog\Manufacturer\Manufacturer;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Cache;
use RuntimeException;

class LavaggioBrandLogoSeeder extends Seeder
{
    public function run(): void
    {
        $records = json_decode(
            file_get_contents(database_path('data/lavaggio-brand-logos.json')),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );

        foreach ($records as $record) {
            $manufacturer = Manufacturer::query()->where('code', $record['code'])->first();
            if (! $manufacturer) {
                continue;
            }

            $relativePath = (string) $record['path'];
            if (! str_starts_with($relativePath, 'assets/lavaggio/brands/') || str_contains($relativePath, '..')) {
                throw new RuntimeException('Invalid local Lavaggio logo path.');
            }

            $path = public_path($relativePath);
            if (! is_file($path)) {
                throw new RuntimeException('Missing Lavaggio brand logo: '.$relativePath);
            }

            $existing = $manufacturer->getFirstMedia('manufacturer_logo');
            $hash = hash_file('sha256', $path);
            if ($existing) {
                // Preserve a logo uploaded by an administrator after the initial import.
                if ($existing->getCustomProperty('source') !== 'lavaggio-brand-logos') {
                    continue;
                }
                if ($existing->getCustomProperty('asset_sha256') === $hash && is_file($existing->getPath())) {
                    continue;
                }
            }

            $manufacturer->addMedia($path)
                ->preservingOriginal()
                ->usingName(($record['name'] ?? $manufacturer->code).' logo')
                ->withCustomProperties([
                    'source' => 'lavaggio-brand-logos',
                    'source_url' => $record['source_url'],
                    'source_page' => $record['source_page'],
                    'retrieved_at' => $record['retrieved_at'],
                    'asset_sha256' => $hash,
                    'normalization' => $record['normalization'],
                ])
                ->toMediaCollection('manufacturer_logo', 'public');

            $this->command?->info('Imported logo: '.($record['name'] ?? $manufacturer->code));
        }

        Cache::flush();
    }
}
