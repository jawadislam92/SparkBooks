<?php

namespace Database\Factories;

use App\Models\Contact;
use App\Models\OpeningBalanceRun;
use Illuminate\Database\Eloquent\Factories\Factory;

class OpeningDocumentFactory extends Factory
{
    public function definition(): array
    {
        return [
            'opening_balance_run_id' => OpeningBalanceRun::factory(),
            'contact_id' => Contact::factory(),
            'kind' => 'receivable',
            'reference' => fake()->bothify('OB-####'),
            'original_date' => now()->subMonth()->toDateString(),
            'due_date' => now()->toDateString(),
            'amount' => fake()->numberBetween(1000, 100000),
            'status' => 'open',
        ];
    }
}
