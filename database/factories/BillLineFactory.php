<?php

namespace Database\Factories;

use App\Models\Account;
use App\Models\Bill;
use Illuminate\Database\Eloquent\Factories\Factory;

class BillLineFactory extends Factory
{
    public function definition(): array
    {
        return [
            'bill_id' => Bill::factory(),
            'description' => fake()->sentence(4),
            // Nested factory: mirrors ExpenseLineFactory's nested
            // category_account_id. AccountFactory's own default is already
            // Expense/OperatingExpense, so no state() override is needed.
            'category_account_id' => Account::factory(),
            'tax_rate_id' => null,
            'tax_rate_snapshot' => null,
            'amount' => fake()->numberBetween(1000, 100000),
            'line_tax' => 0,
        ];
    }
}
