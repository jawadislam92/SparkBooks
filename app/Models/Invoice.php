<?php

namespace App\Models;

use App\Enums\InvoiceStatus;
use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Invoice extends Model
{
    use BelongsToCompany, HasFactory;

    /**
     * `company_id` is deliberately absent — BelongsToCompany sets it on
     * create from CompanyContext; it must never be accepted from request
     * input (see BelongsToCompany::bootBelongsToCompany).
     *
     * Line-level edit freezing lives on InvoiceLine (see its booted()), not
     * here: a posted invoice's own status/amount_paid/sent_at/voided_at
     * fields legitimately keep changing via the send/payment/void services
     * that will land in later Phase 3 tasks — only the LINES are frozen once
     * posted.
     */
    protected $fillable = [
        'contact_id',
        'number',
        'status',
        'issue_date',
        'due_date',
        'currency_code',
        'fx_rate',
        'subtotal',
        'tax_total',
        'total',
        'amount_paid',
        'balance',
        'base_subtotal',
        'base_tax_total',
        'base_total',
        'is_opening',
        'notes',
        'created_by',
        'sent_at',
        'voided_at',
        'email_status',
    ];

    protected function casts(): array
    {
        return [
            'status' => InvoiceStatus::class,
            'issue_date' => 'date',
            'due_date' => 'date',
            'fx_rate' => 'decimal:8',
            'subtotal' => 'integer',
            'tax_total' => 'integer',
            'total' => 'integer',
            'amount_paid' => 'integer',
            'balance' => 'integer',
            'base_subtotal' => 'integer',
            'base_tax_total' => 'integer',
            'base_total' => 'integer',
            'is_opening' => 'boolean',
            'sent_at' => 'datetime',
            'voided_at' => 'datetime',
        ];
    }

    public function contact(): BelongsTo
    {
        return $this->belongsTo(Contact::class);
    }

    public function lines(): HasMany
    {
        return $this->hasMany(InvoiceLine::class);
    }

    public function payments(): HasMany
    {
        return $this->hasMany(InvoicePayment::class);
    }

    /**
     * The Phase 2 opening_documents row this is_opening invoice represents
     * (Phase 3 Task 6) — null for every ordinary (non-opening) invoice.
     */
    public function openingDocument(): HasOne
    {
        return $this->hasOne(OpeningDocument::class);
    }
}
