<?php

namespace App\Models;

use App\Enums\PaymentState;
use Database\Factories\PaymentFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'order_id', 'provider', 'reference', 'state', 'amount_fils', 'currency', 'method',
    'mf_invoice_id', 'mf_payment_id', 'mf_payment_url', 'mf_method',
    'mf_transaction_id', 'mf_reference_id', 'mf_track_id',
    'failure_reason', 'anomaly', 'anomaly_reviewed_at', 'anomaly_reviewed_by',
    'expires_at', 'paid_at', 'verified_at',
])]
#[Hidden(['mf_payment_url'])]
class Payment extends Model
{
    /** @use HasFactory<PaymentFactory> */
    use HasFactory;

    public const ANOMALY_ORDER_CANCELLED = 'order_cancelled';

    public const ANOMALY_AMOUNT_MISMATCH = 'amount_mismatch';

    public const ANOMALY_DUPLICATE = 'duplicate_payment';

    protected function casts(): array
    {
        return [
            'state' => PaymentState::class,
            'amount_fils' => 'integer',
            'expires_at' => 'datetime',
            'paid_at' => 'datetime',
            'verified_at' => 'datetime',
            'anomaly_reviewed_at' => 'datetime',
        ];
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'anomaly_reviewed_by');
    }

    /** Flagged payments nobody has signed off yet. */
    #[Scope]
    protected function needsReview(Builder $query): void
    {
        $query->whereNotNull('anomaly')->whereNull('anomaly_reviewed_at');
    }

    /** Whether the hosted payment page can still be opened and paid. */
    public function isPayable(): bool
    {
        return $this->state === PaymentState::Pending
            && $this->mf_payment_url !== null
            && ($this->expires_at === null || $this->expires_at->isFuture());
    }
}
