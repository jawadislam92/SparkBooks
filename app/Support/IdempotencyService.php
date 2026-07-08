<?php

namespace App\Support;

use App\Models\IdempotencyKey;
use Closure;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use LogicException;
use RuntimeException;

class IdempotencyService
{
    /**
     * The unique index that guards (company_id, key). Used to tell apart a
     * genuine idempotency race from an unrelated unique-constraint violation
     * thrown by the caller's own operation.
     */
    private const UNIQUE_INDEX = 'idempotency_keys_company_id_key_unique';

    /**
     * The connection's transaction level at the time this (scoped) instance
     * was constructed. execute() must not observe a HIGHER level than this,
     * otherwise it is running inside someone else's open transaction and its
     * rollback semantics would silently degrade to a savepoint.
     */
    private readonly int $baselineTransactionLevel;

    public function __construct()
    {
        $this->baselineTransactionLevel = DB::transactionLevel();
    }

    public function execute(string $key, string $action, Closure $operation): mixed
    {
        if (DB::transactionLevel() > $this->baselineTransactionLevel) {
            throw new LogicException(
                'IdempotencyService::execute must not be called inside an open transaction; it owns the transaction boundary.'
            );
        }

        $existing = IdempotencyKey::query()->where('key', $key)->first();
        if ($existing) {
            $this->assertActionMatches($existing, $action);
            return $existing->response_snapshot;
        }

        try {
            return DB::transaction(function () use ($key, $action, $operation) {
                $record = $this->claimKey($key, $action);
                $result = $operation();
                $record->forceFill(['response_snapshot' => $result])->save();
                return $result;
            });
        } catch (UniqueConstraintViolationException $e) {
            if (! $this->isIdempotencyKeyCollision($e)) {
                // Some other unique constraint was violated by the caller's own
                // operation (e.g. a duplicate invoice number) — this transaction
                // has already been rolled back, including our own key row, so
                // the caller's error must propagate untouched instead of being
                // misread as a lost idempotency race.
                throw $e;
            }

            // Lost a race with a concurrent identical request. Our own transaction
            // (or savepoint) has been rolled back by now, so re-query for the
            // winner's row on a fresh read. Use a locking read: a plain SELECT
            // could reuse a REPEATABLE READ snapshot taken before the winner's
            // row was committed and miss it entirely.
            $winner = IdempotencyKey::query()->where('key', $key)->lockForUpdate()->first();
            if (! $winner) {
                throw new RuntimeException(
                    "Idempotency key [{$key}] triggered a unique constraint race but no winning row could be found afterwards."
                );
            }
            $this->assertActionMatches($winner, $action);

            return $winner->response_snapshot;
        }
    }

    /**
     * Insert the row that claims this key. Extracted so tests can force a
     * genuine unique-constraint race by inserting a competing row just before
     * this call runs.
     */
    protected function claimKey(string $key, string $action): IdempotencyKey
    {
        return IdempotencyKey::query()->create([
            'key' => $key,
            'action' => $action,
            'user_id' => auth()->id(),
        ]);
    }

    private function isIdempotencyKeyCollision(UniqueConstraintViolationException $e): bool
    {
        if ($e->index !== null) {
            return $e->index === self::UNIQUE_INDEX;
        }

        // Fallback for drivers/situations where ->index isn't populated: MySQL
        // always includes the violated key name in the error message.
        return str_contains($e->getMessage(), self::UNIQUE_INDEX);
    }

    private function assertActionMatches(IdempotencyKey $record, string $action): void
    {
        if ($record->action !== $action) {
            throw new LogicException(
                "Idempotency key reused across different actions: expected [{$action}], found [{$record->action}]."
            );
        }
    }
}
