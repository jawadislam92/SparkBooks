<?php

namespace Database\Factories;

use App\Enums\InvoiceStatus;
use App\Models\Contact;
use Illuminate\Database\Eloquent\Factories\Factory;

class InvoiceFactory extends Factory
{
    public function definition(): array
    {
        return [
            'contact_id' => Contact::factory(),
            'number' => null,
            'status' => InvoiceStatus::Draft->value,
            'issue_date' => null,
            'due_date' => null,
            'currency_code' => 'PKR',
            'fx_rate' => 1,
            'subtotal' => 0,
            'tax_total' => 0,
            'total' => 0,
            'amount_paid' => 0,
            'balance' => 0,
            'base_subtotal' => 0,
            'base_tax_total' => 0,
            'base_total' => 0,
            'is_opening' => false,
            'notes' => null,
            'created_by' => null,
            'sent_at' => null,
            'voided_at' => null,
            'email_status' => 'not_sent',
        ];
    }
}
