<?php

namespace App\Console\Commands;

use App\Models\Catalog\Attribute\Attribute;
use App\Models\Catalog\Category\Category;
use App\Models\Catalog\Product\Product;
use App\Models\Content\ContentBlock;
use App\Services\Content\ContentBlockResolver;
use App\Services\Front\GuestStorefrontCache;
use App\Support\LavaggioCampaignSlides;
use App\Support\Media\MediaUrl;
use App\Support\WatchlineWidgetSettings;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use RuntimeException;

class LavaggioConfigurePromoExamples extends Command
{
    protected $signature = 'lavaggio:configure-promo-examples {--apply : Add examples only when no edited slide list exists}';

    protected $description = 'Preview or add three local Lavaggio promotion examples while preserving the current banner';

    public function handle(): int
    {
        try {
            $this->guardDestination();
            $block = ContentBlock::query()->where('code', 'lavaggio-home-campaigns')->where('type', 'lavaggio_campaigns')
                ->with('translations')->first();
            $translation = $block?->translations->firstWhere('locale', 'hr');
            if (! $translation) {
                throw new RuntimeException('Nedostaje postojeći hrvatski promo blok. Ništa nije dodano.');
            }
            $stored = array_replace((array) data_get($block->payload, 'watchline', []), (array) data_get($translation->payload, 'watchline', []));
            if (array_key_exists('slides', $stored) && $stored['slides'] !== [] && $stored['slides'] !== null) {
                $this->info('Postojeći popis slajdova je sačuvan. Ogledni slajdovi nisu dodani.');

                return self::SUCCESS;
            }
            $settings = WatchlineWidgetSettings::forBlock($block, $translation);
            $slides = [LavaggioCampaignSlides::legacy($settings)];
            $stains = Category::query()->where('scope', Category::SCOPE_CATALOG)->currentlyVisible()
                ->where('code', 'lavaggio-uklanjanje-mrlja')->with('translations')->first();
            $stainsSlug = $stains?->translations->firstWhere('locale', 'hr')?->slug;
            $delicateId = Attribute::query()->where('code', 'lavaggio-fabric-osjetljive-tkanine')->where('is_active', true)->value('id');
            if (! $stainsSlug || ! $delicateId) {
                throw new RuntimeException('Nedostaju postojeća kategorija mrlja ili filtar osjetljivih tkanina. Ništa nije dodano.');
            }
            $slides[] = $this->example('lavaggio-5999109563200', [
                'eyebrow' => 'RJEŠENJA ZA MRLJE', 'title' => 'Mrlje imaju svoje rješenje.',
                'subtitle' => 'Istražite sredstva za predtretman i uklanjanje mrlja iz naše ponude.',
                'cta_label' => 'Pronađite rješenje za mrlje', 'url' => route('categories.show', $stainsSlug, false),
            ]);
            $slides[] = $this->example('lavaggio-9000101566789', [
                'eyebrow' => 'PAŽLJIVO ODABRANA NJEGA', 'title' => 'Njega za osjetljivo rublje.',
                'subtitle' => 'Pronađite proizvode za rublje kojem želite posvetiti posebnu pažnju.',
                'cta_label' => 'Istražite njegu rublja', 'url' => route('shop.index', ['attr_lavaggio_fabric' => $delicateId], false),
            ]);
            foreach ($slides as $index => $slide) {
                $this->line(($index + 1).'. '.$slide['title'].' | '.$slide['image'].' | '.$slide['url']);
            }
            if (! $this->option('apply')) {
                $this->info('Samo pregled. --apply čuva postojeći banner kao prvi i dodaje dva ogledna slajda, bez novih cijena ili popusta.');

                return self::SUCCESS;
            }
            if (config('database.connections.sqlite.database') !== ':memory:') {
                $directory = storage_path('app/private/lavaggio-feedback-backups');
                File::ensureDirectoryExists($directory, 0700, true);
                $backup = $directory.'/before-promo-examples-'.now()->format('Ymd-His').'-'.bin2hex(random_bytes(3)).'.sqlite';
                DB::statement('VACUUM INTO ?', [$backup]);
                chmod($backup, 0600);
                $this->info('Privatna sigurnosna kopija lokalne baze je spremljena.');
            }
            DB::transaction(function () use ($translation, $slides): void {
                $current = $translation->newQuery()->lockForUpdate()->findOrFail($translation->id);
                if ($current->getRawOriginal('payload') !== $translation->getRawOriginal('payload')
                    || $current->updated_at?->toJSON() !== $translation->updated_at?->toJSON()) {
                    throw new RuntimeException('Promo blok je u međuvremenu uređen. Spremanje je zaustavljeno radi očuvanja izmjena.');
                }
                $payload = (array) $current->payload;
                $payload['watchline'] = (array) ($payload['watchline'] ?? []);
                $payload['watchline']['slides'] = $slides;
                $current->update(['payload' => $payload]);
            });
            ContentBlockResolver::bumpCacheVersion();
            app(GuestStorefrontCache::class)->invalidate();
            $this->info('Tri ogledna slajda su pripremljena lokalno. Prvi banner i ostale postavke ostali su sačuvani.');

            return self::SUCCESS;
        } catch (\Throwable $exception) {
            $this->error($exception instanceof RuntimeException ? $exception->getMessage() : 'Priprema je zaustavljena: '.get_class($exception));

            return self::FAILURE;
        }
    }

    private function example(string $productCode, array $copy): array
    {
        $product = Product::query()->where('code', $productCode)->where('is_active', true)->with(['translations', 'media'])->first();
        $translation = $product?->translations->firstWhere('locale', 'hr');
        $media = $product?->media->first(fn ($item) => $item->collection_name === 'product_main'
            && $item->disk === 'public' && MediaUrl::hasUsableOriginal($item));
        if (! $translation || ! $media) {
            throw new RuntimeException('Nedostaje postojeća lokalna fotografija proizvoda '.$productCode.'. Ništa nije dodano.');
        }
        $image = MediaUrl::conversionOrNull($media, 'card_720w', true) ?? $media->getUrl();
        $path = (string) parse_url($image, PHP_URL_PATH);
        if (WatchlineWidgetSettings::safeUrl($path, true) === '') {
            throw new RuntimeException('Fotografija proizvoda nema valjanu lokalnu adresu.');
        }

        return LavaggioCampaignSlides::normalize([$copy + ['image' => $path, 'image_alt' => $translation->name,
            'display' => 'text_image', 'enabled' => true]])[0];
    }

    private function guardDestination(): void
    {
        if (! app()->environment(['local', 'testing']) || config('storefront.home') !== 'lavaggio'
            || config('database.default') !== 'sqlite' || config('database.connections.sqlite.url')
            || DB::connection()->getDriverName() !== 'sqlite') {
            throw new RuntimeException('Dopušteno samo za lokalni Lavaggio SQLite pregled.');
        }
        $configured = config('database.connections.sqlite.database');
        if (app()->environment('testing') && $configured === ':memory:') {
            return;
        }
        $expected = realpath(database_path('database.sqlite'));
        if (! app()->environment('local') || ! $expected || $configured !== $expected
            || $expected !== realpath(base_path()).'/database/database.sqlite'
            || is_link(database_path()) || is_link(database_path('database.sqlite'))) {
            throw new RuntimeException('Aktivna baza nije provjerena lokalna database/database.sqlite.');
        }
        $active = collect(DB::connection()->select('PRAGMA database_list'))->firstWhere('name', 'main');
        if (! $active || $active->file !== $expected) {
            throw new RuntimeException('SQLite veza ne pokazuje na provjerenu lokalnu bazu.');
        }
    }
}
