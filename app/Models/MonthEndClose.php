<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One row per company per month-end date — the lightweight close-progress
 * record for Phase 6 Task 5's month-end close checklist (spec §17.5). Holds
 * ONLY what can't be derived from existing tables: the two manual-acknowledge
 * steps (A/R+A/P aging review, Trial Balance review — there is no system
 * signal for "a human looked at this report") and the completed_at/by
 * marker written when the close itself is finished. The other four steps
 * (reconcile, drafts sweep, integrity check, period lock) are computed live
 * from real system state by MonthEndCloseService and never stored here.
 */
class MonthEndClose extends Model
{
    use BelongsToCompany, HasFactory;

    protected $fillable = [
        'month_end',
        'ar_ap_reviewed_at',
        'trial_balance_reviewed_at',
        'completed_at',
        'completed_by',
    ];

    protected function casts(): array
    {
        return [
            'month_end' => 'date',
            'ar_ap_reviewed_at' => 'datetime',
            'trial_balance_reviewed_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }

    public function completedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'completed_by');
    }
}
