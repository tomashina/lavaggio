<?php

namespace Database\Seeders;

use App\Models\Catalog\Category\Category;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class LavaggioCatalogSeeder extends Seeder
{
    public function run(): void
    {
        DB::transaction(function (): void {
            foreach (self::categories() as $index => $definition) {
                $parent = $this->saveCategory($definition, null, $index);
                foreach ($definition['children'] as $childIndex => $child) {
                    $this->saveCategory($child, $parent, $childIndex);
                }
            }
        });

        $this->call(LavaggioNavigationImagesSeeder::class);
    }

    private function saveCategory(array $definition, ?Category $parent, int $sortOrder): Category
    {
        $slug = Str::slug($definition['name']);
        $category = Category::query()->updateOrCreate([
            'scope' => Category::SCOPE_CATALOG,
            'code' => 'lavaggio-'.$slug,
        ], [
            'parent_id' => $parent?->id,
            'is_active' => true,
            'show_in_menu' => true,
            'sort_order' => $sortOrder,
        ]);
        $category->translations()->updateOrCreate(['locale' => 'hr'], [
            'scope' => Category::SCOPE_CATALOG,
            'name' => $definition['name'],
            'slug' => $slug,
            'description' => $definition['description'],
            'meta_title' => $definition['name'].' | Lavaggio web shop',
            'meta_description' => $definition['description'],
        ]);

        return $category;
    }

    /** @return array<int, array{name:string, description:string, children:array}> */
    public static function categories(): array
    {
        return [
            [
                'name' => 'Deterdženti',
                'description' => 'Deterdženti za pranje rublja u perilici i ručno pranje. Pronađite tekuće deterdžente, gelove i praškove za bijelo, šareno, crno i osjetljivo rublje te svakodnevno održavanje svih vrsta tkanina.',
                'children' => [
                    [
                        'name' => 'Univerzalni',
                        'description' => 'Univerzalni deterdženti za svakodnevno pranje bijelog i šarenog rublja. Tekući i praškasti deterdženti za učinkovito uklanjanje prljavštine i mrlja na različitim temperaturama pranja.',
                    ],
                    [
                        'name' => 'Bijelo rublje',
                        'description' => 'Deterdženti za bijelo rublje namijenjeni uklanjanju mrlja, održavanju bjeline i sprječavanju sivila tkanina. Odaberite sredstva za pranje bijele odjeće, ručnika i posteljine.',
                    ],
                    [
                        'name' => 'Šareno rublje',
                        'description' => 'Deterdženti za šareno rublje koji učinkovito uklanjaju nečistoće i pomažu očuvati intenzitet boja. Za svakodnevno pranje obojene odjeće i tekstila.',
                    ],
                    [
                        'name' => 'Crno i tamno rublje',
                        'description' => 'Specijalizirani deterdženti za crno i tamno rublje koji pomažu očuvati tamne nijanse i smanjiti blijeđenje boja tijekom pranja.',
                    ],
                    [
                        'name' => 'Vuna, svila i osjetljive tkanine',
                        'description' => 'Blagi deterdženti za pranje vune, svile i osjetljivih tkanina. Sredstva prilagođena njezi osjetljivih vlakana, za ručno i strojno pranje prema uputama na odjeći.',
                    ],
                ],
            ],
            [
                'name' => 'Uklanjanje mrlja',
                'description' => 'Sredstva za uklanjanje mrlja s odjeće, posteljine i drugih tekstilnih proizvoda. Otkrijte sprejeve za predtretman, odstranjivače tvrdokornih mrlja i pojačivače učinkovitosti pranja.',
                'children' => [
                    [
                        'name' => 'Predtretman',
                        'description' => 'Sprejevi, gelovi i sredstva za predtretman rublja prije pranja. Za ciljano uklanjanje masnih, proteinskih i drugih tvrdokornih mrlja s tkanina.',
                    ],
                    [
                        'name' => 'Odstranjivači mrlja',
                        'description' => 'Namjenska sredstva za uklanjanje specifičnih mrlja od tinte, hrđe, masnoće, krvi, vina i drugih nečistoća. Rješenja za zahtjevne mrlje koje obični deterdženti teško uklanjaju.',
                    ],
                    [
                        'name' => 'Pojačivači pranja (OXI i ostali)',
                        'description' => 'OXI sredstva, aktivni kisik i drugi pojačivači pranja koji povećavaju učinkovitost deterdženta. Za uklanjanje tvrdokornih mrlja i temeljitije pranje bijelog i šarenog rublja.',
                    ],
                ],
            ],
            [
                'name' => 'Mirisi i njega rublja',
                'description' => 'Proizvodi za miris, svježinu i njegu rublja nakon i tijekom pranja. Istražite omekšivače, parfeme za rublje i sredstva za neutralizaciju neugodnih mirisa iz tkanina.',
                'children' => [
                    [
                        'name' => 'Omekšivači rublja',
                        'description' => 'Omekšivači za ugodan miris, mekoću i njegu tkanina. Odaberite omekšivače za svakodnevno pranje odjeće, posteljine i drugih prikladnih tekstilnih proizvoda.',
                    ],
                    [
                        'name' => 'Parfemi i mirisi za rublje',
                        'description' => 'Koncentrirani parfemi i mirisi za rublje koji pružaju dodatnu mirisnu svježinu nakon pranja. Mirisni dodaci za perilicu i drugi proizvodi namijenjeni parfemiranju tekstila.',
                    ],
                    [
                        'name' => 'Neutralizatori neugodnih mirisa',
                        'description' => 'Sredstva za uklanjanje i neutralizaciju neugodnih mirisa iz odjeće i tekstila. Za mirise znoja, vlage, kućnih ljubimaca, dima i druge postojane neugodne mirise.',
                    ],
                ],
            ],
            [
                'name' => 'Higijena i održavanje',
                'description' => 'Proizvodi za higijensko pranje rublja te čišćenje i održavanje perilica. Otkrijte higijenske dodatke za pranje, sredstva protiv neugodnih mirisa i proizvode za uklanjanje naslaga u perilici.',
                'children' => [
                    [
                        'name' => 'Higijenski dodaci',
                        'description' => 'Higijenski dodaci za pranje odjeće, ručnika, posteljine i drugih tkanina. Sredstva za dodatnu higijensku obradu rublja, uključujući proizvode s dokazanim dezinfekcijskim učinkom.',
                    ],
                    [
                        'name' => 'Čišćenje i održavanje perilice',
                        'description' => 'Sredstva za čišćenje perilica rublja, uklanjanje kamenca, naslaga deterdženta i neugodnih mirisa. Tablete, prašci i tekuća sredstva za redovito održavanje perilice.',
                    ],
                ],
            ],
            [
                'name' => 'Pribor i dodaci za rublje',
                'description' => 'Praktičan pribor za pranje, sušenje, zaštitu i održavanje odjeće. Pronađite vrećice za pranje, kuglice za sušilicu, odstranjivače mucica i druge korisne dodatke za njegu rublja.',
                'children' => [
                    [
                        'name' => 'Vrećice i mrežice za pranje',
                        'description' => 'Zaštitne mrežice i vrećice za pranje osjetljive odjeće, donjeg rublja, tenisica i drugih tekstilnih proizvoda. Praktična zaštita tijekom strojnog pranja.',
                    ],
                    [
                        'name' => 'Kuglice i dodaci za sušilicu',
                        'description' => 'Vunene kuglice za sušilicu i drugi dodaci za učinkovitije sušenje rublja. Pribor koji pomaže razdvajanju odjeće tijekom sušenja i smanjenju gužvanja.',
                    ],
                    [
                        'name' => 'Odstranjivači mucica i dlačica',
                        'description' => 'Električni odstranjivači mucica, valjci za dlačice i četke za odjeću. Praktični proizvodi za uklanjanje dlačica, mucica i površinskih vlakana s odjeće i tekstila.',
                    ],
                    [
                        'name' => 'Ostali pribor za pranje i njegu rublja',
                        'description' => 'Korisni dodaci za organizaciju, zaštitu i održavanje rublja. Vreće za prljavo rublje, listići za hvatanje boja, pribor za doziranje i druga praktična pomagala.',
                    ],
                ],
            ],
        ];
    }
}
