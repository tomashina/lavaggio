<?php

namespace App\Models\Sales;

use App\Models\Sales\Order\Order;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Crypt;

class GiftVoucher extends Model
{
    public const STATUS_PENDING = 'pending';

    public const STATUS_ACTIVE = 'active';

    public const STATUS_EXHAUSTED = 'exhausted';

    public const STATUS_DISABLED = 'disabled';

    public const STATUS_CANCELLED = 'cancelled';

    protected $guarded = ['id'];

    protected $hidden = ['code_hash', 'code_ciphertext', 'email_claim_token'];

    protected $casts = [
        'initial_amount' => 'decimal:2', 'balance' => 'decimal:2',
        'issued_at' => 'datetime', 'email_sent_at' => 'datetime', 'last_email_sent_at' => 'datetime',
        'email_claimed_at' => 'datetime', 'email_attempts' => 'integer',
        'disabled_at' => 'datetime', 'cancelled_at' => 'datetime', 'expires_at' => 'datetime',
    ];

    public function purchaseOrder(): BelongsTo
    {
        return $this->belongsTo(Order::class, 'purchase_order_id');
    }

    public function redemptions(): HasMany
    {
        return $this->hasMany(GiftVoucherRedemption::class)->latest('id');
    }

    public function getCodeAttribute(): ?string
    {
        if (! $this->code_ciphertext) {
            return null;
        }
        try {
            return Crypt::decryptString($this->code_ciphertext);
        } catch (\Throwable) {
            return null;
        }
    }

    public function storeCode(string $code): void
    {
        $code = strtoupper(trim($code));
        $this->code_hash = hash('sha256', $code);
        $this->code_ciphertext = Crypt::encryptString($code);
        $this->code_suffix = substr($code, -6);
    }

    public function isExpired(): bool
    {
        return $this->expires_at !== null && $this->expires_at->isPast();
    }

    public function isUsable(): bool
    {
        return $this->status === self::STATUS_ACTIVE && ! $this->isExpired() && $this->issued_at && $this->code_hash && (float) $this->balance > 0;
    }

    public function getDisplayStatusAttribute(): string
    {
        if ($this->isExpired()) {
            return 'Istekao';
        }

        return [self::STATUS_PENDING => 'Čeka plaćanje', self::STATUS_ACTIVE => 'Aktivan', self::STATUS_EXHAUSTED => 'Iskorišten', self::STATUS_DISABLED => 'Onemogućen', self::STATUS_CANCELLED => 'Otkazan'][$this->status] ?? $this->status;
    }
}
