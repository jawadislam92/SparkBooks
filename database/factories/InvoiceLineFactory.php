<?php

namespace Database\Factories;

use App\Enums\AccountSubtype;
use App\Enums\AccountType;
use App\Models\Account;
use App\Models\Invoice;
use Illuminate\Database\Eloquent\Factories\Factory;

class InvoiceLineFactory extends Factory
{
    public function definition(): array
    {
        return [
            'invoice_id' => Invoice::factory(),
            'description' => fake()->sentence(4),
            'quantity' => 1,
            'unit_price' => fake()->numberBetween(1000, 100000),
            // Nested factory: creates its own linked income Account so a bare
            // InvoiceLine::factory()->create() is usable on its own, mirroring
            // BankAccountFactory's nested ledger_account_id.
            'income_account_id' => Account::factory()->state([
                'type' => AccountType::Income->value,
                'subtype' => AccountSubtype::OperatingIncome->value,
            ]),
            'tax_rate_id' => null,
            'tax_rate_snapshot' => null,
            'line_subtotal' => 0,
            'line_tax' => 0,
        ];
    }
}
