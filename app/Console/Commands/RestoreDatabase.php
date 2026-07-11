<?php

namespace App\Console\Commands;

use App\Support\SecretScrubber;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;
use Throwable;
use ZipArchive;

/**
 * §11 restore: loads a chosen `sparkbooks:backup` artifact (a .zip built by
 * that command, or a raw .sql dump) into an EXPLICIT target database.
 *
 * The one rule this command exists to enforce: it will NEVER guess a
 * target. `--database=` is required on every invocation, with no fallback
 * to the app's own configured database — a bare `sparkbooks:restore
 * some.sql` does nothing but print an error. If the given target happens to
 * equal the app's live configured database, it additionally demands an
 * interactive confirmation (or `--force` for scripted/rehearsed runs) before
 * touching it, so the single most catastrophic mistake this command could
 * make — silently overwriting production — takes two deliberate signals to
 * trigger, not one.
 *
 * docs/RESTORE.md documents the exact rehearsed procedure (dump dev →
 * restore into a scratch DB → verify → drop the scratch DB) this command
 * was proven against.
 */
class RestoreDatabase extends Command
{
    protected $signature = 'sparkbooks:restore
        {file : Path to a sparkbooks:backup .zip artifact, or a raw .sql dump}
        {--database= : The EXACT target database to restore into — required, never guessed}
        {--force : Skip the confirmation prompt when the target matches the app\'s configured database}';

    protected $description = 'Restore a backup artifact into an explicitly-named target database (spec §11) — never the live app DB by accident';

    public function handle(): int
    {
        $file = (string) $this->argument('file');
        $targetDatabase = $this->option('database');

        if (blank($targetDatabase)) {
            $this->error('You must pass --database=<target>. This command never guesses a target and will NEVER restore over the app\'s configured database implicitly.');
            Log::error('sparkbooks:restore: refused — no --database target given.', ['file' => $file]);

            return self::FAILURE;
        }

        $liveDatabase = config('database.connections.'.config('database.default').'.database');

        if ($targetDatabase === $liveDatabase && ! $this->option('force')) {
            if (! $this->confirm("Target database [{$targetDatabase}] is the app's CONFIGURED LIVE database. Restoring will overwrite it. Continue?", false)) {
                $this->warn('Restore aborted — target matched the live database and was not confirmed.');
                Log::warning('sparkbooks:restore: aborted — live-database target not confirmed.', ['database' => $targetDatabase]);

                return self::FAILURE;
            }
        }

        if (! is_file($file)) {
            $this->error("Backup file not found: {$file}");
            Log::error('sparkbooks:restore: file not found.', ['file' => $file]);

            return self::FAILURE;
        }

        [$sqlPath, $extractDir] = $this->resolveSqlFile($file);

        if ($sqlPath === null) {
            $this->error("Could not find database.sql inside archive: {$file}");
            Log::error('sparkbooks:restore: no database.sql found in archive.', ['file' => $file]);

            return self::FAILURE;
        }

        $exitCode = $this->runRestore($sqlPath, $targetDatabase);

        if ($extractDir !== null) {
            File::deleteDirectory($extractDir);
        }

        return $exitCode;
    }

    /**
     * @return array{0: ?string, 1: ?string} [sqlPath, tempExtractDirToCleanUp]
     */
    private function resolveSqlFile(string $file): array
    {
        if (! str_ends_with(strtolower($file), '.zip')) {
            // Treat as a raw SQL dump already.
            return [$file, null];
        }

        $extractDir = sys_get_temp_dir().'/sparkbooks-restore-'.uniqid();
        File::makeDirectory($extractDir, 0755, true);

        $zip = new ZipArchive;

        if ($zip->open($file) !== true) {
            File::deleteDirectory($extractDir);

            return [null, null];
        }

        if ($zip->getStream('database.sql') === false) {
            $zip->close();
            File::deleteDirectory($extractDir);

            return [null, null];
        }

        $zip->extractTo($extractDir, ['database.sql']);
        $zip->close();

        return ["{$extractDir}/database.sql", $extractDir];
    }

    private function runRestore(string $sqlPath, string $targetDatabase): int
    {
        $connection = config('database.connections.'.config('database.default'));
        $binary = (string) config('backup.mysql_path', 'mysql');

        $command = [
            $binary,
            '--host='.$connection['host'],
            '--port='.$connection['port'],
            '--user='.$connection['username'],
        ];

        if (filled($connection['password'] ?? null)) {
            $command[] = '--password='.$connection['password'];
        }

        $command[] = $targetDatabase;

        $input = fopen($sqlPath, 'r');

        try {
            $result = Process::timeout(600)->input($input)->run($command);
        } catch (Throwable $e) {
            // Symfony's Process*Exception messages interpolate the full argv —
            // which carries --password=<live DB password>. Scrub before it ever
            // reaches the console or the logs (mirrors BackupDatabase).
            $reason = SecretScrubber::scrub($e->getMessage());
            $this->error("Could not run the restore: {$reason}");
            Log::error('sparkbooks:restore: could not start mysql client.', ['binary' => $binary, 'error' => $reason]);

            return self::FAILURE;
        } finally {
            if (is_resource($input)) {
                fclose($input);
            }
        }

        if (! $result->successful()) {
            $errorOutput = SecretScrubber::scrub($result->errorOutput());
            $this->error("Restore failed (exit {$result->exitCode()}): {$errorOutput}");
            Log::error('sparkbooks:restore: restore failed.', ['target' => $targetDatabase, 'exit_code' => $result->exitCode(), 'error' => $errorOutput]);

            return self::FAILURE;
        }

        $this->info("Restored into database [{$targetDatabase}] from {$sqlPath}.");
        Log::info('sparkbooks:restore: restore succeeded.', ['target' => $targetDatabase]);

        return self::SUCCESS;
    }
}
