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
