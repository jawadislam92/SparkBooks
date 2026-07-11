# Restore procedure (spec §11)

This is the documented, **actually rehearsed** restore procedure for SparkBooks. It
was run once end-to-end on 2026-07-11 against the dev database (`sparkbooks`) as
part of Phase 6 Task 6, with the evidence captured below. Rehearse it again
before the real-month trial, and after any change to `BackupDatabase` /
`RestoreDatabase` or the schema.

## What produces a backup

`sparkbooks:backup` (scheduled daily at 02:00, see `routes/console.php`):

1. Runs `mysqldump` against the app's configured connection (`config/database.php`),
   writing straight to a `.sql` file via mysqldump's own `--result-file` (portable —
   no shell redirection needed on either Windows or Linux).
2. Zips that `.sql` dump together with a recursive copy of the private attachments
   disk (`storage/app/private/attachments`, see `AttachmentService`) and a
   `manifest.json` into one artifact: `sparkbooks-backup-<Y-m-d_His>.zip` under
   `config('backup.local_path')` (default `storage/app/backups`).
3. Copies that artifact to the `backups` off-host disk when configured
   (`BACKUP_S3_BUCKET` set — see `config/filesystems.php`); skips cleanly,
   logging why, when it isn't.
4. Prunes old artifacts to 14 daily + 8 weekly (`App\Support\BackupRetentionPolicy`).

## Restoring an artifact

`sparkbooks:restore <file> --database=<target>`

- `<file>` is either a `sparkbooks:backup` `.zip` (the command extracts
  `database.sql` from it to a temp dir, cleaned up afterward) or a raw `.sql` dump.
- `--database=` is **required** — the command refuses to run without it and will
  never guess or default to the app's own configured database.
- If `--database=` happens to equal the app's *configured* database, the command
  additionally requires an interactive confirmation (or `--force`) before
  proceeding, so overwriting the live/dev database by accident takes two
  deliberate signals, not one.
- Internally it pipes the SQL into the `mysql` client (`config('backup.mysql_path')`)
  via stdin against the connection details in `config/database.php`, with the
  target database substituted for whatever the app is normally configured to use.

## The rehearsal (2026-07-11)

Performed by hand in bash, using the mysql/mysqldump binaries directly per the
task brief, against the real dev database and a disposable scratch database.
Every command and its actual output is reproduced verbatim below.

### 1. Baseline: row counts + trial balance on `sparkbooks` (dev) before backup

```
$ mysql --host=127.0.0.1 --port=3306 --user=root sparkbooks -e "
SELECT 'companies' AS tbl, COUNT(*) AS cnt FROM companies
UNION ALL SELECT 'users', COUNT(*) FROM users
UNION ALL SELECT 'accounts', COUNT(*) FROM accounts
UNION ALL SELECT 'journal_entries', COUNT(*) FROM journal_entries
UNION ALL SELECT 'journal_lines', COUNT(*) FROM journal_lines
UNION ALL SELECT 'invoices', COUNT(*) FROM invoices
UNION ALL SELECT 'bills', COUNT(*) FROM bills
UNION ALL SELECT 'contacts', COUNT(*) FROM contacts
UNION ALL SELECT 'attachments', COUNT(*) FROM attachments
UNION ALL SELECT 'activity_log', COUNT(*) FROM activity_log
;"
tbl              cnt
companies        2
users            2
accounts         52
journal_entries  29
journal_lines    73
invoices         7
bills            2
contacts         3
attachments      0
activity_log     58

$ mysql --host=127.0.0.1 --port=3306 --user=root sparkbooks -e \
    "SELECT SUM(debit) AS total_debit, SUM(credit) AS total_credit FROM journal_lines;"
total_debit  total_credit
162164118    162164118
```

### 2. Produce a real backup of dev

```
$ php artisan sparkbooks:backup -v
Backup created: C:\laragon\www\SparkBooks\storage\app/backups/sparkbooks-backup-2026-07-11_044940.zip (attachments: 0 file(s))
Off-host copy skipped — not configured (no bucket set for the "backups" disk).
EXIT: 0
```

(Zero attachment files because the dev `attachments` count above is 0 — the
archive-building/manifest logic is exercised for the non-empty case by
`tests/Feature/BackupTest.php::test_backup_includes_attachments_in_the_archive_and_manifest`.)

### 3. Create the scratch database

```
$ mysql --host=127.0.0.1 --port=3306 --user=root -e \
    "DROP DATABASE IF EXISTS sparkbooks_restore_test; \
     CREATE DATABASE sparkbooks_restore_test CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
```

### 4. Restore the artifact into the scratch database

```
$ php artisan sparkbooks:restore storage/app/backups/sparkbooks-backup-2026-07-11_044940.zip \
    --database=sparkbooks_restore_test -v
Restored into database [sparkbooks_restore_test] from C:\Users\jawad\AppData\Local\Temp/sparkbooks-restore-6a51cb7654e02/database.sql.
EXIT: 0
```

The command extracted `database.sql` from the zip into a temp directory, piped it
into `mysql --database=sparkbooks_restore_test`, and cleaned up the temp
directory on completion (verified: no `sparkbooks-restore-*` directory left
behind afterward).

### 5. Verify: row counts on the restored scratch database — must match step 1 exactly

```
$ mysql --host=127.0.0.1 --port=3306 --user=root sparkbooks_restore_test -e "
SELECT 'companies' AS tbl, COUNT(*) AS cnt FROM companies
UNION ALL SELECT 'users', COUNT(*) FROM users
UNION ALL SELECT 'accounts', COUNT(*) FROM accounts
UNION ALL SELECT 'journal_entries', COUNT(*) FROM journal_entries
UNION ALL SELECT 'journal_lines', COUNT(*) FROM journal_lines
UNION ALL SELECT 'invoices', COUNT(*) FROM invoices
UNION ALL SELECT 'bills', COUNT(*) FROM bills
UNION ALL SELECT 'contacts', COUNT(*) FROM contacts
UNION ALL SELECT 'attachments', COUNT(*) FROM attachments
UNION ALL SELECT 'activity_log', COUNT(*) FROM activity_log
;"
tbl              cnt
companies        2
users            2
accounts         52
journal_entries  29
journal_lines    73
invoices         7
bills            2
contacts         3
attachments      0
activity_log     58
```

Every row count matches step 1 exactly.

### 6. Verify: scoped query — trial balance on the restored data

```
$ mysql --host=127.0.0.1 --port=3306 --user=root sparkbooks_restore_test -e \
    "SELECT SUM(debit) AS total_debit, SUM(credit) AS total_credit FROM journal_lines;"
total_debit  total_credit
162164118    162164118
```

Matches step 1 exactly (162,164,118 = 162,164,118) — the ledger balances on the
restored data, not just the row counts.

### 7. Verify: `ledger:verify` (the §9 correctness watchdog) against the restored data

Pointed the app's `DB_DATABASE` at the scratch database for one command
invocation only (no config cache was active, so the env override took effect
for that single run):

```
$ DB_DATABASE=sparkbooks_restore_test php artisan ledger:verify -v
Company 1 (SparkingAsia Test Co): clean
Company 2 (Task13 Fresh Co): clean
EXIT: 0
```

Both companies come back clean on the restored database — the full integrity
checker (document-balance, AR/AP reconciliation, vacuous-scope guard) passes
against data that only exists because it was dumped and restored.

### 8. Clean up

```
$ mysql --host=127.0.0.1 --port=3306 --user=root -e "DROP DATABASE sparkbooks_restore_test;"
```

Confirmed dropped (`SHOW DATABASES LIKE 'sparkbooks_restore_test'` returned
nothing afterward).

## Result

The restore path is proven, end to end, on real dev data: row counts for every
checked table matched exactly, the ledger's own debit/credit trial balance
matched exactly, and the full `ledger:verify` correctness watchdog reported both
companies clean on the restored database. The scratch database was dropped
afterward — dev itself was never touched by the restore, only read from to
produce the backup.

## Off-host copy (S3-compatible)

Not exercised against a real bucket in this rehearsal (no S3 credentials in this
dev environment) — the config-gate itself (skip cleanly when
`BACKUP_S3_BUCKET` is unset, attempt the upload when it is) is covered by
`tests/Feature/BackupTest.php` using a faked disk. `league/flysystem-aws-s3-v3`
is installed (`composer.json`) so setting `BACKUP_S3_BUCKET` +
`BACKUP_S3_ACCESS_KEY_ID` / `BACKUP_S3_SECRET_ACCESS_KEY` (or the shared `AWS_*`
vars) in a real environment is enough to turn the off-host copy on — no further
code or package changes needed, the same "wired but inert without env" shape as
Sentry (`config/sentry.php`).
