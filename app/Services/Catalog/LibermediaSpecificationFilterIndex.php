<?php

namespace App\Services\Catalog;

use App\Models\Catalog\Attribute\Attribute;
use App\Models\Catalog\Attribute\AttributeGroup;
use App\Models\Catalog\Product\Product;
use App\Support\ProductSpecificationPresenter;
use App\Support\StorefrontTheme;
use DOMDocument;
use DOMXPath;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** Search facets derived only from explicitly labelled product facts, never marketing prose. */
class LibermediaSpecificationFilterIndex
{
    public const SOURCE = 'libermedia-explicit-specifications';

    public const REVISION_KEY = 'libermedia:specification-filter-revision';

    public const GROUPS = ['material' => 'Materijal', 'age' => 'Dob', 'color' => 'Boja', 'dimensions' => 'Dimenzije', 'pieces' => 'Broj komada u pakiranju'];

    private array $valueIds = [];

    public function enabled(): bool
    {
        return config('storefront.home') !== 'watchline' && StorefrontTheme::usesLibermediaBrand();
    }

    public static function groupCodes(): array
    {
        return array_map(fn ($key) => 'libermedia-spec-'.$key, array_keys(self::GROUPS));
    }

    public static function invalidate(): void
    {
        $invalidate = static fn () => Cache::forever(self::REVISION_KEY, Str::random(16));
        $invalidate();
        if (DB::connection()->transactionLevel() > 0) {
            // A concurrent reader may still see pre-commit relations after the
            // first invalidation; the committed snapshot needs its own revision.
            DB::afterCommit($invalidate);
        }
    }

    /** @return array<int,array{group:string,label:string,value:string,source_label:string,source_value:string,origin:string}> */
    public function facts(Product $product): array
    {
        $product->loadMissing(['translations', 'technicalSpecificationRows']);
        $candidates = [];
        $translation = $product->translations->firstWhere('locale', 'hr') ?? $product->translations->firstWhere('locale', config('app.locale'));
        if ($translation && trim((string) $translation->description) !== '') {
            $html = (string) $translation->description;
            if (strlen($html) < 1000000) {
                $dom = new DOMDocument('1.0', 'UTF-8');
                $previous = libxml_use_internal_errors(true);
                $dom->loadHTML('<?xml encoding="UTF-8"><div>'.$html.'</div>', LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING);
                libxml_clear_errors();
                libxml_use_internal_errors($previous);
                $xpath = new DOMXPath($dom);
                foreach ($xpath->query('//tr') as $row) {
                    $cells = $xpath->query('./th | ./td', $row);
                    if ($cells->length === 2) {
                        $candidates[] = [$cells->item(0)->textContent, $cells->item(1)->textContent, 'description:'.$translation->id];
                    }
                }
                $text = strip_tags(preg_replace('~<br\s*/?>|</(?:p|li|div|h[1-6]|tr|table|ul|ol)>~i', "\n", $html));
                foreach (preg_split('/\R/u', $text) ?: [] as $line) {
                    if (preg_match('/^\s*([^:\n]{1,70}):\s*(.{1,500})$/u', $this->text($line), $match)) {
                        $candidates[] = [$match[1], $match[2], 'description:'.$translation->id];
                    }
                }
            }
        }
        foreach ($product->technicalSpecificationRows as $row) {
            $candidates[] = [$row->item_name, app(ProductSpecificationPresenter::class)->valueText($row), 'specification:'.$row->id];
        }
        $facts = [];
        foreach ($candidates as [$label, $value, $origin]) {
            $label = rtrim($this->text($label), ': ');
            $value = $this->text($value);
            $key = Str::lower(Str::ascii($label));
            $group = match (true) {
                in_array($key, ['materijal', 'materijal izrade', 'material', 'sastav'], true) => 'material',
                in_array($key, ['dob', 'preporucena dob', 'dobna skupina', 'uzrast', 'age', 'dob djeteta'], true) => 'age',
                in_array($key, ['boja', 'boje', 'color', 'colour'], true) => 'color',
                in_array($key, ['dimenzije', 'dimenzije proizvoda', 'dimensions', 'velicina'], true) => 'dimensions',
                (bool) preg_match('/^broj (?:komada|elemenata|dijelova|kocki|kockica)(?: u (?:setu|pakiranju))?$/', $key) => 'pieces',
                default => null,
            };
            if (! $group || $value === '') {
                continue;
            }
            foreach ($this->normalizeValues($group, $value) as $normalized) {
                $facts[$group.':'.$normalized] = ['group' => $group, 'label' => self::GROUPS[$group], 'value' => $normalized, 'source_label' => $label, 'source_value' => $value, 'origin' => $origin];
            }
        }
        ksort($facts);

        return array_values($facts);
    }

    public function sync(Product $product): bool
    {
        if (! $this->enabled()) {
            return false;
        }
        $facts = $this->facts($product);
        $fingerprint = hash('sha256', json_encode($facts, JSON_UNESCAPED_UNICODE));
        $changed = DB::transaction(function () use ($product, $facts, $fingerprint): bool {
            $locked = Product::query()->lockForUpdate()->find($product->id);
            if (! $locked) {
                return false;
            }
            $payload = (array) $locked->payload;
            $currentIds = $locked->attributes()->where('catalog_attributes.payload->source', self::SOURCE)->pluck('catalog_attributes.id')->map(fn ($id) => (int) $id)->all();
            $desired = [];
            foreach ($facts as $fact) {
                $desired[] = $this->valueId($fact);
            }
            $desired = array_values(array_unique($desired));
            sort($desired);
            sort($currentIds);
            if ($desired === $currentIds && (data_get($payload, 'specification_filter_index.hash') === $fingerprint || ($facts === [] && ! isset($payload['specification_filter_index'])))) {
                return false;
            }
            $remove = array_diff($currentIds, $desired);
            $add = array_diff($desired, $currentIds);
            if ($remove !== []) {
                $locked->attributes()->detach($remove);
            }
            if ($add !== []) {
                $locked->attributes()->attach($add, ['sort_order' => 0]);
            }
            $payload['specification_filter_index'] = ['source' => self::SOURCE, 'version' => 1, 'hash' => $fingerprint, 'facts' => $facts];
            DB::table('products')->where('id', $locked->id)->update(['payload' => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)]);

            return true;
        });
        if ($changed) {
            self::invalidate();
        }

        return $changed;
    }

    private function valueId(array $fact): int
    {
        $code = 'libermedia-spec-value-'.hash('sha256', $fact['group'].':'.$fact['value']);
        if (isset($this->valueIds[$code])) {
            return $this->valueIds[$code];
        }
        $groupCode = 'libermedia-spec-'.$fact['group'];
        $group = AttributeGroup::query()->firstOrCreate(['code' => $groupCode], ['type' => Attribute::TYPE_SELECT, 'sort_order' => array_search($fact['group'], array_keys(self::GROUPS)), 'payload' => ['source' => self::SOURCE]]);
        $groupTranslation = $group->translations()->firstOrCreate(['locale' => 'hr'], ['name' => $fact['label']]);
        $value = Attribute::query()->firstOrCreate(['code' => $code], ['attribute_group_id' => $group->id, 'group_code' => $groupCode, 'type' => Attribute::TYPE_SELECT, 'is_active' => true, 'payload' => ['source' => self::SOURCE, 'filter_only' => true]]);
        $valueTranslation = $value->translations()->firstOrCreate(['locale' => 'hr'], ['group_name' => $fact['label'], 'name' => $fact['value'], 'slug' => $code]);
        if ($fact['group'] === 'pieces') {
            // Keep the numeric identity and filter URLs stable; clarify only the display.
            $groupTranslation->fill(['name' => $fact['label']]);
            $valueTranslation->fill(['group_name' => $fact['label'], 'name' => $fact['value'].' kom.']);
            if ($groupTranslation->isDirty() || $valueTranslation->isDirty()) {
                $groupTranslation->save();
                $valueTranslation->save();
                self::invalidate();
            }
        }

        return $this->valueIds[$code] = (int) $value->id;
    }

    private function normalizeValues(string $group, string $value): array
    {
        $plain = Str::lower(Str::ascii($value));
        if ($group === 'material') {
            if (preg_match('/\b(?:bez|ne sadrzi|nije)\b/', $plain)) {
                return [];
            }
            $values = [];
            foreach (['plastik' => 'Plastika', 'drvo|drven' => 'Drvo', 'silikon' => 'Silikon', 'gum(?:a|en)' => 'Guma', 'metal' => 'Metal', 'karton' => 'Karton', 'papir' => 'Papir', 'pamuk|pamuc' => 'Pamuk', 'poliester' => 'Poliester', 'filc' => 'Filc', 'pjen' => 'Pjena', 'polipropilen' => 'Polipropilen', 'polietilen' => 'Polietilen', 'tekstil' => 'Tekstil'] as $pattern => $label) {
                if (preg_match('/\b(?:'.$pattern.')/', $plain)) {
                    $values[] = $label;
                }
            }

            return $values;
        }
        if ($group === 'age') {
            if (preg_match('/(?<![\d.,])(?:od\s*)?(\d{1,2})\s*(?:do|[-–])\s*(\d{1,2})\s*(godin\w*|mjesec\w*)/u', $plain, $m) && (int) $m[1] <= (int) $m[2]) {
                return [$m[1].'–'.$m[2].' '.(str_starts_with($m[3], 'mjesec') ? 'mjeseci' : 'godina')];
            }
            if (preg_match('/(?:od\s+)(\d{1,2})\s*\+?\s*(godin\w*|mjesec\w*)|(?<![\d.,])(\d{1,2})\s*\+\s*(godin\w*|mjesec\w*)/u', $plain, $m)) {
                $number = $m[1] ?: ($m[3] ?? '');
                $unit = $m[2] ?: ($m[4] ?? '');

                return [$number.'+ '.(str_starts_with($unit, 'mjesec') ? 'mjeseci' : 'godina')];
            }
            if (preg_match('/^(\d{1,2})\s*(godin\w*|mjesec\w*)\.?$/', $plain, $m)) {
                return [$m[1].' '.(str_starts_with($m[2], 'mjesec') ? 'mjeseci' : 'godina')];
            }

            return [];
        }
        if ($group === 'color') {
            if (preg_match('/vis?ebojn|raznobojn|saren/', $plain)) {
                return ['Višebojno'];
            }
            $values = [];
            foreach (['bijel' => 'Bijela', 'crn' => 'Crna', 'crven' => 'Crvena', 'plav' => 'Plava', 'zelen' => 'Zelena', 'zut' => 'Žuta', 'narancast' => 'Narančasta', 'ruzicast|roza' => 'Ružičasta', 'ljubicast' => 'Ljubičasta', 'siv' => 'Siva', 'smed' => 'Smeđa', 'prozir' => 'Prozirna'] as $pattern => $label) {
                if (preg_match('/\b(?:'.$pattern.')/', $plain)) {
                    $values[] = $label;
                }
            }
            if (preg_match('/\bbež(?:\b|\w*)/iu', $value)) {
                $values[] = 'Bež';
            }

            return $values;
        }
        if ($group === 'dimensions' && strlen($value) <= 80 && preg_match('/^(?:(?:cca\.?|oko)\s*)?\d[\d\s,.x×*\/\-–]*(?:mm|cm|m)(?:\b|\s)/u', $plain) && ! str_contains($plain, '. ')) {
            return [trim(preg_replace('/\s*[x×*]\s*/u', ' × ', rtrim($value, '. ')))];
        }
        if ($group === 'pieces' && preg_match('/^(\d{1,4})\s*(?:kom\.?|komada|dijelova|elemenata|kocki|kockica)?\.?$/u', $plain, $m) && (int) $m[1] > 0) {
            return [(string) ((int) $m[1])];
        }

        return [];
    }

    private function text(string $text): string
    {
        return trim(preg_replace('/\s+/u', ' ', html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8')));
    }
}
