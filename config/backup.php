<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Backups (spec §11)
    |--------------------------------------------------------------------------
    |
    | `sparkbooks:backup` dumps the MySQL database + the private attachments
    | disk into one timestamped .zip under `local_path`, optionally copies it
    | off-host to the `offhost_disk` flysystem disk (see the "backups" disk
    | in config/filesystems.php — config-gated: inert until a bucket is set,
    | the same DSN-gated pattern as Sentry, see config/sentry.php), then
    | prunes old artifacts to `retention.daily` + `retention.weekly` (see
    | App\Support\BackupRetentionPolicy, the pure/testable rotation rule).
    |
    | `sparkbooks:restore` uses `mysql_path` to restore a chosen artifact
    | back into an explicitly-named target database.
    |
    */

    'mysqldump_path' => env('BACKUP_MYSQLDUMP_PATH', 'mysqldump'),

    'mysql_path' => env('BACKUP_MYSQL_PATH', 'mysql'),

    'local_path' => env('BACKUP_LOCAL_PATH', storage_path('app/backups')),

    'offhost_disk' => env('BACKUP_OFFHOST_DISK', 'backups'),

    'retention' => [
        'daily' => (int) env('BACKUP_RETENTION_DAILY', 14),
        'weekly' => (int) env('BACKUP_RETENTION_WEEKLY', 8),
    ],

];
