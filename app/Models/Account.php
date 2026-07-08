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
            // Base protection on the ORIGINAL flag, not the (fillable, possibly
            // just-mutated) live attribute — otherwise update(['is_system' => false,
            // 'code' => 'X']) would demote then retype a built-in in one call.
            // Also forbid demoting a built-in account via is_system itself.
            if ($account->getOriginal('is_system')
                && $account->isDirty(['is_system', 'code', 'type', 'subtype'])) {
                throw new LogicException('System accounts cannot change is_system, code, type, or subtype.');
            }
        });

        static::deleting(function () {
            throw new LogicException('Accounts are archived, never deleted.');
        });
    }
}
