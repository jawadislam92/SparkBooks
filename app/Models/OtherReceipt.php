<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OtherReceipt extends Model
{
    use BelongsToCompany, HasFactory;

    protected $fillable = [
        'bank_account_id',
        'category_account_id',
        'receipt_date',
        'amount',
        'payer',
        'memo',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'receipt_date' => 'date',
            'amount' => 'integer',
        ];
    }

    public function bankAccount(): BelongsTo
    {
        return $this->belongsTo(BankAccount::class, 'bank_account_id');
    }

    public function categoryAccount(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'category_account_id');
    }

    /** The user who created this receipt — nullable, matching the column. */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
