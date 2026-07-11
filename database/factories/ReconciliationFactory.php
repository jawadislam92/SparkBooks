<?php

namespace Database\Factories;

use App\Models\BankAccount;
use Illuminate\Database\Eloquent\Factories\Factory;

class ReconciliationFactory extends Factory
{
    public function definition(): array
    {
        return [
            'bank_account_id' => BankAccount::factory(),
            'statement_date' => now()->toDateString(),
            'statement_ending_balance' => fake()->numberBetween(0, 1000000),
            'opening_reconciled_balance' => 0,
            'status' => 'completed',
            'completed_by' => null,
            'completed_at' => now(),
        ];
    }
}
