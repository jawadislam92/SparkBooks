<?php

namespace Database\Factories;

use App\Models\Reconciliation;
use Illuminate\Database\Eloquent\Factories\Factory;

class ReconciliationLineFactory extends Factory
{
    public function definition(): array
    {
        return [
            'reconciliation_id' => Reconciliation::factory(),
            // journal_lines has no factory of its own (every one in this app
            // is created via a real posting flow — see LedgerService::post);
            // tests that need a real ReconciliationLine create the
            // journal_line first and pass its id in explicitly.
            'journal_line_id' => null,
            'amount' => fake()->numberBetween(-1000000, 1000000),
        ];
    }
}
