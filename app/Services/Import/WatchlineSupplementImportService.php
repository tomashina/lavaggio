<?php

namespace App\Services\Import;

use App\Models\Catalog\Product\Product;
use App\Models\Content\Blog\BlogPost;
use App\Support\ImportedDescriptionHtmlCleaner;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

/** Migrates Watchline extension data without replaying mail, payments or stock events. */
class WatchlineSupplementImportService
{
    private ConnectionInterface $source;

    private string $prefix;

    private array $maps = [];

    private array $records = [];

    private array $stats = [];

    public function import(string $connection = 'watchline_source', bool $dryRun = false, string $prefix = 'oc_'): array
    {
        $this->source = DB::connection($connection);
        $this->prefix = $prefix;
        if (! preg_match('/^[a-zA-Z0-9_]*$/', $prefix)
            || ! app()->environment(['local', 'testing'])
            || ! in_array($this->source->getConfig('host'), ['127.0.0.1', 'localhost', '::1', null], true)
            || ($this->source->getDriverName() === DB::connection()->getDriverName()
                && $this->source->getDatabaseName() === DB::connection()->getDatabaseName())) {
            throw new RuntimeException('Watchline extensions require separate local source and target databases.');
        }
        $this->stats = [];
        foreach (['review', 'blog_comment', 'testimonial', 'newsletter', 'tf_filter', 'tf_filter_value', 'tf_filter_value_to_product', 'customer_reward', 'order_option', 'blog_related_products', 'manufacturer_description'] as $table) {
            $this->stats['source_'.$table] = $this->has($table) ? $this->source->table($this->prefix.$table)->count() : 0;
        }
        if ($dryRun) {
            return $this->stats;
        }
        $this->maps = $this->records = [];
        foreach (DB::table('herrera_import_maps')->whereIn('source', ['herrera-opencart', 'watchline-opencart'])->get() as $map) {
            if ($map->source === 'herrera-opencart') {
                $this->maps[$map->entity][(string) $map->source_id] = (int) $map->target_id;
            } else {
                $this->records[$map->entity][(string) $map->source_id] = (int) $map->target_id;
            }
        }
        $this->comments();
        $this->newsletters();
        $this->filters();
        $this->rewards();
        $this->metadata();
        $this->anchorPrices();
        $this->archive();

        return $this->stats;
    }

    private function comments(): void
    {
        foreach (['review' => [Product::class, 'product', 'product_id', 'review_id'], 'blog_comment' => [BlogPost::class, 'blog', 'blog_id', 'blog_comment_id']] as $table => [$model, $entity, $foreign, $key]) {
            $this->each($table, function (array $row) use ($table, $model, $entity, $foreign, $key): void {
                if (! $target = $this->mapped($entity, $row[$foreign])) {
                    $this->retain($table, $row, (string) $row[$key]);
                    $this->increment($table.'_missing_target');

                    return;
                }
                $this->record($table, $row[$key], 'content_comments', [
                    'commentable_type' => (new $model)->getMorphClass(), 'commentable_id' => $target,
                    'user_id' => $this->mapped('customer', $row['customer_id'] ?? 0),
                    'author_name' => mb_substr($row['author'] ?? $row['name'] ?? '', 0, 120),
                    'author_email' => $row['email'] ?? null, 'locale' => 'hr',
                    'body' => html_entity_decode($row['text'] ?? $row['comment'] ?? '', ENT_QUOTES | ENT_HTML5, 'UTF-8'),
                    'rating' => isset($row['rating']) && (int) $row['rating'] >= 1 && (int) $row['rating'] <= 5 ? (int) $row['rating'] : null,
                    'status' => ! empty($row['status']) ? 'approved' : 'pending',
                    'payload' => $this->json(['opencart' => $row, 'import_source' => 'watchline']),
                    'created_at' => $this->date($row['date_added'] ?? null) ?? now(),
                    'updated_at' => $this->date($row['date_modified'] ?? $row['date_added'] ?? null) ?? now(),
                ]);
                $this->increment($table.'_imported');
            });
        }
        $descriptions = [];
        $this->each('testimonial_description', function (array $row) use (&$descriptions): void {
            if ((int) ($row['language_id'] ?? 2) === 2) {
                $descriptions[$row['testimonial_id']] = $row;
            }
        });
        $this->each('testimonial', function (array $row) use ($descriptions): void {
            $description = $descriptions[$row['testimonial_id']] ?? [];
            $body = $description['description'] ?? $description['text'] ?? '';
            if (trim($body) === '') {
                $this->retain('testimonial', $row, (string) $row['testimonial_id']);

                return;
            }
            $this->record('testimonial', $row['testimonial_id'], 'content_comments', [
                'commentable_type' => null, 'commentable_id' => null, 'locale' => 'hr',
                'author_name' => mb_substr($row['name'] ?? '', 0, 120),
                'body' => html_entity_decode($body, ENT_QUOTES | ENT_HTML5, 'UTF-8'),
                'status' => ! empty($row['status']) ? 'approved' : 'pending',
                'payload' => $this->json(['opencart' => $row, 'translations' => $description, 'import_source' => 'watchline', 'kind' => 'store_testimonial']),
            ]);
            $this->increment('testimonials_imported');
        });
        $this->each('question', function (array $row): void {
            if (! $product = $this->mapped('product', $row['product_id'])) {
                $this->retain('question', $row, (string) $row['question_id']);

                return;
            }
            $fields = [
                'commentable_type' => (new Product)->getMorphClass(), 'commentable_id' => $product,
                'locale' => 'hr', 'status' => ! empty($row['status']) ? 'approved' : 'pending',
                'payload' => $this->json(['opencart' => $row, 'import_source' => 'watchline', 'kind' => 'product_question']),
                'created_at' => $this->date($row['date_added'] ?? null) ?? now(),
            ];
            $parent = $this->record('question', $row['question_id'], 'content_comments', [
                'user_id' => $this->mapped('customer', $row['customer_id'] ?? 0),
                'author_name' => mb_substr($row['author'] ?? '', 0, 120), 'author_email' => $row['email'] ?? null,
                'body' => html_entity_decode($row['text'] ?? '', ENT_QUOTES | ENT_HTML5, 'UTF-8'),
            ] + $fields);
            if (trim($row['answer'] ?? '') !== '') {
                $this->record('question_answer', $row['question_id'], 'content_comments', [
                    'parent_id' => $parent, 'author_name' => 'Watchline',
                    'body' => html_entity_decode($row['answer'], ENT_QUOTES | ENT_HTML5, 'UTF-8'),
                ] + $fields);
            }
            $this->increment('product_questions_imported');
        });
    }

    private function newsletters(): void
    {
        $users = DB::table('users')->where('account_type', 'customer')->pluck('id', 'email')->all();
        $this->each('customer', function (array $row) use ($users): void {
            if (! empty($row['newsletter'])) {
                $this->newsletter($row['email'], $users, ['customer_id' => $row['customer_id'], 'newsletter' => $row['newsletter']], true, $this->date($row['date_added'] ?? null));
            }
        });
        $this->each('newsletter', function (array $row) use ($users): void {
            $this->newsletter($row['email'] ?? '', $users, $row, false, null);
        });
    }

    private function newsletter(string $email, array $users, array $row, bool $optedIn, ?string $date): void
    {
        $email = strtolower(trim($email));
        if (! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $this->retain('newsletter_invalid', $row);
            $this->increment('newsletter_invalid');

            return;
        }
        if (DB::table('newsletter_signups')->where('email', $email)->exists()) {
            $this->increment('newsletter_existing');

            return;
        }
        $this->record('newsletter', hash('sha256', $email), 'newsletter_signups', [
            'email' => $email, 'user_id' => $users[$email] ?? null, 'source' => 'watchline_opencart', 'locale' => 'hr',
            'provider' => 'database', 'sync_status' => 'skipped', 'consent_accepted' => $optedIn,
            'subscribed_at' => $date, 'payload' => $this->json(['opencart' => $row, 'import_source' => 'watchline', 'consent_recorded' => $optedIn]),
        ]);
        $this->increment('newsletters_imported');
    }

    private function filters(): void
    {
        $groupNames = $valueNames = $groupActive = [];
        $this->each('tf_filter_description', function (array $row) use (&$groupNames): void {
            if ((int) $row['language_id'] === 2) {
                $groupNames[$row['filter_id']] = $row['name'];
            }
        });
        $this->each('tf_filter_value_description', function (array $row) use (&$valueNames): void {
            if ((int) $row['language_id'] === 2) {
                $valueNames[$row['value_id']] = $row['name'];
            }
        });
        $this->each('tf_filter', function (array $row) use ($groupNames, &$groupActive): void {
            $groupActive[$row['filter_id']] = (bool) $row['status'];
            $id = $this->record('filter_group', $row['filter_id'], 'catalog_attribute_groups', [
                'code' => 'watchline-filter-'.$row['filter_id'], 'type' => 'select', 'sort_order' => max(0, (int) $row['sort_order']),
                'payload' => $this->json(['opencart' => $row, 'import_source' => 'watchline']),
            ]);
            $this->record('filter_group_translation', $row['filter_id'], 'catalog_attribute_group_translations', [
                'attribute_group_id' => $id, 'locale' => 'hr', 'name' => html_entity_decode($groupNames[$row['filter_id']] ?? 'Filter '.$row['filter_id'], ENT_QUOTES | ENT_HTML5, 'UTF-8'),
            ]);
            $this->increment('filter_groups_imported');
        });
        $this->each('tf_filter_value', function (array $row) use ($groupNames, $valueNames, &$groupActive): void {
            if (! $group = $this->records['filter_group'][(string) $row['filter_id']] ?? null) {
                $this->retain('tf_filter_value', $row, (string) $row['value_id']);

                return;
            }
            $id = $this->record('filter_value', $row['value_id'], 'catalog_attributes', [
                'attribute_group_id' => $group, 'code' => 'watchline-filter-value-'.$row['value_id'],
                'group_code' => 'watchline-filter-'.$row['filter_id'], 'type' => 'select', 'is_active' => (bool) $row['status'] && ($groupActive[$row['filter_id']] ?? false),
                'sort_order' => max(0, (int) $row['sort_order']), 'payload' => $this->json(['opencart' => $row, 'import_source' => 'watchline']),
            ]);
            $this->record('filter_value_translation', $row['value_id'], 'catalog_attribute_translations', [
                'attribute_id' => $id, 'locale' => 'hr', 'group_name' => html_entity_decode($groupNames[$row['filter_id']] ?? 'Filter', ENT_QUOTES | ENT_HTML5, 'UTF-8'),
                'name' => html_entity_decode($valueNames[$row['value_id']] ?? $row['value'], ENT_QUOTES | ENT_HTML5, 'UTF-8'),
                'slug' => 'watchline-filter-'.$row['value_id'].'-'.mb_substr(Str::slug($valueNames[$row['value_id']] ?? $row['value']), 0, 120),
            ]);
            $this->increment('filter_values_imported');
        });
        $buffer = [];
        $this->each('tf_filter_value_to_product', function (array $row) use (&$buffer): void {
            if (isset($this->records['filter_product_links'][(string) $row['value_id']])) {
                return;
            }
            $attribute = $this->records['filter_value'][(string) $row['value_id']] ?? null;
            $product = $this->mapped('product', $row['product_id']);
            if ($attribute && $product && empty($row['trash'])) {
                $buffer[] = ['attribute_id' => $attribute, 'product_id' => $product, 'sort_order' => 0, 'created_at' => now(), 'updated_at' => now()];
                $this->increment('filter_product_links');
                if (count($buffer) >= 200) {
                    DB::table('catalog_attribute_product')->insertOrIgnore($buffer);
                    $buffer = [];
                }
            }
        });
        if ($buffer !== []) {
            DB::table('catalog_attribute_product')->insertOrIgnore($buffer);
        }
        foreach ($this->records['filter_value'] ?? [] as $sourceId => $id) {
            if (! isset($this->records['filter_product_links'][$sourceId])) {
                $this->remember('filter_product_links', $sourceId, $id, ['imported' => true]);
            }
        }
    }

    private function rewards(): void
    {
        $this->each('customer_reward', function (array $row): void {
            if (! $user = $this->mapped('customer', $row['customer_id'])) {
                $this->retain('customer_reward', $row, (string) $row['customer_reward_id']);
                $this->increment('rewards_missing_customer');

                return;
            }
            $this->record('customer_reward', $row['customer_reward_id'], 'loyalty_transactions', [
                'user_id' => $user, 'order_id' => $this->mapped('order', $row['order_id']),
                'event_key' => 'watchline-opencart-reward-'.$row['customer_reward_id'], 'type' => 'legacy_import',
                'points' => (int) $row['points'], 'note' => $row['description'],
                'payload' => $this->json(['opencart' => $row, 'import_source' => 'watchline']),
                'created_at' => $this->date($row['date_added']) ?? now(),
            ]);
            $this->increment('rewards_imported');
        });
    }

    private function metadata(): void
    {
        $related = [];
        $this->each('blog_related_products', function (array $row) use (&$related): void {
            if ($product = $this->mapped('product', $row['related_id'])) {
                $related[$row['blog_id']][] = $product;
                $this->increment('blog_product_links');
            }
        });
        foreach ($related as $sourceId => $ids) {
            if ($id = $this->mapped('blog', $sourceId)) {
                $this->mergePayload('content_blog_posts', $id, 'blog_related_products', ['related_product_ids' => array_values(array_unique($ids))]);
            }
        }
        $options = [];
        $this->each('order_option', function (array $row) use (&$options): void {
            $options[$row['order_product_id']][] = $row;
        });
        foreach ($options as $sourceId => $rows) {
            if ($id = $this->mapped('order_product', $sourceId)) {
                $this->mergePayload('order_items', $id, 'order_options', ['options' => array_map(fn (array $row): array => ['name' => $row['name'], 'value' => $row['value'], 'type' => $row['type']], $rows), 'opencart_order_options' => $rows]);
                $this->increment('order_options_imported', count($rows));
            }
        }
        $history = [];
        $this->each('customer_history', function (array $row) use (&$history): void {
            $history[$row['customer_id']][] = $row;
        });
        foreach ($history as $sourceId => $rows) {
            if ($id = $this->mapped('profile', $sourceId)) {
                $this->mergePayload('user_profiles', $id, 'customer_history', ['opencart_customer_history' => $rows]);
                $this->increment('customer_history_imported', count($rows));
            }
        }
        $this->each('question', function (array $row): void {
            if ($id = $this->mapped('product', $row['product_id'])) {
                $this->mergePayload('products', $id, 'question_'.$row['question_id'], ['opencart_question_'.$row['question_id'] => $row]);
            }
        });
        $cleaner = app(ImportedDescriptionHtmlCleaner::class);
        $this->each('manufacturer_description', function (array $row) use ($cleaner): void {
            if ((int) $row['language_id'] !== 2 || ! $id = $this->mapped('manufacturer', $row['manufacturer_id'])) {
                return;
            }
            $translation = DB::table('catalog_manufacturer_translations')->where('manufacturer_id', $id)->where('locale', 'hr')->first();
            if (! $translation || isset($this->records['manufacturer_description'][(string) $row['manufacturer_id']])) {
                return;
            }
            $fields = [];
            foreach (['description' => 'description', 'meta_description' => 'meta_description', 'meta_title' => 'custom_title'] as $field => $sourceField) {
                if (trim((string) $translation->{$field}) === '' && trim((string) ($row[$sourceField] ?? '')) !== '') {
                    $fields[$field] = $field === 'description' ? $cleaner->clean(html_entity_decode($row[$sourceField], ENT_QUOTES | ENT_HTML5, 'UTF-8')) : $row[$sourceField];
                }
            }
            if ($fields !== []) {
                DB::table('catalog_manufacturer_translations')->where('id', $translation->id)->update($fields + ['updated_at' => now()]);
                $this->increment('manufacturer_descriptions_imported');
            }
            $this->remember('manufacturer_description', $row['manufacturer_id'], (int) $translation->id, $row);
        });
    }

    private function anchorPrices(): void
    {
        $anchors = [];
        $this->each('anchor_price', function (array $row) use (&$anchors): void {
            if ((int) ($row['store_id'] ?? 0) !== 0 || strtoupper($row['currency_code'] ?? '') !== 'EUR'
                || ($row['verification_status'] ?? '') !== 'confirmed' || ! $this->mapped('product', $row['product_id'])) {
                return;
            }
            $old = $anchors[$row['product_id']] ?? null;
            if ($old === null || [$row['reference_date'], (int) $row['anchor_price_id']] > [$old['reference_date'], (int) $old['anchor_price_id']]) {
                $anchors[$row['product_id']] = $row;
            }
        });
        foreach ($anchors as $sourceId => $row) {
            $this->mergePayload('products', $this->mapped('product', $sourceId), 'anchor_price', [
                'watchline_anchor_price' => array_intersect_key($row, array_flip(['price', 'gross_price', 'currency_code', 'reference_date', 'rule_code', 'source', 'verification_status'])),
            ]);
            $this->increment('anchor_prices_imported');
        }
    }

    private function archive(): void
    {
        // Preserve extension/history objects that have no native editor yet.
        foreach ([
            'anchor_price', 'anchor_price_audit', 'anchor_price_publication', 'banner', 'banner_image',
            'module', 'layout', 'layout_module', 'layout_route', 'stock_status', 'currency', 'language',
            'category_path', 'category_canonical', 'product_canonical', 'custom_canonical',
            'manufacturer_description', 'manufacturer_to_store', 'product_to_store', 'product_to_layout',
            'category_to_store', 'category_to_layout', 'information_to_store', 'information_to_layout',
            'blog_to_store', 'blog_to_layout', 'blog_related_products', 'tf_filter', 'tf_filter_description',
            'tf_filter_value', 'tf_filter_value_description', 'tf_filter_to_category', 'tf_filter_value_to_product',
            'customer_history', 'customer_reward', 'customer_product_viewed', 'newsletter', 'cart', 'affiliate',
            'affiliate_transaction', 'order_option', 'order_shipment', 'boxnow_order', 'return', 'return_history',
            'return_action', 'return_reason', 'return_status', 'voucher_theme', 'voucher_theme_description',
            'question', 'testimonial', 'testimonial_description', 'testimonial_to_store', 'product_recurring',
            'recurring', 'recurring_description', 'product_tabs', 'product_tabs_description',
            'product_tabs_to_category', 'product_tabs_to_product', 'mega_sales', 'mega_category_to_sale',
            'mega_manufacturer_to_sale', 'mega_customer_group_to_sale', 'mega_menu', 'attemplate',
            'attemplate_attribute', 'attribute_presets', 'attribute_presets_description', 'marketing', 'location',
            'ebabandonedcart', 'ebabandonedcart_description', 'ebabandonedcart_email_coupon',
            'ebabandonedcart_email_template', 'ebabandonedcart_email_template_description', 'ebcart_coupon',
            'ebnotify_history', 'eborder', 'orderreviews_log', 'orderreviews_mail', 'orderreviews_mail_log',
        ] as $table) {
            $count = 0;
            $this->each($table, function (array $row) use ($table, &$count): void {
                // Module settings may contain credentials; the original SQL remains the complete backup.
                if ($table === 'module') {
                    $row = array_intersect_key($row, array_flip(['module_id', 'name', 'code']));
                }
                $this->retain($table, $row);
                $count++;
            });
            if ($count > 0) {
                $this->stats['retained_'.$table] = $count;
            }
        }
    }

    private function record(string $entity, int|string $sourceId, string $table, array $fields): int
    {
        $existing = $this->records[$entity][(string) $sourceId] ?? null;
        if ($existing !== null) {
            // A later moderation, unsubscribe or deletion belongs to the new shop.
            return $existing;
        }

        return DB::transaction(function () use ($entity, $sourceId, $table, $fields): int {
            $id = DB::table($table)->insertGetId($fields + ['created_at' => now(), 'updated_at' => now()]);
            $this->remember($entity, $sourceId, $id, $fields);

            return $id;
        });
    }

    private function remember(string $entity, int|string $sourceId, int $id, array $row): void
    {
        DB::table('herrera_import_maps')->updateOrInsert(['source' => 'watchline-opencart', 'entity' => $entity, 'source_id' => (string) $sourceId], [
            'target_id' => $id, 'checksum' => hash('sha256', $this->json($row)), 'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->records[$entity][(string) $sourceId] = $id;
    }

    private function mergePayload(string $table, int $id, string $key, array $fields): void
    {
        $raw = DB::table($table)->where('id', $id)->value('payload');
        if ($raw === null && ! DB::table($table)->where('id', $id)->exists()) {
            return;
        }
        $payload = json_decode($raw ?: '{}', true) ?: [];
        if (isset($payload['watchline_imported'][$key])) {
            return;
        }
        foreach ($fields as $field => $value) {
            $payload[$field] ??= $value;
        }
        $payload['watchline_imported'][$key] = true;
        DB::table($table)->where('id', $id)->update(['payload' => $this->json($payload), 'updated_at' => now()]);
    }

    private function retain(string $table, array $row, ?string $key = null): void
    {
        $safe = array_diff_key($row, array_flip(['password', 'salt', 'token', 'session_id', 'ip', 'forwarded_ip', 'request_payload', 'response_payload']));
        $json = $this->json($safe);
        DB::table('herrera_source_records')->updateOrInsert(['source_table' => $table, 'source_key' => $key ?? hash('sha256', $json)], [
            'checksum' => hash('sha256', $json), 'payload' => $json, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function mapped(string $entity, int|string $id): ?int
    {
        return $this->maps[$entity][(string) $id] ?? null;
    }

    private function has(string $table): bool
    {
        return $this->source->getSchemaBuilder()->hasTable($this->prefix.$table);
    }

    private function each(string $table, callable $callback): void
    {
        if (! $this->has($table)) {
            return;
        }
        $query = $this->source->table($this->prefix.$table);
        foreach (array_slice($this->source->getSchemaBuilder()->getColumnListing($this->prefix.$table), 0, 2) as $column) {
            $query->orderBy($column);
        }
        $query->chunk(500, function ($rows) use ($callback): void {
            foreach ($rows as $row) {
                $callback((array) $row);
            }
        });
    }

    private function increment(string $key, int $amount = 1): void
    {
        $this->stats[$key] = ($this->stats[$key] ?? 0) + $amount;
    }

    private function date(?string $value): ?string
    {
        return ! $value || str_starts_with($value, '0000-') ? null : $value;
    }

    private function json(array $value): string
    {
        return json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    }
}
