<?php

namespace App\Http\Controllers\Front;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Front\Concerns\ResolvesFrontendView;
use App\Models\Content\Page\InfoPage;
use App\Models\Content\Support\Faq;
use App\Services\Content\ContentBlockResolver;
use Illuminate\Http\Request;
use Illuminate\View\View;

class FaqController extends Controller
{
    use ResolvesFrontendView;

    public function index(Request $request): View
    {
        $locale = app()->getLocale();
        $fallbackLocale = (string) config('app.locale');
        $variant = $this->frontendVariant($request);

        $faqs = Faq::query()
            ->where('is_active', true)
            ->with(['translations' => fn ($q) => $q->whereIn('locale', [$locale, $fallbackLocale])])
            ->orderByDesc('is_featured')
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get();

        $faqPageTranslation = null;
        if (config('storefront.home') === 'lavaggio') {
            $faqPage = InfoPage::query()->where('code', 'lavaggio-modx-72')->where('is_active', true)
                ->where(fn ($query) => $query->whereNull('published_at')->orWhere('published_at', '<=', now()))
                ->with(['translations' => fn ($query) => $query->whereIn('locale', [$locale, $fallbackLocale])])
                ->first();
            $faqPageTranslation = $faqPage?->translations->firstWhere('locale', $locale)
                ?? $faqPage?->translations->firstWhere('locale', $fallbackLocale);
        }

        $topBlocks = app(ContentBlockResolver::class)->forPlacement(
            placement: 'faq.top',
            locale: $locale,
            targetType: 'faq',
            targetRef: null,
            frontendVariant: $variant
        );

        $bottomBlocks = app(ContentBlockResolver::class)->forPlacement(
            placement: 'faq.bottom',
            locale: $locale,
            targetType: 'faq',
            targetRef: null,
            frontendVariant: $variant
        );

        return view($this->frontendView($request, 'faq.index'), [
            'faqs' => $faqs,
            'faqPageTranslation' => $faqPageTranslation,
            'topBlocks' => $topBlocks,
            'bottomBlocks' => $bottomBlocks,
            'locale' => $locale,
            'fallbackLocale' => $fallbackLocale,
        ]);
    }
}
