<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;
use ZipArchive;

/**
 * Phase 6 Task 6 (spec §11): `sparkbooks:backup`. The rotation math itself
 * is covered exhaustively by tests/Unit/BackupRetentionPolicyTest.php (pure,
 * fabricated filenames) — this file covers the command's I/O behavior: a
 * real mysqldump against the real test database, attachments folded into
 * the same archive, the off-host copy's config gate in both directions,
 * and a genuine failure path returning a non-zero exit.
 *
 * Each test points config('backup.local_path') at a throwaway temp
 * directory so nothing here ever touches storage/app/backups.
 */
class BackupTest extends TestCase
{
    use RefreshDatabase;

    private string $backupDir;

    protected function setUp(): void
    {
        parent::setUp();

        $this->backupDir = sys_get_temp_dir().'/sparkbooks-backup-test-'.uniqid();
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

    /** @return string absolute path to the single .zip artifact produced */
    private function findArchive(): string
    {
        $files = glob($this->backupDir.'/sparkbooks-backup-*.zip') ?: [];
        $this->assertCount(1, $files, 'Expected exactly one backup archive to be produced.');

        return $files[0];
    }

    public function test_backup_produces_a_non_empty_archive_containing_a_real_sql_dump(): void
    {
        $this->artisan('sparkbooks:backup')->assertSuccessful();

        $archivePath = $this->findArchive();
        $this->assertGreaterThan(0, filesize($archivePath));

        $zip = new ZipArchive;
        $this->assertTrue($zip->open($archivePath) === true);

        $sql = $zip->getFromName('database.sql');
        $this->assertNotFalse($sql, 'database.sql entry missing from the archive.');
        $this->assertStringContainsString('CREATE TABLE', $sql);

        $zip->close();
    }

    public function test_backup_includes_attachments_in_the_archive_and_manifest(): void
    {
        Storage::disk('local')->put('attachments/1/receipt.pdf', '%PDF-1.4 fake receipt bytes for backup test');
        Storage::disk('local')->put('attachments/2/scan.jpg', 'fake jpg bytes');

        $this->artisan('sparkbooks:backup')->assertSuccessful();

        $archivePath = $this->findArchive();

        $zip = new ZipArchive;
        $zip->open($archivePath);

        $manifest = json_decode($zip->getFromName('manifest.json'), true);
        $this->assertIsArray($manifest);
        $this->assertTrue($manifest['attachments_included']);
        $this->assertSame(2, $manifest['attachments_file_count']);

        $this->assertNotFalse($zip->locateName('attachments/1/receipt.pdf'));
        $this->assertNotFalse($zip->locateName('attachments/2/scan.jpg'));

        $zip->close();
    }

    public function test_backup_manifest_reflects_no_attachments_when_none_exist(): void
    {
        $this->artisan('sparkbooks:backup')->assertSuccessful();

        $archivePath = $this->findArchive();

        $zip = new ZipArchive;
        $zip->open($archivePath);
        $manifest = json_decode($zip->getFromName('manifest.json'), true);
        $zip->close();

        $this->assertFalse($manifest['attachments_included']);
        $this->assertSame(0, $manifest['attachments_file_count']);
    }

    public function test_off_host_copy_is_skipped_cleanly_when_the_backups_disk_is_unconfigured(): void
    {
        // Default state: no BACKUP_S3_BUCKET, so filesystems.disks.backups.bucket
        // is blank — must not throw, must still succeed overall.
        $this->assertTrue(blank(config('filesystems.disks.backups.bucket')));

        $this->artisan('sparkbooks:backup')
            ->expectsOutputToContain('Off-host copy skipped')
            ->assertSuccessful();
    }

    public function test_off_host_copy_is_attempted_and_lands_on_the_disk_when_configured(): void
    {
        config(['filesystems.disks.backups.bucket' => 'sparkbooks-test-bucket']);
        Storage::fake('backups');

        $this->artisan('sparkbooks:backup')
            ->expectsOutputToContain('Off-host copy uploaded')
            ->assertSuccessful();

        $uploaded = collect(Storage::disk('backups')->allFiles())
            ->first(fn (string $f) => str_starts_with($f, 'sparkbooks-backup-') && str_ends_with($f, '.zip'));

        $this->assertNotNull($uploaded, 'Expected the archive to have been uploaded to the backups disk.');
        $this->assertGreaterThan(0, Storage::disk('backups')->size($uploaded));
    }

    public function test_backup_returns_non_zero_and_reports_an_error_when_mysqldump_is_unusable(): void
    {
        config(['backup.mysqldump_path' => $this->backupDir.'/does-not-exist-mysqldump']);

        $this->artisan('sparkbooks:backup')
            ->expectsOutputToContain('mysqldump')
            ->assertFailed();

        // No archive should have been left behind after a dump failure.
        $this->assertSame([], glob($this->backupDir.'/sparkbooks-backup-*.zip') ?: []);
    }
}
