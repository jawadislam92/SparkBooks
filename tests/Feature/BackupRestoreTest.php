<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;
use Symfony\Component\Process\Exception\ProcessTimedOutException;
use Symfony\Component\Process\Process as SymfonyProcess;
use Tests\TestCase;

/**
 * Phase 6 Task 6 (spec §11): `sparkbooks:restore`'s one job is to never
 * guess a target database. These tests exercise the guard rails only — the
 * actual rehearsed restore (dump dev → restore into a scratch DB → verify
 * row counts → drop the scratch DB) was run once by hand per docs/RESTORE.md
 * and isn't re-run on every test suite pass (it needs CREATE/DROP DATABASE
 * privileges and a real mysqldump/mysql round-trip against dev data, which
 * doesn't belong in the automated suite).
 */
class BackupRestoreTest extends TestCase
{
    public function test_restore_refuses_to_run_without_an_explicit_database_target(): void
    {
        $this->artisan('sparkbooks:restore', ['file' => '/tmp/whatever.sql'])
            ->expectsOutputToContain('--database')
            ->assertFailed();
    }

    public function test_restore_fails_cleanly_when_the_backup_file_does_not_exist(): void
    {
        $this->artisan('sparkbooks:restore', [
            'file' => sys_get_temp_dir().'/sparkbooks-restore-test-does-not-exist-'.uniqid().'.sql',
            '--database' => 'sparkbooks_restore_test_scratch',
        ])
            ->expectsOutputToContain('not found')
            ->assertFailed();
    }

    public function test_restore_into_the_configured_live_database_requires_confirmation_and_aborts_non_interactively(): void
    {
        $liveDatabase = config('database.connections.'.config('database.default').'.database');

        // A harmless placeholder file — if the guard ever failed open, this
        // proves it: real dump content would never reach mysql because the
        // command must abort before even reading the file's contents.
        $sqlFile = tempnam(sys_get_temp_dir(), 'restore-guard-test-');
        file_put_contents($sqlFile, "-- placeholder, guard must abort before this is used\n");

        $this->artisan('sparkbooks:restore', [
            'file' => $sqlFile,
            '--database' => $liveDatabase,
        ])
            ->expectsConfirmation(
                "Target database [{$liveDatabase}] is the app's CONFIGURED LIVE database. Restoring will overwrite it. Continue?",
                'no',
            )
            ->assertFailed();

        @unlink($sqlFile);
    }

    /**
     * Whole-branch review finding: the mysql restore argv carries
     * --password=<live DB password>, and Symfony's ProcessTimedOutException /
     * ProcessStartFailedException interpolate the full command line into their
     * message. A restore that times out (600s) would otherwise write the
     * cleartext password into the application log — the exact leak class Task 7
     * fixed for BackupDatabase but originally missed on its RestoreDatabase
     * sibling. This proves the SecretScrubber is now applied on the restore
     * side too. (Stubbing the scrubber to a no-op makes this fail with the
     * password present — verified while writing the fix.)
     */
    public function test_restore_failure_never_writes_the_db_password_to_the_console_or_log(): void
    {
        Log::spy();
        config([
            'database.connections.'.config('database.default').'.password' => 'SECRETpw',
        ]);

        // A raw .sql file so the command skips the zip path and reaches the
        // mysql Process call directly.
        $sqlFile = tempnam(sys_get_temp_dir(), 'restore-scrub-test-').'.sql';
        file_put_contents($sqlFile, "-- restore payload\n");

        Process::fake([
            '*' => function () {
                $sp = new SymfonyProcess(['mysql', '--host=127.0.0.1', '--password=SECRETpw', 'sparkbooks_restore_test_scratch']);

                return new ProcessTimedOutException($sp, ProcessTimedOutException::TYPE_GENERAL);
            },
        ]);

        $this->artisan('sparkbooks:restore', [
            'file' => $sqlFile,
            '--database' => 'sparkbooks_restore_test_scratch',
        ])
            ->doesntExpectOutputToContain('SECRETpw')
            ->assertFailed();

        // The password reached neither the log message nor its context.
        Log::shouldHaveReceived('error')->withArgs(function ($message, $context = []) {
            return ! str_contains($message.' '.json_encode($context), 'SECRETpw');
        })->atLeast()->once();

        @unlink($sqlFile);
    }
}
