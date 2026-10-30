<?php

namespace Database\Seeders;

use App\Models\Catalog\Action\CatalogAction;
use App\Models\Catalog\Product\Product;
use App\Models\Content\Support\Comment;
use App\Services\Content\ContentBlockResolver;
use App\Services\Front\GuestStorefrontCache;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class LavaggioDemoContentSeeder extends Seeder
{
    public const SOURCE = 'lavaggio-demo-content-v1';

    /** Fictional examples for the local preview; no customer or purchase is attached. */
    public const REVIEWS = [
        ['8700216908795', 'Ana', 5, 'Veliko pakiranje nam dugo traje. Rublje je uredno oprano, a miris je ugodan i nakon sušenja.'],
        ['9000101566789', 'Marko', 4, 'Lako se dozira i dobro ispire. Miris je blag, što mi odgovara za svakodnevno rublje.'],
        ['9000101805017', 'Petra', 5, 'Dobar izbor za naše redovito pranje. Prašak se lijepo otopi i odjeća bude svježa.'],
        ['9000101809527', 'Ivan', 5, 'Koristim ga za tamne majice i hlače. Ugodan miris i praktično pakiranje za doziranje.'],
        ['9000101808568', 'Maja', 4, 'Šareno rublje izgleda uredno nakon pranja. Proizvod mi odgovara, samo bih voljela manju bocu za policu.'],
        ['8700216691550', 'Luka', 5, 'Odgovara mi za svakodnevno pranje ručnika i posteljine. Čep je praktičan za odmjeravanje količine.'],
        ['4009175968616', 'Iva', 5, 'Sviđa mi se blag miris i što nije potrebno puno proizvoda za jedno pranje.'],
        ['9000101568363', 'Filip', 4, 'Dobar tekući deterdžent za šareno rublje. Zadovoljan sam rezultatom, a boca bi mogla biti malo lakša.'],
        ['4001499923746', 'Sara', 5, 'Malo pakiranje dobro dođe uz perilicu. Lako nanesem sredstvo na ovratnik prije pranja.'],
        ['4001499923739', 'Dario', 4, 'Praktično za brzo tretiranje manjih mrlja. Za starije mrlje kod mene je trebalo ponoviti postupak.'],
        ['5997321744674', 'Nina', 5, 'Raspršivač je praktičan i jednostavno pogodim mjesto koje želim tretirati prije pranja.'],
        ['5997321747750', 'Tomislav', 4, 'Koristim ga povremeno uz bijelo rublje. Zadovoljan sam, uz obavezno praćenje uputa s pakiranja.'],
        ['4008455342313', 'Martina', 5, 'Dobro mi dođe za rubove rukava i ovratnike. Jednostavno nanošenje bez nepotrebnog prolijevanja.'],
        ['8700216723985', 'Josip', 5, 'Blag miris koji mi odgovara za posteljinu. Ručnici nakon sušenja budu ugodni na dodir.'],
        ['8700216724074', 'Klara', 4, 'Miris je svjež i lijepo se zadrži na odjeći. Meni je dovoljna malo manja količina.'],
        ['8700216708661', 'Ante', 4, 'Lijepo miriše, ali je za moj ukus intenzivno. S manjom količinom mi puno više odgovara.'],
        ['3045200765004', 'Lea', 5, 'Pakiranje je praktično i upute su jasne. Koristim prema deklaraciji kad perem sportsko rublje.'],
        ['4015200033662', 'Nikola', 5, 'Odgovara mi za pranje vunenih džempera na odgovarajućem programu. Miris je ugodan i nenametljiv.'],
        ['5997321700267', 'Ivana', 4, 'Jednostavno se dozira uz pranje i boca dobro sjeda na policu. Zadovoljna sam za sada.'],
        ['4023103126251', 'Mario', 5, 'Koristan za crne hlače prije izlaska iz kuće. Listići se lako odvajaju i valjak dobro leži u ruci.'],
        ['8700216908801', 'Ema', 5, 'Volim veće pakiranje jer često peremo. Praktično rješenje za obitelj i šareno rublje.'],
        ['8700216764193', 'Robert', 5, 'Kapsule su mi zgodne kad želim brzo pokrenuti pranje. Rublje bude svježe i uredno.'],
        ['9000101830880', 'Lucija', 4, 'Praktične kapsule za svakodnevno pranje. Miris mi odgovara, a pakiranje je jednostavno spremiti.'],
        ['5999109563200', 'Goran', 4, 'Gel se lako nanosi prije pranja. Kod tvrdokornijih mrlja rezultat ovisi o tome koliko dugo stoje.'],
        ['8700216724081', 'Dora', 5, 'Topao, ugodan miris koji posebno volim na posteljini. Pakiranje nam traje dosta dugo.'],
        ['4008455047614', 'Hrvoje', 5, 'Jednostavno za korištenje na praznoj perilici prema uputama. Dobro dođe za redovito održavanje.'],
        ['4001499939891', 'Marija', 5, 'Praktično jednokratno pakiranje za održavanje perilice. Upute su razumljive i postupak je jednostavan.'],
        ['4008838910290', 'Stjepan', 5, 'Mrežica mi je odlična za sitne komade rublja. Zatvarač se lako zatvori i sve ostane na jednom mjestu.'],
        ['4008455497815', 'Tea', 5, 'Maramice su praktične kao dodatak pranju. Najviše mi odgovara što je pakiranje tanko i lako ga spremim.'],
        ['4008455019017', 'Karlo', 3, 'Kugla je jednostavna za korištenje. Miris mi je dosta izražen pa sam prilagodio količinu prema uputama.'],
    ];

    public const PROMOTIONS = [
        '9000101566789' => 15,
        '9000101809527' => 20,
        '9000101568363' => 15,
        '5997321747750' => 25,
        '8700216724074' => 15,
        '5997321700267' => 20,
        '4008455047614' => 10,
        '4008838910290' => 15,
    ];

    public function run(): void
    {
        if (! app()->environment('local') || config('storefront.home') !== 'lavaggio'
            || DB::connection()->getDriverName() !== 'sqlite') {
            throw new RuntimeException('Demo content is restricted to the local Lavaggio SQLite preview.');
        }

        $barcodes = array_unique([...array_column(self::REVIEWS, 0), ...array_keys(self::PROMOTIONS)]);
        $products = Product::query()->whereIn('barcode', $barcodes)->where('is_active', true)
            ->where('payload->lavaggio_import->local_demo', true)->get()->keyBy('barcode');
        foreach ($barcodes as $barcode) {
            if (! $products->has($barcode)) {
                throw new RuntimeException('Missing active local demo product: '.$barcode);
            }
        }

        $reviewsCreated = 0;
        $actionsCreated = 0;
        DB::transaction(function () use ($products, &$reviewsCreated, &$actionsCreated): void {
            foreach (self::REVIEWS as $index => [$barcode, $name, $rating, $body]) {
                $key = self::SOURCE.'-review-'.str_pad((string) ($index + 1), 2, '0', STR_PAD_LEFT);
                // Preserve moderation, edits and even soft-deletions on repeated runs.
                if (Comment::withTrashed()->where('payload->lavaggio_demo_review', $key)->exists()) {
                    continue;
                }
                $product = $products->get($barcode);
                $reviewedAt = now()->subHours(($index + 1) * 9);
                $comment = new Comment([
                    'commentable_type' => $product->getMorphClass(), 'commentable_id' => $product->id,
                    'author_name' => $name, 'author_email' => 'review-'.($index + 1).'@lavaggio.example.test',
                    'locale' => 'hr', 'body' => $body, 'rating' => $rating,
                    'status' => Comment::STATUS_APPROVED, 'is_featured' => true, 'reviewed_at' => $reviewedAt,
                    'payload' => ['local_demo' => true, 'demo_source' => self::SOURCE, 'lavaggio_demo_review' => $key],
                ]);
                $comment->created_at = $reviewedAt;
                $comment->updated_at = $reviewedAt;
                $comment->save();
                $reviewsCreated++;
            }

            foreach (self::PROMOTIONS as $barcode => $discount) {
                $code = 'lavaggio-home-demo-'.$barcode;
                if (CatalogAction::query()->where('code', $code)->exists()) {
                    continue;
                }
                $action = CatalogAction::query()->create([
                    'code' => $code, 'scope' => CatalogAction::SCOPE_PRODUCT,
                    'type' => CatalogAction::TYPE_PERCENTAGE, 'discount_value' => $discount,
                    'target_type' => CatalogAction::TARGET_PRODUCT, 'audience_type' => CatalogAction::AUDIENCE_ALL,
                    'is_active' => true, 'starts_at' => now(), 'ends_at' => now()->addDays(14)->endOfDay(),
                    'payload' => ['local_demo' => true, 'demo_source' => self::SOURCE],
                ]);
                $action->translations()->create([
                    'locale' => 'hr', 'title' => 'Odabrana akcijska ponuda', 'badge' => '-'.$discount.'%',
                    'description' => 'Demo akcija za lokalni pregled Lavaggio trgovine.',
                ]);
                $action->targets()->create([
                    'target_type' => CatalogAction::TARGET_PRODUCT, 'target_id' => $products->get($barcode)->id,
                    'sort_order' => 0,
                ]);
                $actionsCreated++;
            }
        });

        ContentBlockResolver::bumpCacheVersion();
        app(GuestStorefrontCache::class)->invalidate();
        $this->command?->info('Created '.$reviewsCreated.' demo reviews and '.$actionsCreated.' demo promotions. Existing records were preserved.');
    }
}
