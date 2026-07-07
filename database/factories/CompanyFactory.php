<?php
namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;

class CompanyFactory extends Factory
{
    public function definition(): array
    {
        return [
            'name' => fake()->company(),
            'base_currency' => 'PKR',
            'fiscal_year_start_month' => 1,
        ];
    }
}
