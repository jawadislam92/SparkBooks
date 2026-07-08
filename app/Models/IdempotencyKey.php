<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;

class IdempotencyKey extends Model
{
    use BelongsToCompany;

    public const UPDATED_AT = null;
    protected $guarded = [];

    protected function casts(): array
    {
        return ['response_snapshot' => 'array'];
    }
}
