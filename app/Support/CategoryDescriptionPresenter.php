<?php

namespace App\Support;

use App\Models\Catalog\Category\CategoryTranslation;
use Illuminate\Support\Str;

class CategoryDescriptionPresenter
{
    public function __construct(private readonly ImportedDescriptionHtmlCleaner $cleaner) {}

    /** @return array{short: string, long: string} */
    public function forTranslation(?CategoryTranslation $translation): array
    {
        if (! $translation) {
            return ['short' => '', 'long' => ''];
        }

        $short = $this->cleaner->clean($translation->short_description);
        $long = $this->cleaner->clean((string) $translation->description);
        $short = $this->hasContent($short) ? $short : '';
        $long = $this->hasContent($long) ? $long : '';

        if ($short !== '' && $long !== '' && ($short === $long
            || ($this->text($short) !== '' && $this->text($short) === $this->text($long)))) {
            $short = '';
        }

        return ['short' => $short, 'long' => $long];
    }

    private function hasContent(string $html): bool
    {
        return $this->text($html) !== '' || (bool) preg_match('/<img\b[^>]*\bsrc\s*=/i', $html);
    }

    private function text(string $html): string
    {
        return (string) Str::of(html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8'))->squish();
    }
}
