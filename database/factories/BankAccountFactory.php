<?php

namespace Database\Factories;

use App\Enums\AccountSubtype;
use App\Enums\AccountType;
use App\Models\Account;
use Illuminate\Database\Eloquent\Factories\Factory;

class BankAccountFactory extends Factory
{
    public function definition(): array
    {
        return [
            'name' => fake()->company().' Account',
            'type' => 'bank',
            'currency_code' => 'PKR',
            // Nested factory: creates its own linked Account with a plausible
            // money-band code so a bare BankAccount::factory()->create() (no
            // overrides) is usable on its own in tests that don't care about
            // the exact linked account, without colliding with the
            // BankAccountService band-scanning logic under test elsewhere.
            'ledger_account_id' => Account::factory()->state([
                'code' => fake()->unique()->numerify('10##'),
                'type' => AccountType::Asset->value,
                'subtype' => AccountSubtype::Bank->value,
            ]),
            'is_archived' => false,
        ];
    }
}
