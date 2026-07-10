<?php

namespace App\Models;

use App\Enums\ExpenseStatus;
use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

class ExpenseLine extends Model
{
    use BelongsToCompany, HasFactory;

    /**
     * `company_id` is deliberately absent — BelongsToCompany sets it on
     * create from CompanyContext; it must never be accepted from request
     * input (see BelongsToCompany::bootBelongsToCompany).
     */
    protected $fillable = [
        'expense_id',
        'description',
        'category_account_id',
        'tax_rate_id',
        'tax_rate_snapshot',
        'amount',
        'line_tax',
    ];

    protected function casts(): array
    {
        return [
            'tax_rate_snapshot' => 'decimal:4',
            'amount' => 'integer',
            'line_tax' => 'integer',
        ];
    }

    public function expense(): BelongsTo
    {
        return $this->belongsTo(Expense::class);
    }

    public function categoryAccount(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'category_account_id');
    }

    public function taxRate(): BelongsTo
    {
        return $this->belongsTo(TaxRate::class);
    }

    /**
     * The draft-mutability freeze: once the parent expense has left draft
     * status, its lines are immutable. Correction is void + re-enter of the
     * whole expense, never an edit to a frozen line — mirrors
     * InvoiceLine::booted() (Phase 3 T1) exactly, including the "always
     * re-query fresh" reasoning below. Unlike InvoiceLine (which only
     * freezes on isPosted() — sent/partially_paid/paid, NOT void), this
     * guard freezes on ANY non-draft status including Void, per the Phase 4
     * brief: a voided expense's lines must not be editable either.
     */
    protected static function booted(): void
    {
        $guard = function (ExpenseLine $line) {
            // Deliberately NOT `$line->expense` — a belongsTo access caches
            // the relation on first load (e.g. during the `creating` event),
            // and a stale cached instance would still report the expense's
            // status as it was at creation time even after a later request
            // posts/voids the expense. Always re-query fresh so this guard
            // sees the expense's CURRENT status.
            $expense = Expense::query()->find($line->expense_id);

            if ($expense !== null && $expense->status !== ExpenseStatus::Draft) {
                throw new LogicException(
                    'Expense lines cannot be added, edited, or removed once the expense has left draft status. Void and re-enter the expense instead.'
                );
            }
        };

        static::creating($guard);
        static::updating($guard);
        static::deleting($guard);
    }
}
