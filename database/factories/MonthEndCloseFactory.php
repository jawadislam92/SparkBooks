<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;

class MonthEndCloseFactory extends Factory
{
    public function definition(): array
    {
        return [
            'month_end' => now()->endOfMonth()->toDateString(),
            'ar_ap_reviewed_at' => null,
            'trial_balance_reviewed_at' => null,
            'completed_at' => null,
            'completed_by' => null,
        ];
    }
}
