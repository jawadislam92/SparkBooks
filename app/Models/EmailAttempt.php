<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Per-attempt send log for a single invoice email (spec §17.6): one row per
 * SendInvoiceEmail run, success or failure — never updated after creation,
 * never deleted. Deliberately dumb (no relationship to a "retry group" or
 * similar) — the invoice's OWN email_status column is the current state;
 * this table is purely the append-only history behind it.
 */
class EmailAttempt extends Model
{
    use BelongsToCompany, HasFactory;

    /**
     * `company_id` is deliberately absent — BelongsToCompany sets it on
     * create from CompanyContext; it must never be accepted from request
     * input (see BelongsToCompany::bootBelongsToCompany).
     */
    protected $fillable = [
        'invoice_id',
        'recipient',
        'status',
        'error',
        'attempted_at',
    ];

    protected function casts(): array
    {
        return [
            'attempted_at' => 'datetime',
        ];
    }

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }
}
