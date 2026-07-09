<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class InvoicePayment extends Model
{
    use BelongsToCompany, HasFactory;

    /**
     * `company_id` is deliberately absent — BelongsToCompany sets it on
     * create from CompanyContext; it must never be accepted from request
     * input (see BelongsToCompany::bootBelongsToCompany).
     */
    protected $fillable = [
        'invoice_id',
        'payment_date',
        'amount',
        'base_amount',
        'base_ar_relief',
        'bank_account_id',
        'fx_rate',
        'status',
        'memo',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'payment_date' => 'date',
            'amount' => 'integer',
            'base_amount' => 'integer',
            // NOT NULL (Task 8, spec §17.1 P2fx) — always populated by
            // InvoicePaymentService::recordPayment (the sole creation path)
            // and the factory; see the migration docblock for why nullability
            // would reintroduce the SUM()-treats-NULL-as-0 settlement bug.
            'base_ar_relief' => 'integer',
            'fx_rate' => 'decimal:8',
        ];
    }

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }

    public function bankAccount(): BelongsTo
    {
        return $this->belongsTo(BankAccount::class);
    }
}
