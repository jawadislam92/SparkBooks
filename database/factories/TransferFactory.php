<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;

class TransferFactory extends Factory
{
    public function definition(): array
    {
        return [
            'transfer_date' => now()->toDateString(),
            'amount' => fake()->numberBetween(1000, 100000),
            'memo' => fake()->sentence(),
            'created_by' => null,
        ];
    }
}
