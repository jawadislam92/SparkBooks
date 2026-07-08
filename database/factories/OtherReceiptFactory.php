<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;

class OtherReceiptFactory extends Factory
{
    public function definition(): array
    {
        return [
            'receipt_date' => now()->toDateString(),
            'amount' => fake()->numberBetween(1000, 100000),
            'payer' => fake()->name(),
            'memo' => fake()->sentence(),
            'created_by' => null,
        ];
    }
}
