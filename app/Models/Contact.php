<?php

namespace App\Models;

use App\Enums\ContactType;
use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Contact extends Model
{
    use BelongsToCompany, HasFactory;

    protected $fillable = [
        'type',
        'name',
        'email',
        'phone',
        'billing_address',
        'tax_number',
        'notes',
        'is_archived',
    ];

    protected function casts(): array
    {
        return [
            'type' => ContactType::class,
            'is_archived' => 'boolean',
        ];
    }

    /** Every non-archived contact for this company — the shared filter every invoice/bill/expense contact picker applies. Mirrors Account/Item/BankAccount's own scopeActive. */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_archived', false);
    }

    /** Every archived contact for this company — Contacts/Index's own "Show archived" filter. */
    public function scopeArchived(Builder $query): Builder
    {
        return $query->where('is_archived', true);
    }
}
