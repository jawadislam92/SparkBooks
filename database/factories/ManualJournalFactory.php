<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;

class ManualJournalFactory extends Factory
{
    public function definition(): array
    {
        return [
            'entry_date' => now()->toDateString(),
            'memo' => fake()->sentence(),
            'status' => 'posted',
            'created_by' => null,
        ];
    }
}
