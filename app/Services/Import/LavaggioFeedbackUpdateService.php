<?php

namespace App\Services\Import;

use App\Models\Content\ContentBlockTranslation;
use App\Models\Content\Page\InfoPage;
use App\Models\Content\Support\Faq;
use App\Services\Front\NavigationMenuService;
use App\Services\Settings\SystemSettingsService;
use App\Support\Lavaggio\NovemberFeedback;
use App\Support\Lavaggio\PublicUrls;

/** Produces narrow, reviewable updates; never reimports an editor's content. */
class LavaggioFeedbackUpdateService
{
    public function plan(): array
    {
        $snapshot = json_decode(file_get_contents(database_path('data/lavaggio-content-20261028.json')), true, flags: JSON_THROW_ON_ERROR);
        $sources = collect($snapshot['resources'])->keyBy('id');
        $faqSources = collect($snapshot['faqs'])->keyBy('id');
        $pages = InfoPage::query()->with('translations')->get();
        $byCode = $pages->keyBy('code');
        $sourceUrls = [];
        foreach ($pages as $page) {
            if (preg_match('/^lavaggio-modx-(\d+)$/', $page->code, $match)) {
                $slug = $page->translations->firstWhere('locale', 'hr')?->slug;
                if ($slug) {
                    $sourceUrls[(int) $match[1]] = PublicUrls::url('/page/'.$slug);
                }
            }
        }
        $sourceUrls[2] = PublicUrls::url('/contact');
        $actions = $skipped = [];
        foreach ($pages as $page) {
            $sourceId = (int) preg_replace('/^lavaggio-modx-/', '', $page->code);
            if (in_array($sourceId, NovemberFeedback::RETIRED_PAGES, true) && str_starts_with($page->code, 'lavaggio-modx-') && $page->is_active) {
                $actions[] = $this->action($page, ['is_active' => false], 'Isključi stranicu: '.$page->code);
            }
            foreach ($page->translations as $translation) {
                $body = (string) $translation->body_html;
                $source = $sources->get($sourceId);
                if ($translation->locale === 'hr' && $source && in_array($sourceId, [80, 86, 174], true)) {
                    $replacement = NovemberFeedback::resource($source)['body_html'];
                    if ($this->plain($body) === $this->plain($source['body_html'])) {
                        $body = $this->sourceHtml($replacement, $sourceUrls);
                    } elseif ($this->plain($body) !== $this->plain($replacement) && preg_match('/samopos/iu', $body)) {
                        $skipped[] = 'Sačuvan uređeni sadržaj za ručni pregled: '.$page->code;
                    }
                }
                if ($sourceId === 88 && str_starts_with($page->code, 'lavaggio-modx-')) {
                    $body = str_replace('Cijene vrijede od: 01.06.2026', 'Cijene vrijede od: 10.09.2026', $body);
                }
                $body = $this->html($body);
                if ($body !== (string) $translation->body_html) {
                    $actions[] = $this->action($translation, ['body_html' => $body], 'Ažuriraj poveznice/sadržaj: '.$page->code.' ('.$translation->locale.')');
                }
                if ($translation->locale === 'hr' && $source && in_array($sourceId, [68, 80, 174], true)) {
                    $original = NovemberFeedback::metadataDefaults($source);
                    $replacement = NovemberFeedback::metadataDefaults(NovemberFeedback::resource($source));
                    $changes = [];
                    foreach (['excerpt', 'meta_title', 'meta_description'] as $field) {
                        if ((string) $translation->{$field} === (string) $original[$field] && (string) $original[$field] !== (string) $replacement[$field]) {
                            $changes[$field] = $replacement[$field];
                        }
                    }
                    if ($changes) {
                        $actions[] = $this->action($translation, $changes, 'Uskladi izvorni sažetak/SEO: '.$page->code);
                    }
                }
            }
        }
        foreach (Faq::query()->with('translations')->where('code', 'like', 'lavaggio-modx-faq-%')->get() as $faq) {
            $sourceId = substr($faq->code, strlen('lavaggio-modx-faq-'));
            if (in_array($sourceId, NovemberFeedback::RETIRED_FAQS, true) && $faq->is_active) {
                $actions[] = $this->action($faq, ['is_active' => false], 'Isključi samoposlužno pitanje: '.$sourceId);
            }
            foreach ($faq->translations as $translation) {
                $body = (string) $translation->answer_html;
                $replacement = NovemberFeedback::faqAnswer($sourceId);
                $source = $faqSources->get($sourceId);
                if ($translation->locale === 'hr' && $replacement && $source) {
                    if ($this->plain($body) === $this->plain($source['answer_html'])) {
                        $body = $this->sourceHtml($replacement, $sourceUrls);
                    } elseif ($this->plain($body) !== $this->plain($replacement) && preg_match('/samopos/iu', $body)) {
                        $skipped[] = 'Sačuvan uređeni FAQ odgovor za ručni pregled: '.$sourceId;
                    }
                }
                $body = $this->html($body);
                if ($body !== (string) $translation->answer_html) {
                    $actions[] = $this->action($translation, ['answer_html' => $body], 'Uskladi FAQ odgovor: '.$sourceId);
                }
            }
        }
        foreach (\App\Models\Content\Blog\BlogPostTranslation::query()->get() as $translation) {
            $body = $this->html((string) $translation->body_html, false);
            if ($body !== (string) $translation->body_html) {
                $actions[] = $this->action($translation, ['body_html' => $body], 'Uskladi poveznice bloga #'.$translation->post_id);
            }
        }
        $settings = app(SystemSettingsService::class);
        foreach ($settings->all() as $key => $value) {
            if (! preg_match('/^(?:front_navigation_|lavaggio_|store_footer_|store_announcement_)/', $key)) {
                continue;
            }
            $updated = $key === NavigationMenuService::SETTINGS_KEY
                ? $this->navigation((array) $value, $byCode) : $this->value($value);
            if ($updated !== $value) {
                $actions[] = ['setting' => $key, 'value' => $updated, 'description' => 'Uskladi postavku/poveznice: '.$key];
            }
        }
        if (trim((string) $settings->get('store_footer_hours', '')) === '') {
            $actions[] = ['setting' => 'store_footer_hours', 'value' => 'Pon-Pet: 08h - 16h', 'description' => 'Dodaj radno vrijeme uz telefon'];
        }
        foreach (ContentBlockTranslation::query()->get() as $translation) {
            $changes = [];
            foreach (['cta_url', 'body_html', 'payload'] as $field) {
                $current = $translation->{$field};
                $updated = $field === 'body_html' ? $this->html((string) $current) : $this->value($current);
                if ($updated !== $current && ! ($field === 'body_html' && $current === null && $updated === '')) {
                    $changes[$field] = $updated;
                }
            }
            if ($changes) {
                $actions[] = $this->action($translation, $changes, 'Uskladi poveznice bloka #'.$translation->content_block_id);
            }
        }

        return ['actions' => $actions, 'skipped' => array_values(array_unique($skipped))];
    }

    public function apply(array $plan): void
    {
        foreach ($plan['actions'] as $action) {
            if (isset($action['setting'])) {
                app(SystemSettingsService::class)->put($action['setting'], $action['value']);
            } else {
                $action['model']::query()->findOrFail($action['id'])->update($action['changes']);
            }
        }
    }

    private function action($model, array $changes, string $description): array
    {
        return ['model' => get_class($model), 'id' => $model->getKey(), 'changes' => $changes, 'description' => $description];
    }

    private function navigation(array $items, $pages): array
    {
        $retiredIds = $pages->filter(fn ($page) => in_array($page->code, array_map(fn ($id) => 'lavaggio-modx-'.$id, NovemberFeedback::RETIRED_PAGES), true))->pluck('id')->all();
        $oldPricing = $pages->get('lavaggio-modx-70')?->id;
        $newPricing = $pages->get('lavaggio-modx-88')?->id;
        $result = [];
        foreach ($items as $item) {
            if (($item['type'] ?? '') === 'page' && $oldPricing && $newPricing && (int) ($item['page_id'] ?? 0) === $oldPricing) {
                $item['page_id'] = $newPricing;
                $item['children'] = [];
                $item['label'] = 'Cjenik';
                $item['label_translations']['hr'] = 'Cjenik';
            } elseif (($item['type'] ?? '') === 'page' && in_array((int) ($item['page_id'] ?? 0), $retiredIds, true)) {
                continue;
            }
            if (isset($item['children'])) {
                $item['children'] = $this->navigation($item['children'], $pages);
            }
            $updated = $this->value($item);
            if ($updated !== null) {
                $result[] = $updated;
            }
        }

        return $result;
    }

    private function value(mixed $value): mixed
    {
        if (is_array($value)) {
            if (isset($value['url']) && is_string($value['url']) && PublicUrls::retiredUrl($value['url'])) {
                return null;
            }
            $updated = [];
            foreach ($value as $key => $item) {
                $next = $this->value($item);
                if ($next !== null || $item === null) {
                    $updated[$key] = $next;
                }
            }

            return array_is_list($value) ? array_values($updated) : $updated;
        }
        if (! is_string($value)) {
            return $value;
        }
        if (str_contains($value, '|')) {
            return implode("\n", array_values(array_filter(array_map(function ($line) {
                $parts = explode('|', $line, 2);
                if (count($parts) === 2 && preg_match('#^(?:/|https?://)#i', trim($parts[1]))) {
                    if (PublicUrls::retiredUrl(trim($parts[1]))) {
                        return null;
                    }

                    return $parts[0].'|'.PublicUrls::url(trim($parts[1]));
                }

                return $line;
            }, explode("\n", $value)), fn ($line) => $line !== null)));
        }

        return preg_match('#^(?:/|https?://)#', $value) ? PublicUrls::url($value) : $value;
    }

    public function html(string $html, bool $removeRetiredText = true): string
    {
        $html = preg_replace_callback('#<a\b[^>]*\bhref=(["\'])(.*?)\1[^>]*>.*?</a>#isu', function (array $match) use ($removeRetiredText): string {
            $url = html_entity_decode($match[2], ENT_QUOTES | ENT_HTML5, 'UTF-8');
            if (PublicUrls::retiredUrl($url)) {
                return $removeRetiredText ? '' : preg_replace('#^<a\b[^>]*>|</a>$#isu', '', $match[0]);
            }
            $updated = PublicUrls::url($url);

            return $updated === $url ? $match[0] : str_replace($match[1].$match[2].$match[1], $match[1].htmlspecialchars($updated, ENT_QUOTES | ENT_HTML5, 'UTF-8').$match[1], $match[0]);
        }, $html);

        return preg_replace('#<li\b[^>]*>\s*</li>#isu', '', $html);
    }

    private function sourceHtml(string $html, array $urls): string
    {
        return $this->html(preg_replace_callback('/\[\[~(\d+)\]\]/', fn ($match) => $urls[(int) $match[1]] ?? '#', $html));
    }

    private function plain(string $html): string
    {
        return trim(preg_replace('/\s+/u', ' ', html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8')));
    }
}
