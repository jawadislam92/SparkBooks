<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;

class OpeningBalanceRunFactory extends Factory
{
    public function definition(): array
    {
        return [
            'books_start_date' => now()->toDateString(),
            'status' => 'active',
            'created_by' => null,
        ];
    }
}
