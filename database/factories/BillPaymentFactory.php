<?php

namespace Database\Factories;

use App\Models\BankAccount;
use App\Models\Bill;
use Illuminate\Database\Eloquent\Factories\Factory;

class BillPaymentFactory extends Factory
{
    public function definition(): array
    {
        return [
            'bill_id' => Bill::factory(),
            'payment_date' => now()->toDateString(),
            'amount' => fake()->numberBetween(1000, 100000),
            'bank_account_id' => BankAccount::factory(),
            'status' => 'posted',
            'memo' => null,
            'created_by' => null,
        ];
    }
}
