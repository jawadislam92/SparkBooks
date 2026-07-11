# SparkBooks — §14 Pre-Trial Checklist (v1)

Honest status of the spec §14 "pre-trial" items as of the Phase 6 merge (v1 feature-complete).
Legend: ✅ done in code + tested · ⚙️ code done, needs real deploy config to activate · ⏳ deferred / launch-track · 👤 needs a human/business action.

_Last updated: end of Phase 6 (2026-07-11)._

## Accounting correctness (the core)
- ✅ Double-entry ledger with a single write-gate (`LedgerService`); posted entries immutable; corrections are reversals.
- ✅ Posting rules P1–P15 + FX-lite (P1fx/P2fx) built and tested against the spec's worked examples.
- ✅ Integer minor-unit money everywhere; **no float on any money path** (CI-enforced grep).
- ✅ Integrity checker (§9) — never-vacuous, fails loudly on zero companies / zero-entry posting companies; covers every source type; contact-level AR/AP reconciliation.
- ✅ Trial Balance debits == credits; Balance Sheet always balances (incl. across a fiscal-year boundary and when activity predates books-start — a real bug caught during Phase-5 exit and fixed); aging totals tie to the 1100/2000 control accounts; tax summary classified by source type; **report tie-out tests green (§12.9)**.
- ✅ 1192 PHP + 61 JS tests green on real MySQL; Pint clean.

## Multi-tenancy, permissions, audit
- ✅ Strict company isolation (`BelongsToCompany` + `CompanyContext`; missing context throws).
- ✅ §7.14 permission matrix enforced (asserted by tests); Staff cannot post to the ledger.
- ✅ Append-only audit log capturing every mutating action; **and exports (§17.8)** — all 14 export endpoints write an `*.exported` row (anti-exfiltration trail); audit-log viewer (filter by user/module/date), no raw before/after leak.
- ✅ Idempotency (exactly-once posting wrapper) + DB unique/CHECK constraints (§17.2–3).

## Close, reconciliation, exports
- ✅ Bank reconciliation (§7.9) — completes only at difference 0; reopen newest-first; out-of-order completion rejected; writes no ledger entries; an FX payment reconciles at difference 0.
- ✅ Month-end close checklist (§17.5) — 7 steps over existing machinery (reconcile → aging → TB → drafts-sweep → checker → lock → export); the lock is the freeze `LedgerService` enforces.
- ✅ CSV export on every list screen + report; **one-click accountant package** (§11) — GL/TB/P&L/BS/AR+AP aging as CSV+PDF in a zip, audit-logged (verified: a real 12-entry, ~7.4 MB zip downloads).
- 👤 **Month-end close walked through once on real data** — the computation is fully test-covered and the screen renders live; a human walk-through on the real SparkingAsia month is still to be done during the trial.

## Backups & monitoring (need deploy config to activate)
- ✅ Daily DB dump command (`sparkbooks:backup`) incl. attachments; retention 14 daily + 8 weekly (pure, proven); restore command with a two-signal safety guard.
- ✅ **Restore rehearsed once** — dev DB → scratch DB, row counts + trial balance matched, `ledger:verify` clean on the restored copy; procedure documented in [docs/RESTORE.md](RESTORE.md).
- ⚙️ **Off-host backup copy** — S3-compatible via flysystem, config-gated; needs `BACKUP_S3_BUCKET` + credentials set in production to actually copy off-host. Inert (local-only) until then.
- ⚙️ **Monitoring alerts** — error monitoring (Sentry, DSN-gated from Phase 0); alert-on-integrity-findings and alert-on-backup-failure to email + optional Slack; needs `MONITORING_ALERT_EMAIL` and/or `MONITORING_SLACK_WEBHOOK` set. A DB password can never reach an alert body/log (redacted).
- ⚙️ **Dead-man's-switch heartbeats** (§17.9) on daily backup / integrity checker / queue worker — ping code is wired; needs `*_HEARTBEAT_URL` env values (e.g. healthchecks.io) so silence pages someone.
- 👤 **Test alert received on each channel** — send a test to email/Slack/heartbeat once the env is configured.

## Deploy-config items surfaced during the build (not code gaps)
- ⚙️ **PHP `zip` extension** must be enabled in production PHP (the accountant package uses `ZipArchive`; it was disabled by default in the dev php.ini).
- ⚙️ **mysqldump** on the production host: prefer passing the DB password via `MYSQL_PWD`/`--defaults-extra-file` rather than a CLI `--password=` arg on a shared/multi-user host (visible in `ps`); add `--no-tablespaces` if the DB user lacks the `PROCESS` privilege on a managed host.
- 👤 **Hosting decision** (§ "Hosting", by Phase 6): Hostinger like SA Track, or a VPS for cron/queue/backup control. Cron must run the daily backup + `ledger:verify` + queue worker; queue worker must be alive for emails + the queue heartbeat.

## Launch-track (explicitly NOT v1 — deferred by design)
- ⏳ 2FA (TOTP) — §17.8 launch track (pull into v1 only if there was slack; there wasn't).
- ⏳ Custom roles — v1 uses the four fixed role bundles.
- 👤 **Accountant Gate A/B** — deferred at your request (no accountant hired yet). The Gate-B artifacts now all exist (Trial Balance, General Ledger, P&L, Balance Sheet, A/R & A/P aging + the one-click accountant package) — hand these to an accountant for sign-off before real production use.

## Known follow-ups (tracked, non-blocking)
- OtherReceiptService category guard doesn't exclude the Tax-subtype (2100) account — the Tax Summary report is robust to it (residual adjustments), but a receipt shouldn't be categorizable as tax-payable; small guard fix.
- A dedicated bank-transactions list screen (transfers currently only have a create form; `bank-accounts.export` stands in).
- A live interactive browser click-through of the whole app — this build verified via the full test suite + `ledger:verify` + curl-rendered pages (the interactive browser tooling was unavailable during the build).
