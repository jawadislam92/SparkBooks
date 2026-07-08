<?php

namespace App\Models;

use App\Enums\AccountSubtype;
use App\Enums\AccountType;
use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use LogicException;

class Account extends Model
{
    use BelongsToCompany, HasFactory;

    protected $fillable = ['code', 'name', 'type', 'subtype', 'is_system', 'is_archived', 'description'];

    protected function casts(): array
    {
        return [
            'type' => AccountType::class,
            'subtype' => AccountSubtype::class,
            'is_system' => 'boolean',
            'is_archived' => 'boolean',
        ];
    }

    public function isControlAccount(): bool
    {
        return in_array($this->subtype, [AccountSubtype::Receivable, AccountSubtype::Payable], true);
    }

    protected static function booted(): void
    {
        static::updating(function (Account $account) {
            if ($account->is_system && $account->isDirty(['code', 'type', 'subtype'])) {
                throw new LogicException('System accounts cannot change code, type, or subtype.');
            }
        });

        static::deleting(function () {
            throw new LogicException('Accounts are archived, never deleted.');
        });
    }
}
