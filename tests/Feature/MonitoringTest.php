<?php

namespace Tests\Feature;

use App\Domain\Ledger\EntryDraft;
use App\Domain\Ledger\LineDraft;
use App\Enums\AccountSubtype;
use App\Enums\AccountType;
use App\Jobs\QueueHeartbeatJob;
use App\Mail\MonitoringAlertMail;
use App\Models\Account;
use App\Models\Company;
use App\Models\JournalEntry;
use App\Models\JournalLine;
use App\Models\ManualJournal;
use App\Services\LedgerService;
use App\Support\AlertNotifier;
use App\Support\CompanyContext;
use App\Support\Heartbeat;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Symfony\Component\Process\Exception\ProcessTimedOutException;
use Symfony\Component\Process\Process as SymfonyProcess;
use Tests\TestCase;

/**
 * Phase 6 Task 7 (spec §17.9): monitoring & heartbeats. Covers:
 *   - App\Support\Heartbeat — config-gated ping (skipped cleanly when
 *     unset), swallows network errors without ever breaking its caller.
 *   - App\Support\AlertNotifier — email + optional Slack, both
 *     config-gated; logs a warning (never throws) when neither channel is
 *     configured.
 *   - ledger:verify wired to alert the moment it finds findings OR
 *     evaluates zero companies, and to ping the integrity heartbeat only
 *     when clean.
 *   - sparkbooks:backup wired to alert on ANY failure and ping the backup
 *     heartbeat only on success.
 *   - The queue-worker liveness job-on-queue design (QueueHeartbeatJob):
 *     the heartbeat only fires once the job is actually PROCESSED, not
 *     merely dispatched — proving worker liveness, not just cron liveness.
 *
 * Every test uses Http::fake()/Mail::fake() — never a real network call.
 * Every monitoring config value defaults to null/unset (config/monitoring.php,
 * .env.example), so these tests also double as proof the whole feature is
 * inert unless explicitly configured — a bare `php artisan test` run with no
 * monitoring env set (the default for this whole suite) never dials out.
 */
class MonitoringTest extends TestCase
{
    use RefreshDatabase;

    private string $backupDir;

    protected function setUp(): void
    {
        parent::setUp();

        $this->backupDir = sys_get_temp_dir().'/sparkbooks-monitoring-test-'.uniqid();
        config(['backup.local_path' => $this->backupDir]);
        Storage::fake('local');
    }

    protected function tearDown(): void
    {
        if (is_dir($this->backupDir)) {
            File::deleteDirectory($this->backupDir);
        }

        parent::tearDown();
    }

    // ---------------------------------------------------------------
    // Helpers — mirror IntegrityCheckerTest's own shapes (kept
    // self-contained here rather than shared, since that file's helpers are
    // private to its own class).
    // ---------------------------------------------------------------

    private function bankAccount(Company $company): Account
    {
        return Account::factory()->create([
            'company_id' => $company->id,
            'code' => '1010',
            'type' => AccountType::Asset->value,
            'subtype' => AccountSubtype::Bank->value,
        ]);
    }

    private function incomeAccount(Company $company): Account
    {
        return Account::factory()->create([
            'company_id' => $company->id,
            'code' => '4000',
            'type' => AccountType::Income->value,
            'subtype' => AccountSubtype::OperatingIncome->value,
        ]);
    }

    /** Posts a genuinely balanced entry straight through LedgerService (the one ledger write-gate). */
    private function postBalancedEntry(Company $company, Account $bank, Account $income): JournalEntry
    {
        app(CompanyContext::class)->set($company);

        $doc = ManualJournal::query()->create([
            'entry_date' => '2026-07-01',
            'memo' => 'Test entry',
            'status' => 'posted',
        ]);

        $draft = new EntryDraft(
            entryDate: CarbonImmutable::parse('2026-07-01'),
            memo: 'Test entry',
            sourceType: 'manual_journal',
            sourceId: $doc->id,
            lines: [
                new LineDraft(accountId: $bank->id, debit: 10000, credit: 0),
                new LineDraft(accountId: $income->id, debit: 0, credit: 10000),
            ],
        );

        return DB::transaction(fn () => app(LedgerService::class)->post($draft));
    }

    /** A company with one genuinely balanced entry — clean by construction. */
    private function cleanCompany(): Company
    {
        $company = Company::factory()->create();
        $this->postBalancedEntry($company, $this->bankAccount($company), $this->incomeAccount($company));

        return $company;
    }

    /**
     * A company with one raw-tampered journal line — guaranteed integrity
     * findings (entry_balance + trial_balance), the same seeding technique
     * IntegrityCheckerTest uses throughout: model guards forbid this via
     * Eloquent, so raw SQL is the only way to manufacture the corruption.
     */
    private function corruptCompany(): Company
    {
        $company = Company::factory()->create();
        $bank = $this->bankAccount($company);
        $this->postBalancedEntry($company, $bank, $this->incomeAccount($company));

        $bankLine = JournalLine::query()->where('account_id', $bank->id)->sole();
        DB::statement('UPDATE journal_lines SET debit = ? WHERE id = ?', [15000, $bankLine->id]);

        return $company;
    }

    // ---------------------------------------------------------------
    // ledger:verify — alert on findings / zero companies / clean
    // ---------------------------------------------------------------

    public function test_ledger_verify_dispatches_alert_when_findings_exist(): void
    {
        Mail::fake();
        Http::fake();
        config([
            'monitoring.alert_email' => 'alerts@sparkbooks.test',
            'monitoring.slack_webhook' => 'https://hooks.slack.test/services/xyz',
        ]);

        $this->corruptCompany();

        $this->artisan('ledger:verify')->assertExitCode(1);

        Mail::assertSent(MonitoringAlertMail::class, function (MonitoringAlertMail $mail) {
            return str_contains($mail->alertSubject, 'ledger:verify')
                && str_contains($mail->alertBody, 'entry_balance');
        });

        Http::assertSent(fn (Request $request) => $request->url() === 'https://hooks.slack.test/services/xyz');
    }

    public function test_ledger_verify_no_alert_when_clean_and_pings_integrity_heartbeat(): void
    {
        Mail::fake();
        Http::fake();
        config([
            'monitoring.alert_email' => 'alerts@sparkbooks.test',
            'monitoring.heartbeats.integrity' => 'https://hc.test/ping/integrity-uuid',
        ]);

        $this->cleanCompany();

        $this->artisan('ledger:verify')->assertExitCode(0);

        Mail::assertNothingSent();
        Http::assertSent(fn (Request $request) => $request->url() === 'https://hc.test/ping/integrity-uuid');
    }

    public function test_ledger_verify_dispatches_alert_when_zero_companies(): void
    {
        Mail::fake();
        config(['monitoring.alert_email' => 'alerts@sparkbooks.test']);

        $this->assertSame(0, Company::query()->count());

        $this->artisan('ledger:verify')->assertExitCode(1);

        Mail::assertSent(MonitoringAlertMail::class, fn (MonitoringAlertMail $mail) => str_contains($mail->alertSubject, 'zero companies'));
    }

    public function test_ledger_verify_no_heartbeat_ping_when_findings_exist(): void
    {
        Http::fake();
        config(['monitoring.heartbeats.integrity' => 'https://hc.test/ping/integrity-uuid']);

        $this->corruptCompany();

        $this->artisan('ledger:verify')->assertExitCode(1);

        Http::assertNothingSent();
    }

    // ---------------------------------------------------------------
    // sparkbooks:backup — alert on failure / heartbeat on success
    // ---------------------------------------------------------------

    public function test_backup_failure_dispatches_alert(): void
    {
        Mail::fake();
        config([
            'monitoring.alert_email' => 'alerts@sparkbooks.test',
            'backup.mysqldump_path' => $this->backupDir.'/does-not-exist-mysqldump',
        ]);

        $this->artisan('sparkbooks:backup')->assertFailed();

        Mail::assertSent(MonitoringAlertMail::class, fn (MonitoringAlertMail $mail) => str_contains($mail->alertSubject, 'backup failed'));
    }

    public function test_backup_success_pings_heartbeat_and_sends_no_alert(): void
    {
        Mail::fake();
        Http::fake();
        config([
            'monitoring.alert_email' => 'alerts@sparkbooks.test',
            'monitoring.heartbeats.backup' => 'https://hc.test/ping/backup-uuid',
        ]);

        $this->artisan('sparkbooks:backup')->assertSuccessful();

        Http::assertSent(fn (Request $request) => $request->url() === 'https://hc.test/ping/backup-uuid');
        Mail::assertNothingSent();
    }

    public function test_backup_heartbeat_network_error_does_not_break_the_backup_command(): void
    {
        Http::fake(function () {
            throw new ConnectionException('simulated network failure');
        });
        config(['monitoring.heartbeats.backup' => 'https://hc.test/ping/backup-uuid']);

        // The heartbeat ping itself blows up — the backup command must
        // still report success, since the backup genuinely succeeded.
        $this->artisan('sparkbooks:backup')->assertSuccessful();
    }

    // ---------------------------------------------------------------
    // Security: the DB password must never reach an alert body or a log.
    // ---------------------------------------------------------------

    /**
     * The review-found leak: mysqldump's argv carries --password=<live DB
     * password> inline, and Symfony's ProcessTimedOutException /
     * ProcessStartFailedException interpolate the FULL command line into
     * their message. A production dump hang (>300s) therefore throws an
     * exception whose message contains the cleartext password — which the
     * Task 7 alert wiring would otherwise email/Slack out and log verbatim.
     *
     * This test forces exactly that: the dump Process is faked to throw a
     * timeout exception whose message contains --password=SECRETpw (the same
     * shape Symfony produces on a real hang), and asserts the password
     * appears in NEITHER the dispatched alert body NOR the logs, while a
     * useful, secret-free reason still does. Redaction is on the
     * error-message path only — the real argv is untouched (proven by the
     * other backup tests which run a genuine mysqldump successfully).
     */
    public function test_backup_failure_alert_and_logs_never_contain_the_db_password(): void
    {
        Mail::fake();
        Log::spy();
        config([
            'monitoring.alert_email' => 'alerts@sparkbooks.test',
            // Put a real password into the argv dumpDatabase() builds, so
            // this proves the CONFIGURED secret can't leak either.
            'database.connections.'.config('database.default').'.password' => 'SECRETpw',
        ]);

        Process::fake([
            '*' => function () {
                $sp = new SymfonyProcess(['mysqldump', '--host=127.0.0.1', '--password=SECRETpw', 'sparkbooks_test']);

                return new ProcessTimedOutException($sp, ProcessTimedOutException::TYPE_GENERAL);
            },
        ]);

        $this->artisan('sparkbooks:backup')->assertFailed();

        Mail::assertSent(MonitoringAlertMail::class, function (MonitoringAlertMail $mail) {
            $this->assertStringNotContainsString('SECRETpw', $mail->alertBody);
            $this->assertStringNotContainsString('--password=SECRETpw', $mail->alertBody);
            $this->assertStringContainsString('--password=***', $mail->alertBody);
            // A genuinely useful, secret-free reason still survives.
            $this->assertStringContainsString('mysqldump', $mail->alertBody);

            return true;
        });

        // Nothing carrying the password reached the logs either.
        Log::shouldHaveReceived('error')->withArgs(function ($message, $context = []) {
            $blob = $message.' '.json_encode($context);

            return ! str_contains($blob, 'SECRETpw');
        })->atLeast()->once();
    }

    // ---------------------------------------------------------------
    // A throwing alert channel must never change the command's real exit
    // code (AlertNotifier's own per-channel try/catch, proven end-to-end).
    // ---------------------------------------------------------------

    public function test_backup_failure_still_fails_cleanly_when_the_email_alert_throws(): void
    {
        // Mail::to() itself throws — AlertNotifier catches it internally, so
        // the backup's real non-zero exit must still surface (not the mail
        // exception).
        Mail::shouldReceive('to')->andThrow(new RuntimeException('mail server down'));
        config([
            'monitoring.alert_email' => 'alerts@sparkbooks.test',
            'backup.mysqldump_path' => $this->backupDir.'/does-not-exist-mysqldump',
        ]);

        $this->artisan('sparkbooks:backup')->assertFailed();
    }

    public function test_ledger_verify_still_fails_cleanly_when_the_slack_alert_throws(): void
    {
        // The Slack webhook POST throws — AlertNotifier catches it, so
        // ledger:verify still exits 1 on its findings.
        Http::fake(function () {
            throw new ConnectionException('slack unreachable');
        });
        config(['monitoring.slack_webhook' => 'https://hooks.slack.test/services/xyz']);

        $this->corruptCompany();

        $this->artisan('ledger:verify')->assertExitCode(1);
    }

    // ---------------------------------------------------------------
    // App\Support\Heartbeat — config gating + failure isolation
    // ---------------------------------------------------------------

    public function test_heartbeat_ping_fires_when_url_configured(): void
    {
        Http::fake();
        config(['monitoring.heartbeats.backup' => 'https://hc.test/ping/some-uuid']);

        app(Heartbeat::class)->ping('backup');

        Http::assertSent(fn (Request $request) => $request->url() === 'https://hc.test/ping/some-uuid');
    }

    public function test_heartbeat_ping_is_skipped_cleanly_when_url_unset(): void
    {
        Http::fake();
        config(['monitoring.heartbeats.backup' => null]);

        app(Heartbeat::class)->ping('backup');

        Http::assertNothingSent();
    }

    public function test_heartbeat_swallows_network_errors_and_logs_a_warning(): void
    {
        Http::fake(function () {
            throw new ConnectionException('simulated network failure');
        });
        Log::spy();
        config(['monitoring.heartbeats.backup' => 'https://hc.test/ping/some-uuid']);

        // No exception should escape ping() itself.
        app(Heartbeat::class)->ping('backup');

        Log::shouldHaveReceived('warning')->atLeast()->once();
    }

    // ---------------------------------------------------------------
    // App\Support\AlertNotifier — channel gating
    // ---------------------------------------------------------------

    public function test_alert_notifier_sends_email_when_configured(): void
    {
        Mail::fake();
        config(['monitoring.alert_email' => 'alerts@sparkbooks.test', 'monitoring.slack_webhook' => null]);

        app(AlertNotifier::class)->alert('Test subject', 'Test body');

        Mail::assertSent(MonitoringAlertMail::class, function (MonitoringAlertMail $mail) {
            return $mail->alertSubject === 'Test subject' && $mail->alertBody === 'Test body';
        });
    }

    public function test_alert_notifier_posts_to_slack_when_webhook_configured(): void
    {
        Http::fake();
        config(['monitoring.alert_email' => null, 'monitoring.slack_webhook' => 'https://hooks.slack.test/services/xyz']);

        app(AlertNotifier::class)->alert('Test subject', 'Test body');

        Http::assertSent(function (Request $request) {
            return $request->url() === 'https://hooks.slack.test/services/xyz'
                && str_contains($request['text'], 'Test subject');
        });
    }

    public function test_alert_notifier_email_only_works_without_slack(): void
    {
        Mail::fake();
        Http::fake();
        config(['monitoring.alert_email' => 'alerts@sparkbooks.test', 'monitoring.slack_webhook' => null]);

        app(AlertNotifier::class)->alert('Test subject', 'Test body');

        Mail::assertSent(MonitoringAlertMail::class);
        Http::assertNothingSent();
    }

    public function test_alert_notifier_slack_only_works_without_email(): void
    {
        Mail::fake();
        Http::fake();
        config(['monitoring.alert_email' => null, 'monitoring.slack_webhook' => 'https://hooks.slack.test/services/xyz']);

        app(AlertNotifier::class)->alert('Test subject', 'Test body');

        Http::assertSent(fn (Request $request) => $request->url() === 'https://hooks.slack.test/services/xyz');
        Mail::assertNothingSent();
    }

    public function test_alert_notifier_neither_configured_logs_warning_and_sends_nothing(): void
    {
        Mail::fake();
        Http::fake();
        Log::spy();
        config(['monitoring.alert_email' => null, 'monitoring.slack_webhook' => null]);

        app(AlertNotifier::class)->alert('Test subject', 'Test body');

        Mail::assertNothingSent();
        Http::assertNothingSent();
        Log::shouldHaveReceived('warning')->atLeast()->once();
    }

    // ---------------------------------------------------------------
    // Queue-worker liveness (job-on-queue design)
    // ---------------------------------------------------------------

    public function test_queue_heartbeat_command_dispatches_the_job(): void
    {
        Queue::fake();

        $this->artisan('sparkbooks:queue-heartbeat')->assertSuccessful();

        Queue::assertPushed(QueueHeartbeatJob::class);
    }

    /**
     * The load-bearing property of the whole design: the heartbeat only
     * fires once the job is actually PROCESSED (handle() runs), never
     * merely dispatched — a dead queue worker means this never happens, and
     * the external healthcheck pages on the resulting silence.
     */
    public function test_queue_heartbeat_job_pings_when_processed(): void
    {
        Http::fake();
        config(['monitoring.heartbeats.queue' => 'https://hc.test/ping/queue-uuid']);

        (new QueueHeartbeatJob)->handle(app(Heartbeat::class));

        Http::assertSent(fn (Request $request) => $request->url() === 'https://hc.test/ping/queue-uuid');
    }

    public function test_queue_heartbeat_job_dispatched_but_not_processed_never_pings(): void
    {
        Http::fake();
        Queue::fake();
        config(['monitoring.heartbeats.queue' => 'https://hc.test/ping/queue-uuid']);

        // Simulates a dead worker: the job is pushed onto the queue but
        // nothing ever processes it (Queue::fake intercepts dispatch and
        // never runs handle()) — so no ping should ever fire.
        QueueHeartbeatJob::dispatch();

        Queue::assertPushed(QueueHeartbeatJob::class);
        Http::assertNothingSent();
    }
}
