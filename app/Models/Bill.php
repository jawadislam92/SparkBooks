<?php

namespace App\Models;

use App\Enums\BillStatus;
use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\MorphMany;

class Bill extends Model
{
    use BelongsToCompany, HasFactory;

    /**
     * `company_id` is deliberately absent — BelongsToCompany sets it on
     * create from CompanyContext; it must never be accepted from request
     * input (see BelongsToCompany::bootBelongsToCompany).
     *
     * Line-level edit freezing lives on BillLine (see its booted()), not
     * here: a posted bill's own status/amount_paid/received_at/voided_at
     * fields legitimately keep changing via the receive/payment/void
     * services that will land in later Phase 4 tasks — only the LINES are
     * frozen once the bill has left draft status.
     */
    protected $fillable = [
        'contact_id',
        'reference',
        'status',
        'bill_date',
        'due_date',
        'subtotal',
        'tax_total',
        'total',
        'amount_paid',
        'balance',
        'is_opening',
        'notes',
        'created_by',
        'received_at',
        'voided_at',
    ];

    protected function casts(): array
    {
        return [
            'status' => BillStatus::class,
            'bill_date' => 'date',
            'due_date' => 'date',
            'subtotal' => 'integer',
            'tax_total' => 'integer',
            'total' => 'integer',
            'amount_paid' => 'integer',
            'balance' => 'integer',
            'is_opening' => 'boolean',
            'received_at' => 'datetime',
            'voided_at' => 'datetime',
        ];
    }

    public function contact(): BelongsTo
    {
        return $this->belongsTo(Contact::class);
    }

    public function lines(): HasMany
    {
        return $this->hasMany(BillLine::class);
    }

    public function payments(): HasMany
    {
        return $this->hasMany(BillPayment::class);
    }

    public function attachments(): MorphMany
    {
        return $this->morphMany(Attachment::class, 'attachable');
    }

    /**
     * The Phase 4 opening_documents row this is_opening bill represents
     * (Task 7) — null for every ordinary (non-opening) bill. Exact AP-side
     * mirror of Invoice::openingDocument().
     */
    public function openingDocument(): HasOne
    {
        return $this->hasOne(OpeningDocument::class);
    }
}
