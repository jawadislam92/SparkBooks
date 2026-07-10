<?php

namespace Database\Factories;

use App\Enums\ExpenseStatus;
use Illuminate\Database\Eloquent\Factories\Factory;

class ExpenseFactory extends Factory
{
    public function definition(): array
    {
        return [
            // Nullable, unlike Invoice/Bill's contact_id — an expense's
            // payee is optional (Phase 4 brief). Defaults to null so a bare
            // Expense::factory()->create() doesn't require a Contact; tests
            // that need one override it explicitly.
            'contact_id' => null,
            'status' => ExpenseStatus::Draft->value,
            'expense_date' => null,
            'bank_account_id' => null,
            'subtotal' => 0,
            'tax_total' => 0,
            'total' => 0,
            'notes' => null,
            'created_by' => null,
            'recorded_at' => null,
            'voided_at' => null,
        ];
    }
}
