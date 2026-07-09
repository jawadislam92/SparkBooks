<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Item extends Model
{
    use BelongsToCompany, HasFactory;

    /**
     * `company_id` is deliberately absent — BelongsToCompany sets it on
     * create from CompanyContext; it must never be accepted from request
     * input (see BelongsToCompany::bootBelongsToCompany).
     */
    protected $fillable = [
        'name',
        'description',
        'unit_price',
        'default_income_account_id',
        'default_tax_rate_id',
        'is_archived',
    ];

    protected function casts(): array
    {
        return [
            'unit_price' => 'integer',
            'is_archived' => 'boolean',
        ];
    }

    public function incomeAccount(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'default_income_account_id');
    }

    public function taxRate(): BelongsTo
    {
        return $this->belongsTo(TaxRate::class, 'default_tax_rate_id');
    }
}
