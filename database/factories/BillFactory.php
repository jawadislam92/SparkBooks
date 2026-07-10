<?php

namespace Database\Factories;

use App\Enums\BillStatus;
use App\Models\Contact;
use Illuminate\Database\Eloquent\Factories\Factory;

class BillFactory extends Factory
{
    public function definition(): array
    {
        return [
            // NOT NULL, unlike Expense's contact_id — a bill always names a
            // vendor (Phase 4 brief).
            'contact_id' => Contact::factory(),
            'reference' => null,
            'status' => BillStatus::Draft->value,
            'bill_date' => null,
            'due_date' => null,
            'subtotal' => 0,
            'tax_total' => 0,
            'total' => 0,
            'amount_paid' => 0,
            'balance' => 0,
            'is_opening' => false,
            'notes' => null,
            'created_by' => null,
            'received_at' => null,
            'voided_at' => null,
        ];
    }
}
