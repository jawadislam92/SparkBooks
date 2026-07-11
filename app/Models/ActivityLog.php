<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

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

    /** The actor who performed the audited action — nullable (user_id nullOnDelete, and a few system actions log with no auth()->id()). Added for the Audit log viewer (Phase 6 Task 2) to eager-load the actor's name for display. */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
