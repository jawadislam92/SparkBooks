<?php

namespace App\Models;

use App\Enums\ContactType;
use App\Models\Concerns\BelongsToCompany;
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
}
