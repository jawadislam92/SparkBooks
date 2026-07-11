# Phase 6 (FINAL) — Bank reconciliation, month-end close, exports, backups, monitoring

Spec: §7.9 (bank reconciliation), §7.13 (audit-log viewer), §11 (backup & export), §17.5 (month-end close), §17.8 (export access logging), §17.9 (monitoring/heartbeats), §13 Phase-6 exit row, §14 pre-trial checklist. This phase is mostly **process UI + ops machinery over existing accounting logic** — the one genuinely new accounting-adjacent piece is bank reconciliation. After this, v1 is COMPLETE.

Existing infra to build on / mirror:
- **AuditLogger** (`app/Support/AuditLogger.php`) — `$audit->log('action', $subject, after: [...], before: [...])`; every mutating service already uses it. Exports MUST log too (§17.8).
- **Period locks** — enforced in `LedgerService`, stored on Company (`locked_through`-style), managed via `PeriodLockController` (`period-locks`). Lock = the irreversible-ish close act.
- **CSV export pattern** — `Reports/*Controller::exportCsv` (Phase 5) + `OpeningCsvController`; a shared streaming helper (`Money::minorToDecimalString`). Report services (`app/Services/Reports/*`) produce all accountant-package data.
- **PDF** — `app/Services/InvoicePdf.php` (barryvdh/laravel-dompdf).
- **Schedule** — `routes/console.php` has `Schedule::command('ledger:verify')->dailyAt('03:00')`.
- **Bank accounts** — `BankAccount` + `BankAccountService`; `LedgerBalances`/`AccountBalances` for balances; transfers (P11) already post both sides.
- **Gates** — `reconciliation.manage` (Owner/Accountant), `exports.run` (Owner/Admin/Accountant), `audit.view` (Owner/Accountant) already in `config/permissions.php` §7.14.
- **Integrity checker** — `ledger:verify` command + `IntegrityChecker` + health screen.

Iron rules (docs/PROJECT_MAP.md §9): money BIGINT minor units, no float; reconciliation-completing operations acquire `lockForUpdate()` on the parent + re-read before validating (§10); reconciliation NEVER edits the ledger (it only marks lines reconciled — a separate reconciled flag, not a ledger write); company_id never fillable/request; every mutating + EXPORT route gated + audit-logged; design tokens only, no debit/credit words on non-accountant screens (reconciliation/close are accountant screens — debit/credit allowed); 120ms motion. Config-gated external wiring (S3, healthchecks.io, Slack) follows the Phase-0 Sentry precedent: wired but inert without env config; tests exercise the logic, not the external service.

Env: export PATH="/c/laragon/bin/php/php-8.3.30-Win32-vs16-x64:/c/laragon/bin/composer:/c/laragon/bin/nodejs/node-v22:/c/laragon/bin/mysql/mysql-8.4.3-winx64/bin:$PATH"; branch phase-6-close-ops. Tests filter per task then FULL suite + build + test:js + pint. Two KNOWN pre-existing flakes (InvoicePaymentConcurrency timing + OtherReceiptsTest random account-code collision) — if either is the ONLY failure, treat green (re-run in isolation). Commit trailer: Co-Authored-By: Claude Fable 5 <noreply@anthropic.com>

---

### Task 1: Bank reconciliation (§7.9) — schema, service, UI

The one new accounting-adjacent feature. Migrations: `reconciliations` (id, company_id, bank_account_id FK, statement_date, statement_ending_balance BIGINT, opening_reconciled_balance BIGINT [carried from the prior reconciliation's ending balance — the chain], status ['completed','reopened'], completed_by, completed_at, timestamps) + `reconciliation_lines` (reconciliation_id cascade, company_id, journal_line_id FK [the ledger line ticked], amount BIGINT [the line's signed effect on this bank account], timestamps). Add a `reconciled` marker so a journal line can't be double-reconciled (either a nullable `reconciliation_id` on journal_lines via a pivot, OR reconciliation_lines uniqueness on (journal_line_id) — choose; journal_lines are immutable so prefer the reconciliation_lines pivot with a unique index on journal_line_id per company). Models w/ BelongsToCompany, fillable sans company_id.

`app/Services/ReconciliationService.php`:
- `start(BankAccount $account, Carbon $statementDate, int $statementEndingBalanceMinor): array` — returns the unreconciled ledger lines for that account's ledger_account through statementDate (lines not already in a completed reconciliation), the opening_reconciled_balance (= prior completed reconciliation's statement_ending_balance, or 0), and the live-difference inputs. READ-only preview.
- `complete(string $key, BankAccount $account, Carbon $statementDate, int $statementEndingBalanceMinor, array $tickedJournalLineIds): Reconciliation` — idempotent; DB::transaction; **lockForUpdate on the bank account row** (§10); validate every ticked line belongs to this account + is unreconciled + dated ≤ statementDate; compute difference = statementEndingBalance − (opening_reconciled_balance + Σ ticked amounts); **REFUSE unless difference === 0** (a domain exception); create the reconciliation record + reconciliation_lines; audit `reconciliation.completed`. NO ledger write — reconciliation only records which lines cleared.
- `reopen(string $key, Reconciliation $rec): Reconciliation` — reconciliation.manage; **newest-first only** — refuse if a NEWER completed reconciliation exists for the account (a domain exception naming it), so the opening-reconciled-balance chain stays consistent; unlock its lines (delete/void its reconciliation_lines so they're unreconciled again); status reopened; audit `reconciliation.reopened`.
Controller + routes (reconciliation.manage gated; X-Idempotency-Key on complete/reopen; scoped findOrFail). UI: `resources/js/Pages/Reconciliation/{Index,Show}.tsx` — pick bank account → enter statement date + ending balance → screen lists unreconciled lines with tick checkboxes → LIVE difference (statement balance − (opening reconciled + ticked)) updates as you tick → **Finish button enabled ONLY at difference 0** → completed reconciliations list w/ a Reopen action (newest only). Accountant screen — debit/credit words allowed; tokens only, MoneyText, no gray-/indigo-/hex/any.

**Tests:** complete ONLY at difference 0 (non-zero → refused, nothing stored); ticked line must belong to account + be ≤ statementDate + unreconciled (each rejected); opening_reconciled_balance chains from prior reconciliation; a line can't be reconciled twice (unique); reopen newest-first (reopening a non-newest → refused naming the newer one; reopening newest unlocks its lines); idempotent complete/reopen; gate (staff/admin lacks reconciliation.manage → 403); scoped; **FX note:** a P2fx payment posts actual-base-received to bank, so the bank ledger line matches the statement exactly — a reconciliation over an FX-invoice payment completes at difference 0 (test it, since §17.1 P2fx exists specifically to keep reconciliation possible); audit rows for complete + reopen. Mirror: InvoicePaymentService (lock+idempotency), PeriodLockController (settings-screen shape).

---

### Task 2: Audit-log viewer (§7.13)

Read-only viewer for the append-only activity_log, gated `audit.view` (Owner/Accountant). `AuditLogController@index` + `resources/js/Pages/Audit/Index.tsx`: paginated list (timestamp, user, action, subject type/id, IP), **filters by user / module(action-prefix) / date range**; humanized action + subject (reuse the dashboard's humanization if extracted, else mirror it); NO raw before/after JSON dump in the list (a per-row expand MAY show a readable diff, but never a naked payload). Company-scoped (BelongsToCompany). NOTE: the audit log itself already captures all mutating actions (Phases 0–5) — this task is the VIEWER only, no new logging. **Tests:** route gated audit.view (Owner sees, Staff/Admin 403); filters narrow correctly (by user, by action-prefix module, by date range); scoped (another company's rows absent); pagination; no raw payload leak in the default list. Mirror: an existing Index list page + the dashboard recent-activity humanization.

---

### Task 3: List-screen CSV exports + export access logging (§11, §17.8)

CSV export on every LIST screen that lacks one (reports already have CSV from Phase 5): invoices, bills, expenses, contacts, items, bank transactions/transfers, journal entries — the data a user would want to take with them (the escape-hatch). A shared export helper (streamed CSV, `Money::minorToDecimalString`, respects current company scope + the list's filters where present). **CRITICAL (§17.8): EVERY export — these list CSVs AND the Phase-5 report CSVs AND (Task 4) the accountant package — writes an audit-log row** (who, what was exported, period/filter, when). Retrofit the Phase-5 report CSV exports to also log (they may not yet). Gate all exports `exports.run` (Owner/Admin/Accountant; Staff no). **Tests:** each list CSV exports the right rows (scoped, filtered) with correct money formatting; **every export writes exactly one audit-log row with enough detail** (a table-driven test over all export endpoints asserting an `*.exported` audit row appears — the anti-exfiltration trail); gate exports.run (Staff 403); scoped (can't export another tenant's data). Mirror: Reports/*Controller::exportCsv + AuditLogger.

---

### Task 4: Accountant package (§11) — one-click zip of the Gate-B artifacts

`AccountantPackageService` + controller: for a chosen period, generate **General Ledger, Trial Balance, P&L, Balance Sheet, A/R aging, A/P aging** as **CSVs + PDFs**, bundled into a single **zip** (ZipArchive; PDFs via dompdf mirroring InvoicePdf; CSVs via the Task-3 helper / report services). Streamed download, gated exports.run, **audit-logged** (`accountant_package.exported` with the period). The package data comes from the Phase-5 report services (base-currency, ledger-tied) — no new accounting math. This is the customer's escape hatch + the Gate-B/close artifact. **Tests:** the zip contains all 12 expected entries (6 reports × CSV+PDF) with non-empty content; the numbers in the package tie to the report services (spot-check e.g. TB debits==credits, BS balances, P&L net); gate exports.run + scoped; audit row written w/ the period; empty-company period → a valid (possibly minimal) package, no error. Mirror: InvoicePdf + Reports services + Task-3 export helper.

---

### Task 5: Month-end close checklist (§17.5)

A guided checklist SCREEN — process UI over existing machinery, NO new accounting logic. `MonthEndCloseController` + `resources/js/Pages/Close/Index.tsx` for a chosen month-end date, showing the 7 steps with done/not-done + a link to each step's screen:
1. Reconcile all bank/cash accounts through month end — done when every active bank/cash account has a completed reconciliation with statement_date ≥ month end (query Task-1 data).
2. Review A/R and A/P aging — link (manual acknowledge; agings from Phase 5).
3. Review Trial Balance — link (manual acknowledge).
4. **Sweep unposted drafts dated ≤ month end** — the screen LISTS them automatically (draft invoices/bills/expenses/manual-journals with date ≤ month end); done-state = zero such drafts (each must be sent/recorded or re-dated). This prevents the lock stranding a back-dated draft.
5. Run the integrity checker on demand — must report ZERO findings (call IntegrityChecker; show result).
6. Lock the period through month end — invokes the existing period-lock (LedgerService/PeriodLockController); the one irreversible-ish act.
7. Export the accountant package for the now-frozen period (Task 4) — after the lock.
Completing the close is audit-logged (`period.closed` or `close.completed`); steps revisitable; the lock is movable by Owner/Accountant with audit trail (already true). **Tests:** step-status computation is correct (reconcile step reflects Task-1 completions; drafts-sweep lists the right drafts and clears when none ≤ month end; checker step reflects real findings; lock step reflects the period lock); completing close writes an audit row; gated (who can close — Owner/Accountant, mirror period-lock gating); the sweep correctly catches a back-dated draft. Mirror: PeriodLockController + IntegrityChecker + the draft queries from Phase 3–4.

---

### Task 6: Backups + rehearsed restore (§11)

`app/Console/Commands/BackupDatabase.php` (`sparkbooks:backup`): dump the MySQL database (mysqldump via the configured connection) to a timestamped file; **include uploaded attachments** in scope (tar the private attachments disk alongside, or note them in the manifest); copy **off-host to an S3-compatible bucket** via flysystem (config-gated — a `backups` disk in config/filesystems.php; inert/local-only without S3 env, Sentry-precedent); **retention 14 daily + 8 weekly** (prune older). `sparkbooks:restore` (or a documented procedure script) that restores a chosen dump to a target DB. **REHEARSE the restore locally once:** dump the dev DB → restore into a scratch DB (e.g. sparkbooks_restore_test) → verify row counts / a ledger:verify on the restored data → document the exact steps in `docs/RESTORE.md`. Schedule the backup daily in routes/console.php (alongside ledger:verify). Backup FAILURE must be catchable for alerting (Task 7). **Tests:** the backup command runs and produces a dump artifact (in a temp/scratch dir; assert the file exists + is non-empty + attachments included in the manifest); retention prunes correctly (given N dated dumps, keeps 14 daily + 8 weekly, deletes the rest — test the pruning logic with fabricated filenames, no real files needed); the command is idempotent-safe to re-run; config-gated S3 copy is skipped cleanly when unconfigured (no crash). DOC: docs/RESTORE.md with the rehearsed procedure + evidence it was run. Mirror: existing artisan commands + flysystem (familiar from SA Track).

---

### Task 7: Monitoring & heartbeats (§17.9)

Config-gated (Sentry precedent — inert without env). **Dead-man's-switch heartbeats** on the three scheduled lifelines (daily backup, integrity checker `ledger:verify`, queue-worker liveness): after each successful run, ping a configured healthcheck URL (healthchecks.io-style; env-configured per lifeline; skipped if unset). Alert fires on SILENCE (that's the external service's job — we just ping on success; a dead cron never pings → external service pages). **Backup failure + integrity-checker findings alert to email + optional Slack webhook the moment they occur:** wire `ledger:verify` to dispatch an alert (email to a configured address + optional Slack webhook POST) when it finds ANY findings or evaluates zero companies (it already fails loudly — add the notification); wire BackupDatabase to alert on failure. A small `AlertNotifier` (email + optional Slack via config; both gated on env). **Tests:** the heartbeat ping fires after a successful scheduled command when configured, and is skipped (no error) when unconfigured; ledger:verify dispatches an alert when findings exist (fake the notifier/mailer + assert dispatched; assert NOT dispatched when clean); backup-failure path dispatches an alert (simulate a failure); Slack webhook is optional (email-only works without it). Use Laravel's Mail/Http fakes — do NOT hit real external services. Mirror: the Phase-0 Sentry DSN-gating + Notification/Mail patterns.

---

### Task 8: Exit verification + PROJECT_MAP + §14 pre-trial checklist + v1 COMPLETE

Full suites + build + test:js + pint + no-float grep. Curl-render the new screens (reconciliation, audit viewer, close checklist) + confirm an accountant-package zip downloads with all entries. ledger:verify clean on dev. Walk the §17.5 month-end close ONCE on dev test data (reconcile a bank account at diff 0 → sweep drafts → checker zero → lock → export package) and record it. Update PROJECT_MAP to end of Phase 6 (§2 all rows built, §5 graph, §8 tree, §10 remaining launch-track items). Update the visual status page Artifact to 7/7 phases + v1 complete. Update memory. Produce the **§14 pre-trial checklist** status (docs/PRE_TRIAL_CHECKLIST.md): tick what's done, honestly mark what needs real deploy config (S3 creds, healthchecks URLs, Slack webhook, a real accountant for Gate B, hosting decision) — these are deployment/ops, not code gaps. Whole-branch review, then merge to main. **v1 is then feature-complete** pending: the deferred accountant Gate B, a live interactive browser click-through (tooling was broken during the build), and production deploy config. Report the whole thing to Jawad.

---

## Self-Review Notes
- Phase 6 is process-UI + ops over existing machinery; the ONLY new accounting-adjacent logic is bank reconciliation (T1) — which writes NO ledger entries (it marks lines cleared), so it can't corrupt the books; its risk is the difference==0 gate + reopen-newest-first chain, both heavily tested.
- Export access logging (T3, §17.8) is a cross-cutting requirement — every export endpoint (list CSVs + Phase-5 report CSVs + accountant package) must audit-log; a table-driven test enforces it (the anti-exfiltration trail).
- Backups/monitoring (T6/T7) build the machinery + tests but the external wiring (S3, healthchecks.io, Slack) is config-gated and inert locally (Sentry precedent) — the restore is rehearsed locally against a scratch DB and documented. Honest §14 marking in T8 distinguishes "code done" from "needs deploy config".
- Reconciliation depends on P2fx's actual-base-received design (§17.1) — an FX payment lands the real bank amount, so reconciliation completes at diff 0; T1 tests this explicitly.
- Order: T1→T2→T3→T4→T5(needs T1+T4)→T6→T7(needs T6)→T8. T2/T3 independent of T1; kept serial for review simplicity.
- After Phase 6 merge: v1 COMPLETE. Remaining are launch-track (2FA §17.8, custom roles, real Gate B, deploy) — NOT v1 scope.
