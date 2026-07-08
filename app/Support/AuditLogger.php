<?php

namespace App\Support;

use App\Models\ActivityLog;
use Illuminate\Database\Eloquent\Model;

class AuditLogger
{
    public function log(string $action, ?Model $subject = null, ?array $before = null, ?array $after = null): ActivityLog
    {
        return ActivityLog::query()->create([
            'user_id' => auth()->id(),
            'action' => $action,
            'subject_type' => $subject?->getMorphClass(),
            'subject_id' => $subject?->getKey(),
            'before' => $before,
            'after' => $after,
            'ip' => request()?->ip(),
        ]);
    }
}
