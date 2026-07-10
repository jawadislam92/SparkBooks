<?php

namespace App\Models;

use App\Enums\ExpenseStatus;
use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;

class Expense extends Model
{
    use BelongsToCompany, HasFactory;

    /**
     * `company_id` is deliberately absent — BelongsToCompany sets it on
     * create from CompanyContext; it must never be accepted from request
     * input (see BelongsToCompany::bootBelongsToCompany).
     *
     * Line-level edit freezing lives on ExpenseLine (see its booted()), not
     * here: a recorded expense's own status/recorded_at/voided_at fields
     * legitimately keep changing via the record/void services that will
     * land in later Phase 4 tasks — only the LINES are frozen once the
     * expense has left draft status.
     */
    protected $fillable = [
        'contact_id',
        'status',
        'expense_date',
        'bank_account_id',
        'subtotal',
        'tax_total',
        'total',
        'notes',
        'created_by',
        'recorded_at',
        'voided_at',
    ];

    protected function casts(): array
    {
        return [
            'status' => ExpenseStatus::class,
            'expense_date' => 'date',
            'subtotal' => 'integer',
            'tax_total' => 'integer',
            'total' => 'integer',
            'recorded_at' => 'datetime',
            'voided_at' => 'datetime',
        ];
    }

    public function contact(): BelongsTo
    {
        return $this->belongsTo(Contact::class);
    }

    public function bankAccount(): BelongsTo
    {
        return $this->belongsTo(BankAccount::class);
    }

    public function lines(): HasMany
    {
        return $this->hasMany(ExpenseLine::class);
    }

    public function attachments(): MorphMany
    {
        return $this->morphMany(Attachment::class, 'attachable');
    }
}
