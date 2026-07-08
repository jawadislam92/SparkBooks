<?php

namespace App\Support;

use App\Models\IdempotencyKey;
use Closure;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

class IdempotencyService
{
    public function execute(string $key, string $action, Closure $operation): mixed
    {
        $existing = IdempotencyKey::query()->where('key', $key)->first();
        if ($existing) {
            return $existing->response_snapshot;
        }

        try {
            return DB::transaction(function () use ($key, $action, $operation) {
                $record = IdempotencyKey::query()->create([
                    'key' => $key,
                    'action' => $action,
                    'user_id' => auth()->id(),
                ]);
                $result = $operation();
                $record->forceFill(['response_snapshot' => $result])->save();
                return $result;
            });
        } catch (UniqueConstraintViolationException) {
            // Lost a race with a concurrent identical request — replay its result.
            return IdempotencyKey::query()->where('key', $key)->firstOrFail()->response_snapshot;
        }
    }
}
