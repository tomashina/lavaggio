<?php

namespace App\Livewire\Admin\Content\Navigation;

use App\Models\Catalog\Category\Category;
use App\Models\Content\Page\InfoPage;
use App\Services\Front\NavigationMenuService;
use App\Services\Settings\SystemSettingsService;
use Livewire\Component;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Livewire\WithFileUploads;

class Manager extends Component
{
    use WithFileUploads;

    /** @var array<string, string> */
    private const PROMO_LOCALIZED_FIELDS = [
        'desktop_promo_title' => 'desktop_promo_title_translations',
        'desktop_promo_subtitle' => 'desktop_promo_subtitle_translations',
        'desktop_promo_cta_label' => 'desktop_promo_cta_label_translations',
        'desktop_promo_cta_url' => 'desktop_promo_cta_url_translations',
    ];

    /**
     * @var array{
     *     items: array<int, array<string, mixed>>,
     *     category_images: array<int, array{image_path:string,image_alt:string}>,
     *     appearance: array{container_width:int,header_content_width:int,item_height:int,font_size:int,logo_height:int,background_color:string,text_color:string,highlight_color:string},
     *     top_bar: array<string, mixed>
     * }
     */
    public array $form = [
        'items' => [],
        'category_images' => [],
        'appearance' => [
            'container_width' => 1860,
            'header_content_width' => 1400,
            'item_height' => 62,
            'font_size' => 17,
            'logo_height' => 70,
            'background_color' => '#e65100',
            'text_color' => '#ffffff',
            'highlight_color' => '#ffffff',
        ],
        'top_bar' => [
            'is_enabled' => true,
            'height' => 34,
            'font_size' => 13,
            'background_color' => '#eeeeee',
            'text_color' => '#334155',
            'border_color' => '#4c1d95',
            'links' => [],
            'socials' => [],
        ],
    ];

    public string $locale = 'en';

    public string $previousLocale = 'en';

    /** @var array<int, TemporaryUploadedFile|null> */
    public array $desktopPromoUploads = [];

    /** @var array<int, TemporaryUploadedFile|null> */
    public array $categoryMenuUploads = [];

    public function mount(): void
    {
        $this->locale = (string) (request()->query('locale') ?: config('app.locale', 'en'));
        $this->previousLocale = $this->locale;

        $navigation = app(NavigationMenuService::class);
        $items = $navigation->configuredItems();
        $this->form['items'] = $items;
        $this->form['appearance'] = $navigation->appearance();
        $this->form['top_bar'] = $navigation->topBar();
        $this->form['category_images'] = $navigation->categoryImages();
        if (config('storefront.home') === 'lavaggio') {
            foreach (Category::query()->where('scope', Category::SCOPE_CATALOG)->whereNull('parent_id')->pluck('id') as $categoryId) {
                $this->form['category_images'][$categoryId] ??= ['image_path' => '', 'image_alt' => ''];
            }
        }
        $this->syncInputsFromLocaleTranslations($this->locale);
    }

    public function updatedCategoryMenuUploads(mixed $upload, string $categoryId): void
    {
        $this->validateOnly('categoryMenuUploads.'.$categoryId, [
            'categoryMenuUploads.'.$categoryId => ['nullable', 'image', 'max:4096'],
        ]);
    }

    public function updatedLocale(): void
    {
        $this->syncLocaleTranslationsFromInputs($this->previousLocale);
        $this->syncInputsFromLocaleTranslations($this->locale);
        $this->previousLocale = $this->locale;
    }

    public function addCategoryItem(): void
    {
        $this->form['items'][] = $this->makeDefaultItem('category');
    }

    public function addCatalogItem(): void
    {
        $item = $this->makeDefaultItem('catalog');
        $item['label'] = (string) __('admin.content.navigation.defaults.catalog');
        $item['label_translations'] = [$this->locale => (string) __('admin.content.navigation.defaults.catalog')];
        $item['url'] = '/categories';
        $item['url_translations'] = [$this->locale => '/categories'];
        $this->form['items'][] = $item;
    }

    public function addPageItem(): void
    {
        $this->form['items'][] = $this->makeDefaultItem('page');
    }

    public function addBlogItem(): void
    {
        $item = $this->makeDefaultItem('blog');
        $item['label'] = 'Blog';
        $item['label_translations'] = [$this->locale => 'Blog'];
        $this->form['items'][] = $item;
    }

    public function addContactItem(): void
    {
        $item = $this->makeDefaultItem('contact');
        $item['label'] = (string) __('admin.content.navigation.defaults.contact');
        $item['label_translations'] = [$this->locale => (string) __('admin.content.navigation.defaults.contact')];
        $this->form['items'][] = $item;
    }

    public function addFaqItem(): void
    {
        $item = $this->makeDefaultItem('faq');
        $item['label'] = 'FAQ';
        $item['label_translations'] = [$this->locale => 'FAQ'];
        $this->form['items'][] = $item;
    }

    public function addCustomItem(): void
    {
        $item = $this->makeDefaultItem('custom');
        $item['label'] = (string) __('admin.content.navigation.defaults.custom_label');
        $item['url'] = '/';
        $item['label_translations'] = [$this->locale => (string) __('admin.content.navigation.defaults.custom_label')];
        $item['url_translations'] = [$this->locale => '/'];
        $this->form['items'][] = $item;
    }

    public function removeItem(int $index): void
    {
        if (! isset($this->form['items'][$index])) {
            return;
        }

        unset($this->form['items'][$index]);
        $this->form['items'] = array_values($this->form['items']);
    }

    public function moveUp(int $index): void
    {
        if ($index <= 0 || ! isset($this->form['items'][$index])) {
            return;
        }

        [$this->form['items'][$index - 1], $this->form['items'][$index]] = [$this->form['items'][$index], $this->form['items'][$index - 1]];
    }

    public function moveDown(int $index): void
    {
        $lastIndex = count($this->form['items']) - 1;

        if ($index < 0 || $index >= $lastIndex || ! isset($this->form['items'][$index])) {
            return;
        }

        [$this->form['items'][$index + 1], $this->form['items'][$index]] = [$this->form['items'][$index], $this->form['items'][$index + 1]];
    }

    public function addTopBarLink(): void
    {
        $this->form['top_bar']['links'][] = [
            'label' => (string) __('admin.content.navigation.top_bar_default_link'),
            'url' => '/',
            'open_in_new_tab' => false,
            'is_active' => true,
            'sort_order' => count($this->form['top_bar']['links']) * 10,
        ];
    }

    public function removeTopBarLink(int $index): void
    {
        if (! isset($this->form['top_bar']['links'][$index])) {
            return;
        }

        unset($this->form['top_bar']['links'][$index]);
        $this->form['top_bar']['links'] = array_values($this->form['top_bar']['links']);
    }

    public function addSocialLink(): void
    {
        $this->form['top_bar']['socials'][] = [
            'network' => 'facebook',
            'url' => '',
            'is_active' => true,
            'sort_order' => count($this->form['top_bar']['socials']) * 10,
        ];
    }

    public function removeSocialLink(int $index): void
    {
        if (! isset($this->form['top_bar']['socials'][$index])) {
            return;
        }

        unset($this->form['top_bar']['socials'][$index]);
        $this->form['top_bar']['socials'] = array_values($this->form['top_bar']['socials']);
    }

    public function addChildItem(string $parentPath, string $type = 'page'): void
    {
        if (! $this->validItemPath($parentPath) || substr_count($parentPath, '.children.') >= 3 || ! in_array($type, ['page', 'custom'], true)) {
            return;
        }
        $parent = data_get($this->form['items'], $parentPath);
        if (! is_array($parent)) {
            return;
        }
        $children = $parent['children'] ?? [];
        $item = $this->makeDefaultItem($type);
        $item['sort_order'] = count($children);
        if ($type === 'custom') {
            $item['url'] = '/';
        }
        $children[] = $item;
        data_set($this->form['items'], $parentPath.'.children', $children);
    }

    public function removeNestedItem(string $path): void
    {
        $this->changeNestedItem($path, null);
    }

    public function moveNestedItem(string $path, int $direction): void
    {
        if (in_array($direction, [-1, 1], true)) {
            $this->changeNestedItem($path, $direction);
        }
    }

    private function validItemPath(string $path): bool
    {
        return preg_match('/^\d+(?:\.children\.\d+){0,3}$/', $path) === 1;
    }

    private function changeNestedItem(string $path, ?int $direction): void
    {
        if (! $this->validItemPath($path) || ! str_contains($path, '.children.')) {
            return;
        }
        $split = strrpos($path, '.');
        $listPath = substr($path, 0, $split);
        $index = (int) substr($path, $split + 1);
        $items = data_get($this->form['items'], $listPath, []);
        if (! isset($items[$index])) {
            return;
        }
        if ($direction === null) {
            unset($items[$index]);
            $items = array_values($items);
        } elseif (isset($items[$index + $direction])) {
            [$items[$index], $items[$index + $direction]] = [$items[$index + $direction], $items[$index]];
        }
        foreach ($items as $order => &$item) {
            $item['sort_order'] = $order;
        }
        unset($item);
        data_set($this->form['items'], $listPath, $items);
    }

    private function addNestedValidationRules(array $items, string $path, array $nodeRules, array &$rules, int $depth = 0): void
    {
        foreach ($items as $index => $item) {
            $itemPath = $path.'.'.$index;
            foreach ($nodeRules as $key => $rule) {
                $rules[str_replace('form.items.*', $itemPath, $key)] = $rule;
            }
            if ($depth >= 3) {
                $rules[$itemPath.'.children'] = ['sometimes', 'array', 'size:0'];
            } elseif (is_array($item['children'] ?? null)) {
                $this->addNestedValidationRules($item['children'], $itemPath.'.children', $nodeRules, $rules, $depth + 1);
            }
        }
    }

    private function validateItemReferences(array $item, string $path): void
    {
        $type = (string) $item['type'];
        if ($type === 'category' && (int) $item['category_id'] <= 0) {
            $this->addError($path.'.category_id', (string) __('admin.content.navigation.errors.select_category'));
        }
        if ($type === 'page' && (int) $item['page_id'] <= 0) {
            $this->addError($path.'.page_id', (string) __('admin.content.navigation.errors.select_page'));
        }
        if ($type === 'custom') {
            if (trim((string) $item['label']) === '') {
                $this->addError($path.'.label', (string) __('admin.content.navigation.errors.enter_label'));
            }
            if (trim((string) $item['url']) === '') {
                $this->addError($path.'.url', (string) __('admin.content.navigation.errors.enter_url'));
            }
        }
        foreach ($item['children'] ?? [] as $index => $child) {
            $this->validateItemReferences($child, $path.'.children.'.$index);
        }
    }

    public function save(): void
    {
        $this->syncLocaleTranslationsFromInputs($this->locale);

        $rules = [
            'form.items' => ['array'],
            'form.category_images' => ['array'],
            'form.category_images.*.image_path' => ['nullable', 'string', 'max:2048'],
            'form.category_images.*.image_alt' => ['nullable', 'string', 'max:255'],
            'form.items.*.children' => ['sometimes', 'array', 'max:100'],
            'form.items.*.type' => ['required', 'in:catalog,category,page,blog,contact,faq,custom'],
            'form.items.*.label' => ['nullable', 'string', 'max:120'],
            'form.items.*.category_id' => ['nullable', 'integer', 'min:0'],
            'form.items.*.page_id' => ['nullable', 'integer', 'min:0'],
            'form.items.*.url' => ['nullable', 'string', 'max:2048'],
            'form.items.*.label_translations' => ['nullable', 'array'],
            'form.items.*.label_translations.*' => ['nullable', 'string', 'max:120'],
            'form.items.*.url_translations' => ['nullable', 'array'],
            'form.items.*.url_translations.*' => ['nullable', 'string', 'max:2048'],
            'form.items.*.is_active' => ['required', 'boolean'],
            'form.items.*.show_dropdown' => ['required', 'boolean'],
            'form.items.*.is_highlighted' => ['required', 'boolean'],
            'form.items.*.icon' => ['nullable', 'string', 'in:gift,ticket'],
            'form.items.*.align_right' => ['sometimes', 'boolean'],
            'form.items.*.open_in_new_tab' => ['required', 'boolean'],
            'form.items.*.sort_order' => ['required', 'integer', 'min:0', 'max:9999'],
            'form.items.*.desktop_promo_image_path' => ['nullable', 'string', 'max:2048'],
            'form.items.*.desktop_promo_title' => ['nullable', 'string', 'max:120'],
            'form.items.*.desktop_promo_subtitle' => ['nullable', 'string', 'max:255'],
            'form.items.*.desktop_promo_cta_label' => ['nullable', 'string', 'max:80'],
            'form.items.*.desktop_promo_cta_url' => ['nullable', 'string', 'max:2048'],
            'form.items.*.desktop_promo_title_translations' => ['nullable', 'array'],
            'form.items.*.desktop_promo_title_translations.*' => ['nullable', 'string', 'max:120'],
            'form.items.*.desktop_promo_subtitle_translations' => ['nullable', 'array'],
            'form.items.*.desktop_promo_subtitle_translations.*' => ['nullable', 'string', 'max:255'],
            'form.items.*.desktop_promo_cta_label_translations' => ['nullable', 'array'],
            'form.items.*.desktop_promo_cta_label_translations.*' => ['nullable', 'string', 'max:80'],
            'form.items.*.desktop_promo_cta_url_translations' => ['nullable', 'array'],
            'form.items.*.desktop_promo_cta_url_translations.*' => ['nullable', 'string', 'max:2048'],
            'form.appearance.container_width' => ['required', 'integer', 'min:960', 'max:1920'],
            'form.appearance.header_content_width' => ['required', 'integer', 'min:960', 'max:1920', 'lte:form.appearance.container_width'],
            'form.appearance.item_height' => ['required', 'integer', 'min:44', 'max:84'],
            'form.appearance.font_size' => ['required', 'integer', 'min:13', 'max:20'],
            'form.appearance.logo_height' => ['required', 'integer', 'min:48', 'max:82'],
            'form.appearance.background_color' => ['required', 'regex:/^#[0-9a-fA-F]{6}$/'],
            'form.appearance.text_color' => ['required', 'regex:/^#[0-9a-fA-F]{6}$/'],
            'form.appearance.highlight_color' => ['required', 'regex:/^#[0-9a-fA-F]{6}$/'],
            'form.top_bar.is_enabled' => ['required', 'boolean'],
            'form.top_bar.height' => ['required', 'integer', 'min:28', 'max:50'],
            'form.top_bar.font_size' => ['required', 'integer', 'min:11', 'max:16'],
            'form.top_bar.background_color' => ['required', 'regex:/^#[0-9a-fA-F]{6}$/'],
            'form.top_bar.text_color' => ['required', 'regex:/^#[0-9a-fA-F]{6}$/'],
            'form.top_bar.border_color' => ['required', 'regex:/^#[0-9a-fA-F]{6}$/'],
            'form.top_bar.links' => ['array'],
            'form.top_bar.links.*.label' => ['required', 'string', 'max:120'],
            'form.top_bar.links.*.url' => ['required', 'string', 'max:2048'],
            'form.top_bar.links.*.open_in_new_tab' => ['required', 'boolean'],
            'form.top_bar.links.*.is_active' => ['required', 'boolean'],
            'form.top_bar.links.*.sort_order' => ['required', 'integer', 'min:0', 'max:9999'],
            'form.top_bar.socials' => ['array'],
            'form.top_bar.socials.*.network' => ['required', 'in:facebook,youtube,instagram,linkedin,twitter,tiktok'],
            'form.top_bar.socials.*.label' => ['nullable', 'string', 'max:120'],
            'form.top_bar.socials.*.url' => ['required', 'string', 'max:2048'],
            'form.top_bar.socials.*.is_active' => ['required', 'boolean'],
            'form.top_bar.socials.*.sort_order' => ['required', 'integer', 'min:0', 'max:9999'],
            'desktopPromoUploads.*' => ['nullable', 'image', 'max:4096'],
            'categoryMenuUploads.*' => ['nullable', 'image', 'max:4096'],
        ];
        $nodeRules = array_filter($rules, fn ($key): bool => str_starts_with($key, 'form.items.*.'), ARRAY_FILTER_USE_KEY);
        $this->addNestedValidationRules($this->form['items'], 'form.items', $nodeRules, $rules);
        $validated = $this->validate($rules);

        $normalizedItems = [];
        foreach (($validated['form']['items'] ?? []) as $index => $item) {
            $normalized = $this->normalizeItem($item, $index, $this->desktopPromoUploads[$index] ?? null);
            $normalizedItems[] = $normalized;

            $this->validateItemReferences($normalized, 'form.items.'.$index);
        }

        if ($this->getErrorBag()->isNotEmpty()) {
            return;
        }

        $settings = [
            NavigationMenuService::SETTINGS_KEY => $normalizedItems,
            NavigationMenuService::APPEARANCE_SETTINGS_KEY => $validated['form']['appearance'],
            NavigationMenuService::TOP_BAR_SETTINGS_KEY => $validated['form']['top_bar'],
        ];
        if (config('storefront.home') === 'lavaggio') {
            $images = [];
            $categoryIds = Category::query()->where('scope', Category::SCOPE_CATALOG)->whereNull('parent_id')->pluck('id');
            foreach ($categoryIds as $categoryId) {
                $image = $validated['form']['category_images'][$categoryId] ?? [];
                $imagePath = trim((string) ($image['image_path'] ?? ''));
                $upload = $this->categoryMenuUploads[$categoryId] ?? null;
                if ($upload instanceof TemporaryUploadedFile) {
                    $imagePath = $upload->store('navigation/categories', 'public');
                }

                $images[(int) $categoryId] = [
                    'image_path' => $imagePath,
                    'image_alt' => trim((string) ($image['image_alt'] ?? '')),
                ];
            }
            $settings[NavigationMenuService::CATEGORY_IMAGES_SETTINGS_KEY] = $images;
            $this->form['category_images'] = $images;
        }
        app(SystemSettingsService::class)->putMany($settings);
        $this->desktopPromoUploads = [];
        $this->categoryMenuUploads = [];

        $this->dispatch('notify', type: 'success', message: (string) __('admin.content.navigation.notify_saved'));
    }

    public function clearDesktopPromoImage(int $index): void
    {
        if (! isset($this->form['items'][$index])) {
            return;
        }

        $this->form['items'][$index]['desktop_promo_image_path'] = '';
        unset($this->desktopPromoUploads[$index]);
    }

    public function clearCategoryMenuImage(int $categoryId): void
    {
        if (! isset($this->form['category_images'][$categoryId])) {
            return;
        }

        $this->form['category_images'][$categoryId]['image_path'] = '';
        unset($this->categoryMenuUploads[$categoryId]);
        $this->resetValidation('categoryMenuUploads.'.$categoryId);
    }

    public function render()
    {
        $fallbackLocale = (string) config('app.locale', 'en');
        $locales = array_values(array_unique([$this->locale, $fallbackLocale]));

        $categoryOptions = Category::query()
            ->where('scope', Category::SCOPE_CATALOG)
            ->where('is_active', true)
            ->with([
                'translations' => fn ($q) => $q
                    ->where('scope', Category::SCOPE_CATALOG)
                    ->whereIn('locale', $locales),
            ])
            ->orderBy('_lft')
            ->get();

        $categoryNameById = $categoryOptions
            ->mapWithKeys(function (Category $category) use ($fallbackLocale): array {
                $translation = $category->translations->firstWhere('locale', $this->locale)
                    ?? $category->translations->firstWhere('locale', $fallbackLocale)
                    ?? $category->translations->first();

                return [
                    (int) $category->id => (string) ($translation?->name ?? $category->code ?? '#'.$category->id),
                ];
            });

        $categoryMap = $categoryOptions->keyBy(fn (Category $category): int => (int) $category->id);

        $categoryImageOptions = $categoryOptions
            ->filter(fn (Category $category): bool => $category->parent_id === null)
            ->sortBy(fn (Category $category): array => [(int) $category->sort_order, (int) $category->id])
            ->map(fn (Category $category): array => [
                'id' => (int) $category->id,
                'label' => (string) $categoryNameById[(int) $category->id],
                'image_url' => app(NavigationMenuService::class)->categoryMenuImageUrl((string) ($this->form['category_images'][$category->id]['image_path'] ?? '')),
            ])
            ->values()
            ->all();

        $categoryOptions = $categoryOptions
            ->map(function (Category $category) use ($categoryNameById, $categoryMap): array {
                $parts = [];
                $cursor = $category;
                $guard = 0;

                while ($cursor && $guard < 32) {
                    $parts[] = (string) ($categoryNameById[(int) $cursor->id] ?? ('#'.$cursor->id));
                    $parentId = (int) ($cursor->parent_id ?? 0);
                    $cursor = $parentId > 0 ? $categoryMap->get($parentId) : null;
                    $guard++;
                }

                $parts = array_reverse($parts);

                return [
                    'id' => (int) $category->id,
                    'label' => implode(' > ', $parts),
                ];
            })
            ->values()
            ->all();

        $pageOptions = InfoPage::query()
            ->where('is_active', true)
            ->with([
                'translations' => fn ($q) => $q->whereIn('locale', $locales),
            ])
            ->orderBy('sort_order')
            ->orderByDesc('id')
            ->get()
            ->map(function (InfoPage $page) use ($fallbackLocale): array {
                $translation = $page->translations->firstWhere('locale', $this->locale)
                    ?? $page->translations->firstWhere('locale', $fallbackLocale)
                    ?? $page->translations->first();

                return [
                    'id' => (int) $page->id,
                    'label' => (string) ($translation?->title ?? $page->code),
                ];
            })
            ->values()
            ->all();

        return view('livewire.admin.content.navigation.manager', [
            'categoryOptions' => $categoryOptions,
            'pageOptions' => $pageOptions,
            'categoryImageOptions' => $categoryImageOptions,
        ]);
    }

    /**
     * @param  array<string, mixed>  $item
     * @return array<string, mixed>
     */
    private function normalizeItem(array $item, int $index, mixed $desktopPromoUpload = null): array
    {
        $type = (string) ($item['type'] ?? 'custom');
        $locale = strtolower(trim($this->locale));
        $fallbackLocale = strtolower((string) config('app.locale', 'en'));
        $labelTranslations = $this->normalizeTranslations($item['label_translations'] ?? []);
        $urlTranslations = $this->normalizeTranslations($item['url_translations'] ?? []);

        $label = trim((string) ($item['label'] ?? ''));
        if ($label !== '' && $locale !== '') {
            $labelTranslations[$locale] = $label;
        }
        if ($label === '' && $locale !== '') {
            unset($labelTranslations[$locale]);
        }

        $url = trim((string) ($item['url'] ?? ''));
        if ($url !== '' && $locale !== '') {
            $urlTranslations[$locale] = $url;
        }
        if ($url === '' && $locale !== '') {
            unset($urlTranslations[$locale]);
        }

        $storedLabel = $this->pickTranslationValue($labelTranslations, $fallbackLocale);
        $storedUrl = $this->pickTranslationValue($urlTranslations, $fallbackLocale);

        $desktopPromoImagePath = trim((string) ($item['desktop_promo_image_path'] ?? ''));
        if ($desktopPromoUpload instanceof TemporaryUploadedFile) {
            $desktopPromoImagePath = $desktopPromoUpload->store('navigation/mega-promo', 'public');
        }

        $localizedPromo = [];
        foreach (self::PROMO_LOCALIZED_FIELDS as $field => $translationsField) {
            $translations = $this->normalizeTranslations($item[$translationsField] ?? []);
            $value = trim((string) ($item[$field] ?? ''));

            if ($value !== '' && $locale !== '') {
                $translations[$locale] = $value;
            } elseif ($locale !== '') {
                unset($translations[$locale]);
            }

            $localizedPromo[$field] = $this->pickTranslationValue($translations, $fallbackLocale);
            $localizedPromo[$translationsField] = $translations;
        }

        return array_merge([
            'type' => $type,
            'label' => $storedLabel,
            'label_translations' => $labelTranslations,
            'category_id' => (int) ($item['category_id'] ?? 0),
            'page_id' => (int) ($item['page_id'] ?? 0),
            'url' => $storedUrl,
            'url_translations' => $urlTranslations,
            'open_in_new_tab' => (bool) ($item['open_in_new_tab'] ?? false),
            'show_dropdown' => (bool) ($item['show_dropdown'] ?? true),
            'is_highlighted' => (bool) ($item['is_highlighted'] ?? false),
            'icon' => in_array($item['icon'] ?? '', ['gift', 'ticket'], true) ? $item['icon'] : '',
            'align_right' => (bool) ($item['align_right'] ?? false),
            'is_active' => (bool) ($item['is_active'] ?? true),
            'sort_order' => (int) ($item['sort_order'] ?? $index),
            'desktop_promo_image_path' => $desktopPromoImagePath,
            'children' => array_map(fn (array $child, int $childIndex): array => $this->normalizeItem($child, $childIndex), $item['children'] ?? [], array_keys($item['children'] ?? [])),
        ], $localizedPromo);
    }

    /**
     * @return array<string, mixed>
     */
    private function makeDefaultItem(string $type): array
    {
        return [
            'type' => $type,
            'children' => [],
            'label' => '',
            'label_translations' => [],
            'category_id' => 0,
            'page_id' => 0,
            'url' => '',
            'url_translations' => [],
            'open_in_new_tab' => false,
            'show_dropdown' => true,
            'is_highlighted' => false,
            'icon' => '',
            'align_right' => false,
            'is_active' => true,
            'sort_order' => count($this->form['items']),
            'desktop_promo_image_path' => '',
            'desktop_promo_title' => '',
            'desktop_promo_title_translations' => [],
            'desktop_promo_subtitle' => '',
            'desktop_promo_subtitle_translations' => [],
            'desktop_promo_cta_label' => '',
            'desktop_promo_cta_label_translations' => [],
            'desktop_promo_cta_url' => '',
            'desktop_promo_cta_url_translations' => [],
        ];
    }

    private function syncLocaleTranslationsFromInputs(string $locale): void
    {
        $normalizedLocale = strtolower(trim($locale));
        if ($normalizedLocale === '') {
            return;
        }

        $this->form['items'] = $this->syncItemsLocale($this->form['items'], $normalizedLocale, false);
    }

    private function syncItemsLocale(array $items, string $normalizedLocale, bool $read): array
    {
        $fallbackLocale = strtolower((string) config('app.locale', 'en'));
        foreach ($items as $index => $item) {
            if ($read) {
                foreach (['label' => 'label_translations', 'url' => 'url_translations', ...self::PROMO_LOCALIZED_FIELDS] as $field => $translationsField) {
                    $translations = $this->normalizeTranslations($item[$translationsField] ?? []);
                    $resolved = $this->pickTranslationValue($translations, $normalizedLocale, $fallbackLocale);
                    $items[$index][$field] = $resolved !== '' ? $resolved : trim((string) ($item[$field] ?? ''));
                    $items[$index][$translationsField] = $translations;
                }
                $items[$index]['children'] = $this->syncItemsLocale($item['children'] ?? [], $normalizedLocale, true);

                continue;
            }
            $labelTranslations = $this->normalizeTranslations($item['label_translations'] ?? []);
            $urlTranslations = $this->normalizeTranslations($item['url_translations'] ?? []);

            $label = trim((string) ($item['label'] ?? ''));
            if ($label !== '') {
                $labelTranslations[$normalizedLocale] = $label;
            } else {
                unset($labelTranslations[$normalizedLocale]);
            }

            $url = trim((string) ($item['url'] ?? ''));
            if ($url !== '') {
                $urlTranslations[$normalizedLocale] = $url;
            } else {
                unset($urlTranslations[$normalizedLocale]);
            }

            $items[$index]['label_translations'] = $labelTranslations;
            $items[$index]['url_translations'] = $urlTranslations;

            foreach (self::PROMO_LOCALIZED_FIELDS as $field => $translationsField) {
                $translations = $this->normalizeTranslations($item[$translationsField] ?? []);
                $value = trim((string) ($item[$field] ?? ''));

                if ($value !== '') {
                    $translations[$normalizedLocale] = $value;
                } else {
                    unset($translations[$normalizedLocale]);
                }

                $items[$index][$translationsField] = $translations;
            }
            $items[$index]['children'] = $this->syncItemsLocale($item['children'] ?? [], $normalizedLocale, false);
        }

        return $items;
    }

    private function syncInputsFromLocaleTranslations(string $locale): void
    {
        $this->form['items'] = $this->syncItemsLocale($this->form['items'], strtolower(trim($locale)), true);
    }

    /**
     * @return array<string, string>
     */
    private function normalizeTranslations(mixed $translations): array
    {
        if (! is_array($translations)) {
            return [];
        }

        $normalized = [];
        foreach ($translations as $locale => $value) {
            $key = strtolower(trim((string) $locale));
            if ($key === '') {
                continue;
            }

            $text = trim((string) $value);
            if ($text === '') {
                continue;
            }

            $normalized[$key] = $text;
        }

        return $normalized;
    }

    /**
     * @param  array<string, string>  $translations
     */
    private function pickTranslationValue(array $translations, string ...$preferredLocales): string
    {
        foreach ($preferredLocales as $locale) {
            $key = strtolower(trim($locale));
            if ($key !== '' && isset($translations[$key]) && trim((string) $translations[$key]) !== '') {
                return trim((string) $translations[$key]);
            }
        }

        foreach ($translations as $value) {
            $text = trim((string) $value);
            if ($text !== '') {
                return $text;
            }
        }

        return '';
    }
}
