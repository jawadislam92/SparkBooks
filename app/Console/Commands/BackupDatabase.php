<?php

namespace App\Console\Commands;

use App\Support\AlertNotifier;
use App\Support\BackupRetentionPolicy;
use App\Support\Heartbeat;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;
use Throwable;
use ZipArchive;

/**
 * §11 backup: dumps the MySQL database + the private attachments disk into
 * one timestamped `sparkbooks-backup-<ts>.zip` under config('backup.local_path'),
 * copies it off-host when the `backups` disk is configured (config-gated —
 * see config/filesystems.php, same "wired but inert without env" shape as
 * Sentry), then prunes old artifacts via App\Support\BackupRetentionPolicy
 * (14 daily + 8 weekly by default). Scheduled daily in routes/console.php,
 * alongside ledger:verify.
 *
 * Every failure path returns self::FAILURE with a logged, printed error —
 * Task 7 hangs its alerting off this command's exit code: handle() itself is
 * a thin wrapper around runBackup() that pings the backup heartbeat on
 * success (App\Support\Heartbeat, config-gated, no-op without an env URL) or
 * dispatches an alert on failure (App\Support\AlertNotifier, config-gated
 * email + optional Slack) — one call site for each, regardless of which
 * internal step failed, keyed off $lastFailureReason set at each failure
 * point below.
 */
class BackupDatabase extends Command
{
    protected $signature = 'sparkbooks:backup';

    protected $description = 'Dump the database + attachments to a timestamped archive, copy off-host when configured, and prune retention (spec §11)';

    /** Set at whichever failure point runBackup() returns FAILURE from, so handle() can alert with a meaningful body. */
    private ?string $lastFailureReason = null;

    public function handle(Heartbeat $heartbeat, AlertNotifier $alertNotifier): int
    {
        $result = $this->runBackup();

        if ($result === self::SUCCESS) {
            $heartbeat->ping('backup');
        } else {
            $alertNotifier->alert(
                'SparkBooks backup failed',
                $this->lastFailureReason ?? 'sparkbooks:backup exited non-zero. Check application logs for details.'
            );
        }

        return $result;
    }

    private function runBackup(): int
    {
        $localPath = rtrim((string) config('backup.local_path'), '/\\');

        if (! is_dir($localPath) && ! @mkdir($localPath, 0755, true) && ! is_dir($localPath)) {
            $this->error("Could not create the backups directory: {$localPath}");
            Log::error("sparkbooks:backup: could not create backups directory [{$localPath}].");
            $this->lastFailureReason = "Could not create the backups directory: {$localPath}";

            return self::FAILURE;
        }

        $stamp = now()->format('Y-m-d_His');
        $sqlPath = "{$localPath}/sparkbooks-backup-{$stamp}.sql";
        $archiveName = "sparkbooks-backup-{$stamp}.zip";
        $archivePath = "{$localPath}/{$archiveName}";

        if (! $this->dumpDatabase($sqlPath)) {
            return self::FAILURE;
        }

        try {
            $manifest = $this->buildArchive($archivePath, $sqlPath);
        } catch (Throwable $e) {
            $this->error("Failed to build the backup archive: {$e->getMessage()}");
            Log::error('sparkbooks:backup: failed to build archive', ['error' => $e->getMessage()]);
            $this->lastFailureReason = "Failed to build the backup archive: {$e->getMessage()}";

            return self::FAILURE;
        } finally {
            @unlink($sqlPath);
        }

        $this->info("Backup created: {$archivePath} (attachments: {$manifest['attachments_file_count']} file(s))");
        Log::info('sparkbooks:backup: backup created', ['path' => $archivePath, 'manifest' => $manifest]);

        $offHostOk = $this->copyOffHost($archivePath, $archiveName);

        $this->pruneRetention($localPath);

        return $offHostOk ? self::SUCCESS : self::FAILURE;
    }

    /**
     * Runs mysqldump against the app's configured connection, writing
     * straight to $sqlPath via mysqldump's own --result-file (portable
     * across Windows/Linux, no shell redirection needed). Returns false —
     * with the error already printed/logged — on any failure: unsupported
     * driver, the binary not found/exiting non-zero, or an empty result.
     */
    private function dumpDatabase(string $sqlPath): bool
    {
        $connectionName = config('database.default');
        $connection = config("database.connections.{$connectionName}");
        $driver = $connection['driver'] ?? null;

        if (! in_array($driver, ['mysql', 'mariadb'], true)) {
            $this->error("sparkbooks:backup only supports mysql/mariadb connections (current default connection [{$connectionName}] uses driver [{$driver}]).");
            Log::error("sparkbooks:backup: unsupported database driver [{$driver}] for connection [{$connectionName}].");
            $this->lastFailureReason = "sparkbooks:backup only supports mysql/mariadb connections (current default connection [{$connectionName}] uses driver [{$driver}]).";

            return false;
        }

        $binary = (string) config('backup.mysqldump_path', 'mysqldump');

        $command = [
            $binary,
            '--host='.$connection['host'],
            '--port='.$connection['port'],
            '--user='.$connection['username'],
            '--result-file='.$sqlPath,
            '--single-transaction',
            '--routines',
            '--triggers',
        ];

        if (filled($connection['password'] ?? null)) {
            $command[] = '--password='.$connection['password'];
        }

        $command[] = $connection['database'];

        try {
            $result = Process::timeout(300)->run($command);
        } catch (Throwable $e) {
            $this->error("Could not run mysqldump: {$e->getMessage()}");
            Log::error('sparkbooks:backup: mysqldump could not be started', ['binary' => $binary, 'error' => $e->getMessage()]);
            $this->lastFailureReason = "Could not run mysqldump: {$e->getMessage()}";

            return false;
        }

        if (! $result->successful()) {
            $this->error("mysqldump failed (exit {$result->exitCode()}): {$result->errorOutput()}");
            Log::error('sparkbooks:backup: mysqldump failed', ['exit_code' => $result->exitCode(), 'error' => $result->errorOutput()]);
            $this->lastFailureReason = "mysqldump failed (exit {$result->exitCode()}): {$result->errorOutput()}";

            return false;
        }

        if (! is_file($sqlPath) || filesize($sqlPath) === 0) {
            $this->error("mysqldump reported success but produced an empty or missing dump file: {$sqlPath}");
            Log::error('sparkbooks:backup: dump file missing or empty', ['path' => $sqlPath]);
            $this->lastFailureReason = "mysqldump reported success but produced an empty or missing dump file: {$sqlPath}";

            return false;
        }

        return true;
    }

    /**
     * Zips the SQL dump + a recursive copy of the private attachments disk
     * (spec §11: "uploaded attachments included in backup scope") +
     * manifest.json into one artifact. Not company-scoped: a single backup
     * of the whole physical database + disk covers every tenant at once.
     *
     * @return array{sql_bytes: int, attachments_file_count: int, attachments_included: bool}
     */
    private function buildArchive(string $archivePath, string $sqlPath): array
    {
        $zip = new ZipArchive;

        if ($zip->open($archivePath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new \RuntimeException("Could not open {$archivePath} for writing.");
        }

        $zip->addFile($sqlPath, 'database.sql');

        $attachmentsRoot = Storage::disk('local')->path('attachments');
        $fileCount = 0;

        if (is_dir($attachmentsRoot)) {
            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($attachmentsRoot, \FilesystemIterator::SKIP_DOTS),
                \RecursiveIteratorIterator::LEAVES_ONLY,
            );

            foreach ($iterator as $file) {
                /** @var \SplFileInfo $file */
                if (! $file->isFile()) {
                    continue;
                }

                $relative = 'attachments/'.ltrim(str_replace('\\', '/', substr($file->getPathname(), strlen($attachmentsRoot))), '/');
                $zip->addFile($file->getPathname(), $relative);
                $fileCount++;
            }
        }

        $manifest = [
            'created_at' => now()->toIso8601String(),
            'database' => config('database.connections.'.config('database.default').'.database'),
            'sql_bytes' => filesize($sqlPath),
            'attachments_included' => $fileCount > 0,
            'attachments_file_count' => $fileCount,
        ];

        $zip->addFromString('manifest.json', json_encode($manifest, JSON_PRETTY_PRINT));
        $zip->close();

        return $manifest;
    }

    /**
     * Copies the archive to the configured off-host disk. Config-gated on
     * that disk's `bucket` being set — Storage::disk() is never even called
     * when it's blank, so an unconfigured backups disk (the default, no S3
     * package required) can never throw here. Returns false (surfaced as a
     * command failure so Task 7 can alert on it) only when the disk IS
     * configured but the upload itself fails; the local artifact is
     * unaffected either way.
     */
    private function copyOffHost(string $archivePath, string $archiveName): bool
    {
        $diskName = (string) config('backup.offhost_disk', 'backups');
        $bucket = config("filesystems.disks.{$diskName}.bucket");

        if (blank($bucket)) {
            $this->info("Off-host copy skipped — not configured (no bucket set for the \"{$diskName}\" disk).");
            Log::info("sparkbooks:backup: off-host copy skipped — not configured (disk [{$diskName}]).");

            return true;
        }

        $stream = fopen($archivePath, 'r');

        try {
            Storage::disk($diskName)->put($archiveName, $stream);
        } catch (Throwable $e) {
            $this->error("Off-host copy to disk [{$diskName}] failed: {$e->getMessage()}");
            Log::error('sparkbooks:backup: off-host copy failed', ['disk' => $diskName, 'error' => $e->getMessage()]);
            $this->lastFailureReason = "Off-host copy to disk [{$diskName}] failed: {$e->getMessage()}";

            return false;
        } finally {
            if (is_resource($stream)) {
                fclose($stream);
            }
        }

        $this->info("Off-host copy uploaded to disk [{$diskName}]: {$archiveName}");
        Log::info('sparkbooks:backup: off-host copy uploaded', ['disk' => $diskName, 'file' => $archiveName]);

        return true;
    }

    /**
     * Lists this run's local artifacts, applies BackupRetentionPolicy, and
     * deletes whatever it marks for deletion — locally, and (best-effort,
     * never fatal to this command) on the off-host disk too when
     * configured. Never runs against anything but files matching our own
     * naming pattern, so a stray unrelated file in the backups directory is
     * left alone.
     */
    private function pruneRetention(string $localPath): void
    {
        $files = collect(scandir($localPath) ?: [])
            ->filter(fn (string $f) => str_starts_with($f, 'sparkbooks-backup-') && str_ends_with($f, '.zip'))
            ->values()
            ->all();

        $dailyDays = (int) config('backup.retention.daily', BackupRetentionPolicy::DEFAULT_DAILY_DAYS);
        $weeklyCount = (int) config('backup.retention.weekly', BackupRetentionPolicy::DEFAULT_WEEKLY_COUNT);

        $partition = BackupRetentionPolicy::partition($files, now(), $dailyDays, $weeklyCount);

        if ($partition['delete'] === []) {
            return;
        }

        $diskName = (string) config('backup.offhost_disk', 'backups');
        $offHostConfigured = filled(config("filesystems.disks.{$diskName}.bucket"));

        foreach ($partition['delete'] as $filename) {
            @unlink($localPath.'/'.$filename);

            if ($offHostConfigured) {
                try {
                    Storage::disk($diskName)->delete($filename);
                } catch (Throwable $e) {
                    Log::warning('sparkbooks:backup: off-host prune delete failed', ['disk' => $diskName, 'file' => $filename, 'error' => $e->getMessage()]);
                }
            }
        }

        $this->info('Pruned '.count($partition['delete']).' old backup(s): '.implode(', ', $partition['delete']));
        Log::info('sparkbooks:backup: pruned old backups', ['deleted' => $partition['delete']]);
    }
}
