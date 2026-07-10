<?php

namespace Database\Factories;

use App\Models\Account;
use App\Models\Expense;
use Illuminate\Database\Eloquent\Factories\Factory;

class ExpenseLineFactory extends Factory
{
    public function definition(): array
    {
        return [
            'expense_id' => Expense::factory(),
            'description' => fake()->sentence(4),
            // Nested factory: creates its own linked category Account so a
            // bare ExpenseLine::factory()->create() is usable on its own,
            // mirroring InvoiceLineFactory's nested income_account_id.
            // AccountFactory's own default is already Expense/
            // OperatingExpense, so no state() override is needed here.
            'category_account_id' => Account::factory(),
            'tax_rate_id' => null,
            'tax_rate_snapshot' => null,
            'amount' => fake()->numberBetween(1000, 100000),
            'line_tax' => 0,
        ];
    }
}
