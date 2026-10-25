@php
    $watchlineSeo = config('storefront.home') === 'watchline';
    $seoErrorPage = $watchlineSeo && isset($exception) && $exception instanceof \Symfony\Component\HttpKernel\Exception\HttpExceptionInterface && $exception->getStatusCode() >= 400;
    $seoSettings = $storeSettings['seo'] ?? [];
    $ogSettings = $storeSettings['og'] ?? [];
    $brandSettings = $storeSettings['branding'] ?? [];

    $locale = (string) ($locale ?? app()->getLocale());
    $fallbackLocale = (string) ($fallbackLocale ?? config('app.locale'));

    $cleanupText = static function (mixed $value, int $limit = 320) use ($watchlineSeo): string {
        $plain = trim(strip_tags(html_entity_decode((string) $value, ENT_QUOTES | ENT_HTML5, 'UTF-8')));
        if ($plain === '') {
            return '';
        }

        $plain = preg_replace('/\s+/u', ' ', $plain) ?: $plain;

        return \Illuminate\Support\Str::limit($plain, $limit, '', $watchlineSeo);
    };

    $defaultTitle = trim((string) ($seoSettings['default_title'] ?? ''));
    $defaultDescription = $cleanupText($seoSettings['default_description'] ?? '', 320);
    $sectionTitle = trim((string) \Illuminate\Support\Facades\View::yieldContent('title'));

    $title = $sectionTitle !== ''
        ? $sectionTitle
        : ($defaultTitle !== '' ? $defaultTitle : (string) config('app.name', 'AG Shop'));
    $description = $defaultDescription;
    $robotsOverride = trim((string) \Illuminate\Support\Facades\View::yieldContent('robots'));
    $robots = $robotsOverride !== ''
        ? $robotsOverride
        : trim((string) ($seoSettings['robots'] ?? 'index,follow'));
    $canonicalPolicy = (string) ($seoSettings['canonical_policy'] ?? 'self');
    $canonicalUrl = $canonicalPolicy === 'self' ? url()->current() : '';

    $siteName = trim((string) ($brandSettings['store_name'] ?? ''));
    if ($siteName === '') {
        $siteName = (string) config('app.name', 'AG Shop');
    }
    if ($defaultDescription === '') {
        $defaultDescription = $cleanupText(
            (string) __('ui.seo.default_description', ['store' => $siteName]),
            320
        );
        if ($description === '') {
            $description = $defaultDescription;
        }
    }

    $ogType = 'website';
    $ogImage = (string) ($ogSettings['default_image_url'] ?? '');

    if ($watchlineSeo) {
        $staticMeta = [
            'shop.index' => ['Satovi, naočale i nakit – Watchline', 'Istražite Watchline ponudu satova, sunčanih naočala i nakita.'],
            'categories.index' => ['Kategorije – Watchline', 'Pregledajte kategorije satova, sunčanih naočala i nakita iz Watchline ponude.'],
            'manufacturers.index' => ['Brendovi – Watchline', 'Pregledajte brandove u Watchline ponudi i istražite njihove artikle.'],
            'blog.index' => ['Blog – Watchline', 'Novosti i članci iz Watchline ponude satova, sunčanih naočala i nakita.'],
            'contact.create' => ['Kontakt – Watchline', 'Kontaktirajte Watchline za informacije o proizvodima, narudžbama, trgovini i servisu.'],
            'reviews.index' => ['Iskustva kupaca – Watchline', 'Pročitajte iskustva i ocjene kupaca Watchline proizvoda.'],
            'faq.index' => ['Česta pitanja – Watchline', 'Pronađite odgovore na česta pitanja o kupnji i Watchline ponudi.'],
        ];
        $routeMeta = $staticMeta[request()->route()?->getName()] ?? null;
        if ($routeMeta) { [$title, $description] = $routeMeta; }
    }

    if (request()->routeIs('home')) {
        $title = $defaultTitle !== '' ? $defaultTitle : $title;
        $description = $defaultDescription;
        $ogImage = (string) ($ogSettings['home_image_url'] ?? $ogImage);
    }

    if (request()->routeIs('categories.show') && isset($category)) {
        $categoryTranslation = $category->translations->firstWhere('locale', $locale)
            ?? $category->translations->firstWhere('locale', $fallbackLocale);
        $title = $cleanupText($categoryTranslation?->meta_title ?: $categoryTranslation?->name ?: $title, 191);
        $description = $cleanupText($categoryTranslation?->meta_description ?: $categoryTranslation?->description ?: ($watchlineSeo && $categoryTranslation?->name ? 'Istražite kategoriju '.$categoryTranslation->name.' u Watchline ponudi.' : $description), 320);

        if (trim((string) ($ogSettings['category_image_url'] ?? '')) !== '') {
            $ogImage = (string) $ogSettings['category_image_url'];
        } elseif (method_exists($category, 'getFirstMediaUrl')) {
            $categoryImage = (string) \App\Support\Media\LegacyCatalogImage::first($category);
            if ($categoryImage !== '') {
                $ogImage = $categoryImage;
            }
        }
    }

    if ($watchlineSeo && request()->routeIs('manufacturers.show') && isset($manufacturer)) {
        $manufacturerTranslation = $manufacturer->translations->firstWhere('locale', $locale)
            ?? $manufacturer->translations->firstWhere('locale', $fallbackLocale);
        $title = $cleanupText($manufacturerTranslation?->meta_title ?: $manufacturerTranslation?->name ?: $title, 191);
        $description = $cleanupText($manufacturerTranslation?->meta_description ?: $manufacturerTranslation?->description ?: ($manufacturerTranslation?->name ? 'Istražite '.$manufacturerTranslation->name.' artikle u Watchline ponudi.' : $description), 320);
        $manufacturerImage = \App\Support\Media\ManufacturerLogo::resolve($manufacturer)['url'] ?? '';
        if ($manufacturerImage !== '') { $ogImage = $manufacturerImage; }
    }
    if ($watchlineSeo && request()->routeIs('gift-vouchers.create')) {
        $description = 'Poklon bon Watchline za satove, naočale i nakit. Prepustite odabir onome kome darujete.';
    }
    if ($watchlineSeo && request()->routeIs('gift-guide.index')) {
        $title = 'Tražiš poklon? – Watchline';
        $description = 'Pronađite sat, sunčane naočale ili nakit prema osobi kojoj darujete i svom budžetu.';
    }

    if (request()->routeIs('products.show') && isset($product)) {
        $productTranslation = $product->translations->firstWhere('locale', $locale)
            ?? $product->translations->firstWhere('locale', $fallbackLocale);
        $title = $cleanupText($productTranslation?->meta_title ?: $productTranslation?->name ?: $title, 191);
        $description = $cleanupText($productTranslation?->meta_description ?: $productTranslation?->excerpt ?: $productTranslation?->description ?: ($watchlineSeo && $productTranslation?->name ? $productTranslation->name.' – Watchline.' : $description), 320);
        $ogType = 'product';

        if (trim((string) ($ogSettings['product_image_url'] ?? '')) !== '') {
            $ogImage = (string) $ogSettings['product_image_url'];
        } elseif (method_exists($product, 'getFirstMediaUrl')) {
            $productImage = (string) \App\Support\Media\LegacyCatalogImage::first($product);
            if ($productImage !== '') {
                $ogImage = $productImage;
            }
        }
    }

    if (request()->routeIs('pages.show') && isset($page)) {
        $pageTranslation = $selectedTranslation
            ?? $page->translations->firstWhere('locale', $locale)
            ?? $page->translations->firstWhere('locale', $fallbackLocale)
            ?? (isset($slug) ? $page->translations->firstWhere('slug', (string) $slug) : null);
        $title = $cleanupText($pageTranslation?->meta_title ?: $pageTranslation?->title ?: $title, 191);
        $description = $cleanupText($pageTranslation?->meta_description ?: $pageTranslation?->excerpt ?: ($watchlineSeo ? $pageTranslation?->body_html : '') ?: $description, 320);

        if (trim((string) ($ogSettings['page_image_url'] ?? '')) !== '') {
            $ogImage = (string) $ogSettings['page_image_url'];
        }
    }

    if (request()->routeIs('blog.*')) {
        $ogType = request()->routeIs('blog.show') ? 'article' : 'website';

        if (isset($blogCategoryTranslation) && $blogCategoryTranslation) {
            $title = $cleanupText($blogCategoryTranslation->meta_title ?: $blogCategoryTranslation->name ?: $title, 191);
            $description = $cleanupText($blogCategoryTranslation->meta_description ?: $blogCategoryTranslation->description ?: $description, 320);
        if ($canonicalPolicy === 'self') { $canonicalUrl = route('blog.index', ['category' => $blogCategoryTranslation->slug]); }
        }

        if (request()->routeIs('blog.show') && isset($post)) {
            $postTranslation = $post->translations->firstWhere('locale', $locale)
                ?? $post->translations->firstWhere('locale', $fallbackLocale);
            $title = $cleanupText($postTranslation?->meta_title ?: $postTranslation?->title ?: $title, 191);
            $description = $cleanupText($postTranslation?->meta_description ?: $postTranslation?->excerpt ?: ($watchlineSeo ? $postTranslation?->body_html : '') ?: $description, 320);

            if (trim((string) ($ogSettings['blog_image_url'] ?? '')) !== '') {
                $ogImage = (string) $ogSettings['blog_image_url'];
            } elseif (method_exists($post, 'getFirstMediaUrl')) {
                $postImage = (string) \App\Support\Media\HerreraBlogImage::first($post);
                if ($postImage !== '') {
                    $ogImage = $postImage;
                }
            }
        } elseif (trim((string) ($ogSettings['blog_image_url'] ?? '')) !== '') {
            $ogImage = (string) $ogSettings['blog_image_url'];
        }
    }

    if (! $watchlineSeo && request()->routeIs('faq.index')) {
        $title = $cleanupText((string) __('ui.faq.page_title'), 191);
        $description = $cleanupText((string) __('ui.faq.subtitle'), 320);
    }

    if ($description === '') {
        $description = $defaultDescription;
    }

    if ($title === '') {
        $title = (string) config('app.name', 'AG Shop');
    }
    $paginationNumber = isset($products) && $products instanceof \Illuminate\Contracts\Pagination\Paginator
        ? $products->currentPage()
        : (isset($posts) && $posts instanceof \Illuminate\Contracts\Pagination\Paginator ? $posts->currentPage() : 1);
    if ($watchlineSeo) {
        $seoPagination = isset($products) && $products instanceof \Illuminate\Contracts\Pagination\Paginator ? $products
            : (isset($posts) && $posts instanceof \Illuminate\Contracts\Pagination\Paginator ? $posts
                : (isset($reviews) && $reviews instanceof \Illuminate\Contracts\Pagination\Paginator ? $reviews
                    : (isset($pages) && $pages instanceof \Illuminate\Contracts\Pagination\Paginator ? $pages : null)));
        $paginationNumber = $seoPagination?->currentPage() ?? 1;
        if ($canonicalPolicy === 'self') { $canonicalUrl = \App\Support\WatchlineSeo::canonical(request(), $seoPagination); }
        $robots = \App\Support\WatchlineSeo::robots(request(), $robots);
        if ($ogImage !== '' && ! \Illuminate\Support\Str::startsWith($ogImage, ['http://', 'https://'])) { $ogImage = url($ogImage); }
    }
    if ($paginationNumber > 1) {
        $title .= ' – Stranica '.$paginationNumber;
    }
    if ($seoErrorPage) {
        $title = $sectionTitle !== '' ? $sectionTitle : 'Stranica nije pronađena';
        $canonicalUrl = '';
        $ogType = 'website';
    }
@endphp

<title>{{ $title }}</title>
@if ($description !== '')
    <meta name="description" content="{{ $description }}">
@endif
@if ($robots !== '')
    <meta name="robots" content="{{ $robots }}">
@endif
@if ($canonicalUrl !== '')
    <link rel="canonical" href="{{ $canonicalUrl }}">
@endif

<meta property="og:locale" content="{{ $watchlineSeo && app()->getLocale() === 'hr' ? 'hr_HR' : str_replace('-', '_', app()->getLocale()) }}">
<meta property="og:type" content="{{ $ogType }}">
<meta property="og:title" content="{{ $title }}">
@if ($description !== '')
    <meta property="og:description" content="{{ $description }}">
@endif
<meta property="og:url" content="{{ $canonicalUrl !== '' ? $canonicalUrl : request()->fullUrl() }}">
<meta property="og:site_name" content="{{ $siteName }}">
@if ($ogImage !== '')
    <meta property="og:image" content="{{ $ogImage }}">
@endif

<meta name="twitter:card" content="summary_large_image">
<meta name="twitter:title" content="{{ $title }}">
@if ($description !== '')
    <meta name="twitter:description" content="{{ $description }}">
@endif
@if ($ogImage !== '')
    <meta name="twitter:image" content="{{ $ogImage }}">
@endif
