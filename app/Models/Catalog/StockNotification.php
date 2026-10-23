<?php

namespace App\Models\Catalog;

use App\Models\Catalog\Bundle\Bundle;
use App\Models\Catalog\Product\Product;
use App\Models\Catalog\Product\ProductOptionValue;
use App\Models\User;
use App\Services\Catalog\StockNotificationService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class StockNotification extends Model
{
    public const WAITING = 'waiting';

    public const PROCESSING = 'processing';

    public const SENT = 'sent';

    public const FAILED = 'failed';

    public const CANCELLED = 'cancelled';

    protected $guarded = ['id'];

    protected $hidden = ['token', 'claim_token'];

    protected $casts = ['consented_at' => 'datetime', 'sent_at' => 'datetime', 'last_attempt_at' => 'datetime', 'attempts' => 'integer'];

    protected static function booted(): void
    {
        static::saving(function (self $notification): void {
            $notification->email = mb_strtolower(trim($notification->email));
            $notification->token ??= Str::random(64);
            $notification->target_key ??= $notification->bundle_id ? 'bundle:'.$notification->bundle_id : 'product:'.$notification->product_id.':option:'.($notification->product_option_value_id ?? 0);
        });
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function bundle(): BelongsTo
    {
        return $this->belongsTo(Bundle::class);
    }

    public function optionValue(): BelongsTo
    {
        return $this->belongsTo(ProductOptionValue::class, 'product_option_value_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function displayName(): string
    {
        if ($this->bundle_id) {
            return $this->bundle?->title ?? 'Uklonjeni komplet';
        }
        $translations = $this->product?->translations;
        $name = $translations?->firstWhere('locale', $this->locale)?->name
            ?? $translations?->firstWhere('locale', 'hr')?->name
            ?? $translations?->first()?->name
            ?? $this->product?->sku ?? 'Uklonjeni artikl';
        if (! $this->product_option_value_id) {
            return $name;
        }
        $labels = collect([$this->optionValue?->parentOptionValue, $this->optionValue?->optionValue])
            ->filter()->map(fn ($value) => $value->translations->firstWhere('locale', $this->locale)?->name
                ?? $value->translations->firstWhere('locale', 'hr')?->name ?? $value->code)->filter()->implode(' / ');

        return $labels === '' ? $name : $name.' · '.$labels;
    }

    public function isAvailable(): bool
    {
        return app(StockNotificationService::class)->isAvailable($this);
    }

    public function storefrontUrl(): string
    {
        if ($this->bundle_id) {
            return route('bundles.index').'#bundle-'.$this->bundle_id;
        }
        $translations = $this->product?->translations;
        $slug = $translations?->firstWhere('locale', $this->locale)?->slug ?? $translations?->firstWhere('locale', 'hr')?->slug;
        if (! $slug) {
            return route('shop.index');
        }
        $parameters = ['slug' => $slug];
        if ($this->product_option_value_id) {
            $parameters['product_option_value_id'] = $this->product_option_value_id;
        }

        return route('products.show', $parameters);
    }
}
