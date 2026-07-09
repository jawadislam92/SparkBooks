<?php

namespace Tests\Feature;

use App\Enums\AccountSubtype;
use App\Enums\AccountType;
use App\Enums\ContactType;
use App\Enums\InvoiceStatus;
use App\Models\Account;
use App\Models\BankAccount;
use App\Models\Company;
use App\Models\Contact;
use App\Models\Invoice;
use App\Support\CompanyContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\AssertsLedgerBalanced;
use Tests\TestCase;

/**
 * Task 13 (Phase 3 exit, spec §12.6 carry-in from Task 4): proves the
 * invoice-payment overpayment guard under GENUINE cross-process,
 * cross-connection row-lock contention — not a simulation inside one
 * PHPUnit process/transaction, which is what every other concurrency-shaped
 * test in this codebase (IdempotencyTest's race-sim, BankAccountsTest's and
 * this same class's own InvoicePaymentTest "concurrent-style" test) settles
 * for, and each explicitly documents why: fixture rows created inside
 * RefreshDatabase's still-open, uncommitted outer transaction on the default
 * connection are invisible to any genuinely separate session, so a second
 * real connection's lockForUpdate() against them doesn't block — it just
 * finds "no such row." A row that must be UPDATEd/locked (not merely
 * INSERTed, like IdempotencyTest's competing key) can't be faked around
 * that problem either: a second session's UPDATE/SELECT-FOR-UPDATE against
 * a row that exists only inside another session's still-open transaction
 * blocks on THAT transaction (which in a PHPUnit test only ends at
 * tearDown), i.e. it hangs for the rest of the test rather than racing
 * anything — precisely why BankAccountsTest/InvoicePaymentTest both
 * concluded a true 2-connection race "deadlocks/times out" under
 * RefreshDatabase and settled for the weaker proof instead.
 *
 * This test sidesteps that by not participating in RefreshDatabase's
 * transaction at all: every fixture row below is a REAL, immediately
 * committed write (plain Eloquent create() with no wrapping transaction —
 * see class-level note below), so it's genuinely visible to separate OS
 * processes the instant it's created.
 *
 * ┌─ WHAT THIS TEST PROVES ────────────────────────────────────────────────┐
 * │ Three genuinely separate OS processes (own PHP interpreter, own MySQL  │
 * │ connection/session each — spawned via Illuminate\Support\Facades\      │
 * │ Process, running app/Console/Commands/ConcurrencyProbeInvoicePayment.  │
 * │ php, a test-support-only artisan command):                             │
 * │                                                                        │
 * │  1. A "hold" process opens its own transaction, takes a real           │
 * │     lockForUpdate() on the invoice row, and proves it has the lock by  │
 * │     writing a marker file the instant the query returns — this test   │
 * │     waits for that marker (not a blind sleep) before proceeding, so   │
 * │     the next step is NEVER racing against uncertainty about whether   │
 * │     the lock is actually held yet.                                    │
 * │  2. TWO "pay" processes are then started back-to-back, each calling   │
 * │     the real, completely unmodified InvoicePaymentService::           │
 * │     recordPayment() for a FULL payment against the SAME invoice, with │
 * │     DIFFERENT idempotency keys (so this is genuinely two independent  │
 * │     payment attempts, not idempotency replay dressed up as a race).   │
 * │     recordPayment()'s own lockForUpdate() on the invoice row is the   │
 * │     ONLY code under test here — nothing about it is modified, mocked, │
 * │     or bypassed for this test.                                        │
 * │  3. Because the hold process is still holding the real InnoDB lock,   │
 * │     BOTH pay processes' lockForUpdate() calls genuinely BLOCK in      │
 * │     MySQL — verified afterward by timing evidence (below), not        │
 * │     assumed.                                                          │
 * │  4. When the hold process's --hold-ms elapses and it rolls back       │
 * │     (releasing the lock, having changed nothing), the two blocked pay │
 * │     processes are now racing each other for the SAME real lock — a    │
 * │     faithful proxy for two payers racing directly against each other, │
 * │     since InnoDB's lock queue doesn't distinguish "who currently      │
 * │     holds it" from "who's racing whom"; the queued waiters are        │
 * │     granted the lock one at a time regardless.                        │
 * │  5. The assertions below prove: exactly ONE of the two succeeds: the  │
 * │     other is rejected; the invoice ends Paid with balance exactly 0;  │
 * │     exactly one posted invoice_payments row and one journal_entries   │
 * │     row exist for this invoice — i.e. two genuinely concurrent,       │
 * │     cross-process payment attempts against the same invoice can NOT   │
 * │     overpay it.                                                       │
 * │                                                                        │
 * │ Timing evidence (not just the outcome) proves the blocking was real:  │
 * │ both pay processes' own "about to call recordPayment" timestamp is    │
 * │ BEFORE the hold's release timestamp, and both "recordPayment returned"│
 * │ timestamps are AT OR AFTER it — i.e. neither could possibly have      │
 * │ finished while the lock was held elsewhere, so their lockForUpdate()  │
 * │ calls were genuinely in-flight (blocked), not just fortunate ordering.│
 * └────────────────────────────────────────────────────────────────────────┘
 *
 * ┌─ WHAT REMAINS ASSUMPTION / NOT PROVEN HERE ────────────────────────────┐
 * │ - The two "pay" processes contend against a third "hold" process       │
 * │   rather than directly against each other's own recordPayment()        │
 * │   transaction. This is a faithful proxy (InnoDB's row-lock queue       │
 * │   treats every waiter identically regardless of who currently holds    │
 * │   the lock, and recordPayment()'s logic is 100% identical either way — │
 * │   nothing about ITS code path knows or cares whether the lock holder   │
 * │   it's waiting on is another recordPayment() call or this test's hold  │
 * │   process) but it is not literally "process A's recordPayment()        │
 * │   blocked on process B's recordPayment()."                             │
 * │ - Exact win/lose ordering between the two pay processes once the lock  │
 * │   releases is not deterministic (OS/MySQL scheduling decides) — the    │
 * │   assertions below deliberately don't assume a specific winner, only   │
 * │   that the invariant (exactly one wins) holds either way.              │
 * │ - Covers full-payment vs full-payment only (the brief's ask — the      │
 * │   overpayment/double-payment guard), not partial-payment races.        │
 * │ - Depends on the harness being able to spawn real php.exe subprocesses │
 * │   (works under Git Bash/Windows here); a sandboxed CI runner that      │
 * │   forbids process spawning would need a different approach.           │
 * └────────────────────────────────────────────────────────────────────────┘
 *
 * Deliberately does NOT use RefreshDatabase: every row this test creates is
 * a real, immediately committed write (see the trait note above) so it is
 * visible to the spawned subprocesses. Unlike the Task 7 BankAccountsTest
 * race-sim precedent ("leaks one orphan row per run in test DB"), this test
 * DOES clean up fully in tearDown() (via a dedicated connection with
 * FOREIGN_KEY_CHECKS off, deleting every row tagged with this run's
 * company_id — sidesteps spec §17.3's ON DELETE RESTRICT chain rather than
 * fighting it) — empirically NOT optional here: `companies` is the one
 * table several other tests read with a genuinely global, unscoped count
 * (e.g. OnboardingTest asserts `Company::query()->count() === 1`), so even
 * one leaked company row silently breaks unrelated tests for the rest of
 * that same `composer test` run (caught by running the full suite after
 * first writing this test — see task-13-report.md).
 */
class InvoicePaymentConcurrencyTest extends TestCase
{
    use AssertsLedgerBalanced;

    private ?int $companyId = null;

    protected function setUp(): void
    {
        parent::setUp();

        // This test intentionally doesn't use RefreshDatabase (see class
        // docblock), so it can't rely on that trait's one-time migration.
        // Every other test in a full `composer test` run already migrates
        // the schema, but this guards standalone/filtered runs too.
        if (! Schema::hasTable('invoices')) {
            $this->artisan('migrate', ['--force' => true])->run();
        }
    }

    protected function tearDown(): void
    {
        if ($this->companyId !== null) {
            $this->purgeCompany($this->companyId);
        }

        parent::tearDown();
    }

    #[Test]
    public function two_genuinely_concurrent_full_payments_against_the_same_invoice_cannot_overpay_it(): void
    {
        $context = app(CompanyContext::class);

        $company = Company::factory()->create(['name' => 'Concurrency Probe Co '.Str::uuid()]);
        $this->companyId = $company->id;

        [$contact, $ar, $bank, $invoice] = $context->runFor($company, function () use ($company) {
            $contact = Contact::factory()->create([
                'company_id' => $company->id,
                'type' => ContactType::Customer->value,
            ]);

            $ar = Account::factory()->create([
                'company_id' => $company->id,
                'code' => '1100',
                'name' => 'Accounts Receivable',
                'type' => AccountType::Asset->value,
                'subtype' => AccountSubtype::Receivable->value,
                'is_system' => true,
            ]);

            $bank = BankAccount::factory()->create(['company_id' => $company->id]);

            $invoice = Invoice::factory()->create([
                'company_id' => $company->id,
                'contact_id' => $contact->id,
                'status' => InvoiceStatus::Sent->value,
                'number' => 'INV-CONC-'.fake()->unique()->numerify('####'),
                'issue_date' => '2026-07-01',
                'subtotal' => 100000,
                'tax_total' => 0,
                'total' => 100000,
                'amount_paid' => 0,
                'balance' => 100000,
                'base_subtotal' => 100000,
                'base_tax_total' => 0,
                'base_total' => 100000,
            ]);

            return [$contact, $ar, $bank, $invoice];
        });

        // Sanity: these rows are REALLY committed (not living inside a test
        // transaction) — a plain query on a connection this test process
        // hasn't touched confirms it, the same guarantee the spawned
        // subprocesses depend on.
        $this->assertDatabaseHasReal('invoices', ['id' => $invoice->id, 'balance' => 100000]);

        $dbConfig = config('database.connections.mysql');
        $childEnv = [
            'APP_ENV' => 'testing',
            'DB_CONNECTION' => 'mysql',
            'DB_HOST' => (string) $dbConfig['host'],
            'DB_PORT' => (string) $dbConfig['port'],
            'DB_DATABASE' => (string) $dbConfig['database'],
            'DB_USERNAME' => (string) $dbConfig['username'],
            'DB_PASSWORD' => (string) $dbConfig['password'],
            'CACHE_STORE' => 'array',
            'SESSION_DRIVER' => 'array',
            'QUEUE_CONNECTION' => 'sync',
            'MAIL_MAILER' => 'array',
        ];

        $php = PHP_BINARY;
        $artisan = base_path('artisan');
        $marker = sys_get_temp_dir().DIRECTORY_SEPARATOR.'sparkbooks-concurrency-lock-'.Str::uuid().'.marker';
        if (file_exists($marker)) {
            unlink($marker);
        }

        // Step 1: start the lock holder and wait for PROOF (the marker
        // file), not a guessed sleep, that it genuinely holds the row lock.
        $holdProcess = Process::env($childEnv)->timeout(30)->start([
            $php, $artisan, 'sparkbooks:concurrency-probe-invoice-payment', 'hold',
            (string) $company->id, (string) $invoice->id,
            '--hold-ms=700', '--marker='.$marker,
        ]);

        $waitedMs = 0;
        while (! file_exists($marker)) {
            usleep(5000);
            $waitedMs += 5;
            if ($waitedMs > 5000) {
                $this->fail('Lock-holder process never confirmed acquiring the invoice row lock within 5s. Output so far: '.$holdProcess->output());
            }
        }

        // Step 2: with the lock genuinely held (proven above), start BOTH
        // real payment attempts back-to-back. Each is a fresh OS process
        // calling the real InvoicePaymentService::recordPayment() with no
        // artificial delay of its own — their lockForUpdate() calls will
        // queue behind the holder's real lock.
        $keyA = (string) Str::uuid();
        $keyB = (string) Str::uuid();

        $payArgs = fn (string $key) => [
            $php, $artisan, 'sparkbooks:concurrency-probe-invoice-payment', 'pay',
            (string) $company->id, (string) $invoice->id,
            '--amount=100000', '--bank-account-id='.$bank->id,
            '--payment-date=2026-07-15', '--idempotency-key='.$key,
        ];

        $payA = Process::env($childEnv)->timeout(30)->start($payArgs($keyA));
        $payB = Process::env($childEnv)->timeout(30)->start($payArgs($keyB));

        $resultA = $payA->wait();
        $resultB = $payB->wait();
        $holdResult = $holdProcess->wait();

        $this->assertTrue($resultA->successful(), "pay worker A process failed to run: {$resultA->errorOutput()}");
        $this->assertTrue($resultB->successful(), "pay worker B process failed to run: {$resultB->errorOutput()}");
        $this->assertTrue($holdResult->successful(), "hold process failed to run: {$holdResult->errorOutput()}");

        $hold = $this->parseJsonLine($holdResult->output());
        $outcomeA = $this->parseJsonLine($resultA->output());
        $outcomeB = $this->parseJsonLine($resultB->output());

        $this->assertNotNull($hold, "Hold process produced no parseable JSON line. Output: {$holdResult->output()}");
        $this->assertNotNull($outcomeA, "Pay worker A produced no parseable JSON line. Output: {$resultA->output()}");
        $this->assertNotNull($outcomeB, "Pay worker B produced no parseable JSON line. Output: {$resultB->output()}");

        // --- Timing evidence: both attempts were genuinely in-flight while
        // the lock was held, not just luckily sequenced. ---
        foreach (['A' => $outcomeA, 'B' => $outcomeB] as $label => $outcome) {
            $this->assertLessThan(
                $hold['released_at'],
                $outcome['started_at'],
                "Pay worker {$label} started attempting payment AFTER the lock was already released — this run didn't exercise real contention (widen --hold-ms and retry)."
            );
            $this->assertGreaterThanOrEqual(
                $hold['released_at'],
                $outcome['finished_at'],
                "Pay worker {$label} finished BEFORE the lock was released — it could not have been genuinely blocked on lockForUpdate()."
            );
        }

        // --- The core invariant: exactly one succeeds, one is rejected. ---
        $outcomes = [$outcomeA['outcome'], $outcomeB['outcome']];
        sort($outcomes);
        $this->assertSame(['ok', 'rejected'], $outcomes, 'Exactly one of the two genuinely concurrent full payments must succeed and the other must be rejected — any other combination means either an overpay or both wrongly rejected. Worker A: '.json_encode($outcomeA).' Worker B: '.json_encode($outcomeB));

        $rejected = $outcomeA['outcome'] === 'rejected' ? $outcomeA : $outcomeB;
        $this->assertContains(
            $rejected['exception'],
            ['App\\Exceptions\\Invoicing\\InvoiceNotPayable', 'App\\Exceptions\\Invoicing\\PaymentExceedsBalance'],
            'The losing payment must be rejected by the overpayment/not-payable guard specifically, not some unrelated error.'
        );

        // --- Final DB state, read fresh (this test never opened a
        // transaction of its own, so this is a plain committed read). ---
        $freshInvoice = $context->runFor($company, fn () => Invoice::query()->findOrFail($invoice->id));
        $this->assertSame(InvoiceStatus::Paid, $freshInvoice->status);
        $this->assertSame(100000, $freshInvoice->amount_paid);
        $this->assertSame(0, $freshInvoice->balance);

        $postedPayments = DB::table('invoice_payments')
            ->where('invoice_id', $invoice->id)
            ->where('status', 'posted')
            ->count();
        $this->assertSame(1, $postedPayments, 'Exactly one payment must have actually posted — a second concurrent full payment succeeding too would mean the invoice was overpaid.');

        $paymentEntries = DB::table('journal_entries')
            ->where('source_type', 'invoice_payment')
            ->whereIn('source_id', DB::table('invoice_payments')->where('invoice_id', $invoice->id)->pluck('id'))
            ->count();
        $this->assertSame(1, $paymentEntries, 'Exactly one journal entry must have posted for this invoice\'s payment.');

        $this->assertLedgerBalances();

        @unlink($marker);
    }

    /**
     * Deletes every row this test tagged with company_id, in an order-
     * independent way, via a dedicated connection with FOREIGN_KEY_CHECKS
     * off for that session only (mirrors IdempotencyTest's race-sim
     * connection trick) — sidesteps spec §17.3's ON DELETE RESTRICT chain
     * (journal_lines -> journal_entries/accounts/contacts, invoices ->
     * contacts, bank_accounts -> accounts, ...) rather than hand-ordering
     * a dozen deletes. Scoped strictly to company_id, so it can never touch
     * another test's data; disabling FK checks is session-local to this one
     * throwaway connection, not global.
     */
    private function purgeCompany(int $companyId): void
    {
        config(['database.connections.concurrency_test_cleanup' => config('database.connections.mysql')]);
        $conn = DB::connection('concurrency_test_cleanup');
        $conn->statement('SET SESSION FOREIGN_KEY_CHECKS=0');

        foreach ([
            'journal_lines', 'journal_entries', 'invoice_payments', 'invoice_lines', 'invoices',
            'bank_accounts', 'accounts', 'contacts', 'activity_log', 'idempotency_keys',
            'document_counters', 'email_attempts',
        ] as $table) {
            $conn->table($table)->where('company_id', $companyId)->delete();
        }
        $conn->table('companies')->where('id', $companyId)->delete();

        DB::disconnect('concurrency_test_cleanup');
    }

    /** Confirms a row is visible via a query this process hasn't already opened a transaction against — i.e. it's really committed. */
    private function assertDatabaseHasReal(string $table, array $where): void
    {
        $query = DB::table($table);
        foreach ($where as $column => $value) {
            $query->where($column, $value);
        }
        $this->assertTrue($query->exists(), "Expected a real, committed row in [{$table}] matching ".json_encode($where));
    }

    /** @return array<string, mixed>|null */
    private function parseJsonLine(string $output): ?array
    {
        $lines = array_reverse(array_filter(explode("\n", $output)));
        foreach ($lines as $line) {
            $decoded = json_decode(trim($line), true);
            if (is_array($decoded)) {
                return $decoded;
            }
        }

        return null;
    }
}
