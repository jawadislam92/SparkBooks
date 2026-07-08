<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\Company;
use App\Models\IdempotencyKey;
use App\Models\User;
use App\Support\CompanyContext;
use App\Support\IdempotencyService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use LogicException;
use Tests\TestCase;

class IdempotencyTest extends TestCase
{
    use RefreshDatabase;

    public function test_operation_runs_once_and_replays_snapshot(): void
    {
        app(CompanyContext::class)->set(Company::factory()->create());
        $service = app(IdempotencyService::class);
        $key = (string) Str::uuid();
        $runs = 0;

        $first = $service->execute($key, 'invoice.send', function () use (&$runs) {
            $runs++;

            return ['invoice_id' => 42];
        });
        $second = $service->execute($key, 'invoice.send', function () use (&$runs) {
            $runs++;

            return ['invoice_id' => 99];
        });

        $this->assertSame(1, $runs);
        $this->assertSame(['invoice_id' => 42], $first);
        $this->assertSame(['invoice_id' => 42], $second);
    }

    public function test_failed_operation_releases_the_key(): void
    {
        app(CompanyContext::class)->set(Company::factory()->create());
        $service = app(IdempotencyService::class);
        $key = (string) Str::uuid();

        try {
            $service->execute($key, 'invoice.send', fn () => throw new \RuntimeException('boom'));
        } catch (\RuntimeException) {
        }

        $result = $service->execute($key, 'invoice.send', fn () => ['ok' => true]);
        $this->assertSame(['ok' => true], $result);
    }

    public function test_same_key_in_different_companies_is_independent(): void
    {
        [$a, $b] = Company::factory()->count(2)->create();
        $service = app(IdempotencyService::class);
        $ctx = app(CompanyContext::class);
        $key = (string) Str::uuid();

        $ctx->set($a);
        $ra = $service->execute($key, 'x', fn () => 'from-a');
        $rb = $ctx->runFor($b, fn () => $service->execute($key, 'x', fn () => 'from-b'));

        $this->assertSame('from-a', $ra);
        $this->assertSame('from-b', $rb);
    }

    public function test_concurrent_identical_request_replays_winners_snapshot(): void
    {
        app(CompanyContext::class)->set(Company::factory()->create());
        $key = (string) Str::uuid();

        // A service subclass whose claimKey() first simulates a *different*
        // database session winning the race: it inserts and commits a
        // competing row (with its own snapshot already set) on a genuinely
        // separate connection, then delegates to the real claimKey(), whose
        // insert now hits the real unique index — exercising the actual
        // catch(UniqueConstraintViolationException) path end-to-end.
        $service = new class extends IdempotencyService
        {
            protected function claimKey(string $key, string $action): IdempotencyKey
            {
                config(['database.connections.idempotency_race_sim' => config('database.connections.mysql')]);
                $sim = DB::connection('idempotency_race_sim');
                // The test's own Company row lives inside RefreshDatabase's still-open,
                // uncommitted outer transaction on the default connection, so it is
                // invisible to this genuinely separate session. Skip the FK check
                // (this insert is purely a race simulation, not a referential-integrity
                // test) to avoid blocking on that row's lock.
                $sim->statement('SET SESSION FOREIGN_KEY_CHECKS=0');
                $sim->table('idempotency_keys')->insert([
                    'company_id' => app(CompanyContext::class)->id(),
                    'user_id' => null,
                    'key' => $key,
                    'action' => $action,
                    'response_snapshot' => json_encode(['invoice_id' => 'winner']),
                    'created_at' => now(),
                ]);
                DB::disconnect('idempotency_race_sim');

                return parent::claimKey($key, $action);
            }
        };

        $ranOperation = false;
        $result = $service->execute($key, 'invoice.send', function () use (&$ranOperation) {
            $ranOperation = true;

            return ['invoice_id' => 'mine'];
        });

        $this->assertFalse($ranOperation, 'Operation must not run once another request already claimed the key.');
        $this->assertSame(['invoice_id' => 'winner'], $result);
    }

    public function test_unrelated_unique_violation_inside_operation_propagates_and_releases_key(): void
    {
        // CREATE TABLE is DDL and causes MySQL to implicitly commit whatever
        // transaction issued it. Running it on the default connection would
        // silently commit RefreshDatabase's outer test transaction while
        // Laravel's own transaction-level bookkeeping stays out of sync,
        // corrupting the savepoint nesting the rest of this test relies on.
        // Create the throwaway table on a separate connection/session instead
        // — schema changes are immediately visible to every session regardless.
        //
        // The table is created idempotently and never dropped: once this
        // test's own transaction (rolled back by RefreshDatabase as usual)
        // inserts rows into it, a DROP TABLE from any session would block
        // on the table's metadata lock until that transaction ends — i.e.
        // it would deadlock against the very test that's trying to run it.
        // Leaving the (empty, schema-only) table in place is harmless and
        // self-healing across a `migrate:fresh` of the test database.
        config(['database.connections.idempotency_schema_sim' => config('database.connections.mysql')]);
        $schemaConnection = Schema::connection('idempotency_schema_sim');
        if (! $schemaConnection->hasTable('idempotency_test_widgets')) {
            $schemaConnection->create('idempotency_test_widgets', function (Blueprint $t) {
                $t->id();
                $t->string('code')->unique();
            });
        }
        DB::disconnect('idempotency_schema_sim');

        app(CompanyContext::class)->set(Company::factory()->create());
        $service = app(IdempotencyService::class);
        $key = (string) Str::uuid();

        DB::table('idempotency_test_widgets')->insert(['code' => 'dup']);

        $threw = null;
        try {
            $service->execute($key, 'widget.create', function () {
                DB::table('idempotency_test_widgets')->insert(['code' => 'dup']);
            });
        } catch (UniqueConstraintViolationException $e) {
            $threw = $e;
        }

        $this->assertNotNull($threw, 'The caller\'s own unique violation must propagate, not be swallowed.');
        $this->assertDatabaseMissing('idempotency_keys', ['key' => $key]);

        // The key was released, so a retry with the same key succeeds normally.
        $result = $service->execute($key, 'widget.create', fn () => ['retried' => true]);
        $this->assertSame(['retried' => true], $result);
    }

    public function test_execute_rejects_being_called_inside_an_open_transaction(): void
    {
        app(CompanyContext::class)->set(Company::factory()->create());
        $service = app(IdempotencyService::class);

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage(
            'IdempotencyService::execute must not be called inside an open transaction; it owns the transaction boundary.'
        );

        DB::transaction(function () use ($service) {
            $service->execute((string) Str::uuid(), 'invoice.send', fn () => ['ok' => true]);
        });
    }

    public function test_reusing_key_for_a_different_action_throws(): void
    {
        app(CompanyContext::class)->set(Company::factory()->create());
        $service = app(IdempotencyService::class);
        $key = (string) Str::uuid();

        $service->execute($key, 'invoice.send', fn () => ['ok' => true]);

        $this->expectException(LogicException::class);
        $this->expectExceptionMessageMatches('/reused across different actions/');

        $service->execute($key, 'invoice.void', fn () => ['should' => 'not-run']);
    }

    public function test_null_and_falsy_snapshots_replay_correctly(): void
    {
        app(CompanyContext::class)->set(Company::factory()->create());
        $service = app(IdempotencyService::class);

        $nullKey = (string) Str::uuid();
        $nullRuns = 0;
        $firstNull = $service->execute($nullKey, 'noop', function () use (&$nullRuns) {
            $nullRuns++;

            return null;
        });
        $secondNull = $service->execute($nullKey, 'noop', function () use (&$nullRuns) {
            $nullRuns++;

            return ['should-not-run' => true];
        });
        $this->assertSame(1, $nullRuns);
        $this->assertNull($firstNull);
        $this->assertNull($secondNull);

        $falseKey = (string) Str::uuid();
        $falseRuns = 0;
        $firstFalse = $service->execute($falseKey, 'noop', function () use (&$falseRuns) {
            $falseRuns++;

            return false;
        });
        $secondFalse = $service->execute($falseKey, 'noop', function () use (&$falseRuns) {
            $falseRuns++;

            return true;
        });
        $this->assertSame(1, $falseRuns);
        $this->assertFalse($firstFalse);
        $this->assertFalse($secondFalse);
    }

    /**
     * Characterizes the defect this task closes. IdempotencyService is bound
     * scoped(), so its baseline is captured whenever the container FIRST
     * constructs it during the request. If that first resolution happens to
     * occur while a transaction is already open — because nothing forced
     * earlier resolution — the baseline is captured at the already-raised
     * level, and a same-level execute() call looks "at baseline" to the
     * guard and slips through uncaught. This is exactly the hole that makes
     * SetCompanyContext's eager, pre-transaction resolution necessary (see
     * the next test, and the constructor comment on IdempotencyService).
     */
    public function test_lazy_first_resolution_inside_an_open_transaction_defeats_the_guard(): void
    {
        app(CompanyContext::class)->set(Company::factory()->create());

        $ranOperation = false;
        $result = DB::transaction(function () use (&$ranOperation) {
            // First-ever resolution of the scoped instance happens HERE,
            // already inside a transaction — the bug scenario.
            $service = app(IdempotencyService::class);

            return $service->execute((string) Str::uuid(), 'demo', function () use (&$ranOperation) {
                $ranOperation = true;

                return ['ok' => true];
            });
        });

        $this->assertTrue($ranOperation, 'Documents the hole: lazy in-transaction resolution defeats the baseline guard, so the operation runs uncaught.');
        $this->assertSame(['ok' => true], $result);
    }

    /**
     * Proves the fix: SetCompanyContext eagerly resolves IdempotencyService
     * immediately after setting the company context, i.e. before any route
     * handler gets a chance to open a transaction. Because of that, a
     * handler which opens DB::transaction() and only THEN resolves/uses the
     * (already-constructed) service correctly hits the guard and throws,
     * instead of silently defeating it as in the previous test.
     */
    public function test_middleware_eager_resolution_makes_execute_inside_request_transaction_throw(): void
    {
        $user = User::factory()->create();
        $company = Company::factory()->create();
        $user->companies()->attach($company, ['role' => Role::Owner->value]);
        $user->forceFill(['current_company_id' => $company->id])->save();

        Route::middleware('web')->get('/__test/idempotency-guard-probe', function () {
            return DB::transaction(function () {
                $service = app(IdempotencyService::class);
                $service->execute((string) Str::uuid(), 'demo', fn () => ['probed' => true]);

                return response()->json(['probed' => true]);
            });
        });

        $this->actingAs($user);
        $this->withoutExceptionHandling();

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage(
            'IdempotencyService::execute must not be called inside an open transaction; it owns the transaction boundary.'
        );

        $this->get('/__test/idempotency-guard-probe');
    }
}
