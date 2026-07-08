<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;

class ActivityLog extends Model
{
    use BelongsToCompany;

    public const UPDATED_AT = null;
    protected $table = 'activity_log';
    protected $guarded = [];

    protected function casts(): array
    {
        return ['before' => 'array', 'after' => 'array'];
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new \LogicException('Audit log rows are immutable.'));
        static::deleting(fn () => throw new \LogicException('Audit log rows are immutable.'));
    }
}
