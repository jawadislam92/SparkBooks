<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OpeningDocument extends Model
{
    use BelongsToCompany, HasFactory;

    protected $fillable = [
        'opening_balance_run_id',
        'contact_id',
        'invoice_id',
        'kind',
        'reference',
        'original_date',
        'due_date',
        'amount',
        'status',
    ];

    protected function casts(): array
    {
        return [
            'original_date' => 'date',
            'due_date' => 'date',
            'amount' => 'integer',
        ];
    }

    public function openingBalanceRun(): BelongsTo
    {
        return $this->belongsTo(OpeningBalanceRun::class);
    }

    public function contact(): BelongsTo
    {
        return $this->belongsTo(Contact::class);
    }

    /**
     * Null for a vendor (payable) document, and for a receivable document
     * created before Task 6 that hasn't been reconciled yet (see
     * ReconcileOpeningInvoices) — set for every receivable document
     * OpeningBalanceService::apply() creates going forward.
     */
    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }
}
