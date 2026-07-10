<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;

class AttachmentFactory extends Factory
{
    public function definition(): array
    {
        // No attachable_id/attachable_type default — this factory is always
        // used via the polymorphic relation (e.g.
        // `$expense->attachments()->create(Attachment::factory()->raw())` or
        // `Attachment::factory()->for($expense, 'attachable')->create()`),
        // which sets both morph columns itself.
        return [
            'disk_path' => 'attachments/'.fake()->uuid().'.pdf',
            'original_name' => fake()->word().'.pdf',
            'mime' => 'application/pdf',
            'size' => fake()->numberBetween(1000, 500000),
            'uploaded_by' => null,
        ];
    }
}
