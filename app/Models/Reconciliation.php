<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One completed (or since-reopened) bank statement reconciliation (spec
 * §7.9). Never writes a ledger entry itself — it only records which
 * journal_lines on the bank account's linked ledger account cleared the
 * statement, via the reconciliation_lines pivot (see ReconciliationLine).
 * `opening_reconciled_balance` is the prior completed reconciliation's own
 * `statement_ending_balance` (0 for the account's first) — the chain
 * ReconciliationService keeps consistent by only ever reopening the newest
 * completed reconciliation for an account (see reopen()'s docblock).
 */
class Reconciliation extends Model
{
    use BelongsToCompany, HasFactory;

    protected $fillable = [
        'bank_account_id',
        'statement_date',
        'statement_ending_balance',
        'opening_reconciled_balance',
        'status',
        'completed_by',
        'completed_at',
    ];

    protected function casts(): array
    {
        return [
            'statement_date' => 'date',
            'statement_ending_balance' => 'integer',
            'opening_reconciled_balance' => 'integer',
            'completed_at' => 'datetime',
        ];
    }

    public function bankAccount(): BelongsTo
    {
        return $this->belongsTo(BankAccount::class);
    }

    public function completedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'completed_by');
    }

    public function lines(): HasMany
    {
        return $this->hasMany(ReconciliationLine::class);
    }
}
