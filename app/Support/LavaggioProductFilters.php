<?php

namespace App\Support;

use App\Models\Catalog\Attribute\Attribute;
use App\Models\Catalog\Attribute\AttributeGroup;
use App\Models\Catalog\Category\Category;
use App\Services\Settings\SystemSettingsService;
use Illuminate\Support\Str;

class LavaggioProductFilters
{
    public const GROUPS = [
        'problem' => ['Problem', ['Masnoća', 'Krv', 'Vino', 'Kava', 'Znoj', 'Neugodni mirisi', 'Neugodan miris ručnika', 'Sivilo bijelog rublja', 'Dlačice na odjeći']],
        'fabric' => ['Vrsta tkanine', ['Pamuk', 'Sintetika', 'Vuna', 'Svila', 'Osjetljive tkanine']],
        'purpose' => ['Namjena', ['Bijelo', 'Šareno', 'Crno', 'Univerzalno']],
        'form' => ['Oblik proizvoda', ['Prašak', 'Gel', 'Tekući deterdžent', 'Sprej', 'Tablete', 'Kapsule', 'Tekućina', 'Perlice', 'Pribor']],
        'package' => ['Veličina pakiranja', ['Kućno pakiranje', 'Profesionalno pakiranje']],
        'pro' => ['Profesionalna upotreba', ['PRO']],
    ];

    public static function groupCode(string $key): string
    {
        return 'lavaggio-'.$key;
    }

    public static function attributeCode(string $key, string $label): string
    {
        return self::groupCode($key).'-'.Str::slug($label);
    }

    /** Create editable attributes using the shop's existing filter system. */
    public static function seed(): void
    {
        foreach (self::GROUPS as $key => [$label, $values]) {
            $group = AttributeGroup::query()->firstOrCreate(['code' => self::groupCode($key)], [
                'type' => Attribute::TYPE_MULTI, 'sort_order' => array_search($key, array_keys(self::GROUPS)) * 10,
            ]);
            $group->translations()->updateOrCreate(['locale' => 'hr'], ['name' => $label]);
            foreach ($values as $index => $value) {
                $attribute = Attribute::query()->firstOrCreate(['code' => self::attributeCode($key, $value)], [
                    'attribute_group_id' => $group->id, 'group_code' => $group->code,
                    'type' => Attribute::TYPE_MULTI, 'is_active' => true, 'sort_order' => $index,
                ]);
                $attribute->translations()->updateOrCreate(['locale' => 'hr'], [
                    'group_name' => $label, 'name' => $value, 'slug' => Str::slug($value),
                ]);
            }
        }
        app(SystemSettingsService::class)->put('store_product_filter_attribute_group_codes', array_map(self::groupCode(...), array_keys(self::GROUPS)));
    }

    /** Links share precisely the same attributes as the catalogue filter controls. */
    public static function navigationLinks(?string $categoryUrl = null, ?string $categoryKey = null): array
    {
        $problems = [
            'lavaggio-uklanjanje-mrlja' => ['Mrlje od masnoće', 'Masnoća'],
            'lavaggio-mirisi-i-njega-rublja' => ['Neugodan miris ručnika', 'Neugodan miris ručnika'],
            'lavaggio-deterdzenti' => ['Sivo bijelo rublje', 'Sivilo bijelog rublja'],
            'lavaggio-pribor-i-dodaci-za-rublje' => ['Odjeća puna dlačica', 'Dlačice na odjeći'],
        ];
        $categoryCode = $categoryUrl ? 'lavaggio-'.basename((string) parse_url($categoryUrl, PHP_URL_PATH)) : null;
        if ($categoryKey && preg_match('/^category-(\d+)$/', $categoryKey, $match)) {
            $categories = request()->attributes->get('lavaggio.problem-navigation.categories');
            if ($categories === null) {
                $categories = Category::query()->where('scope', Category::SCOPE_CATALOG)->whereIn('code', array_keys($problems))->pluck('code', 'id')->all();
                request()->attributes->set('lavaggio.problem-navigation.categories', $categories);
            }
            $categoryCode = $categories[(int) $match[1]] ?? '';
        }
        $rows = request()->attributes->get('lavaggio.problem-navigation');
        if ($rows === null) {
            $rows = Attribute::query()->where('group_code', self::groupCode('problem'))->where('is_active', true)->pluck('id', 'code')->all();
            request()->attributes->set('lavaggio.problem-navigation', $rows);
        }
        $links = [];
        foreach ($problems as $category => [$label, $value]) {
            if ($categoryCode !== null && $categoryCode !== $category) {
                continue;
            }
            $id = $rows[self::attributeCode('problem', $value)] ?? null;
            if ($id) {
                $links[] = ['label' => $label, 'url' => route('shop.index', ['attr_lavaggio_problem' => $id])];
            }
        }

        return $links;
    }
}
