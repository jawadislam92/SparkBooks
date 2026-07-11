<?php

namespace Tests\Feature;

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
}
