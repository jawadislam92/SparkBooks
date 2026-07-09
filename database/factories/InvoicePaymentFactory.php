<?php

namespace Database\Factories;

use App\Models\BankAccount;
use App\Models\Invoice;
use Illuminate\Database\Eloquent\Factories\Factory;

class InvoicePaymentFactory extends Factory
{
    public function definition(): array
    {
        return [
            'invoice_id' => Invoice::factory(),
            'payment_date' => now()->toDateString(),
            'amount' => fake()->numberBetween(1000, 100000),
            'base_amount' => fake()->numberBetween(1000, 100000),
            'bank_account_id' => BankAccount::factory(),
            'fx_rate' => null,
            'status' => 'posted',
            'memo' => null,
            'created_by' => null,
        ];
    }
}
