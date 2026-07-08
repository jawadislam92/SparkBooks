<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;

/**
 * One row per company per `ledger:verify` invocation (scheduled or manual,
 * via the Settings > Health "Run now" button). `findings` is null exactly
 * when `clean` is true — an empty findings array is never persisted as
 * "clean" by inference, the flag is always set explicitly by the caller.
 */
class IntegrityRun extends Model
{
    use BelongsToCompany;

    public $timestamps = false;

    protected $fillable = ['ran_at', 'findings', 'clean'];

    protected function casts(): array
    {
        return [
            'ran_at' => 'datetime',
            'findings' => 'array',
            'clean' => 'boolean',
        ];
    }
}
