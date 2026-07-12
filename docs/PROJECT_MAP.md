# SparkBooks — Project Map

> **This is the living map of the whole codebase.** It exists so that no matter how much
> conversation context is lost between sessions, anyone (human or AI) can open this one file
> and understand what exists, how it connects, and what is not built yet.
>
> **Maintenance rule:** update this file at the end of every phase (and whenever a new
> service/controller/screen/table is added). It is committed to git. If it ever disagrees with
> the code, the code wins — fix this file.
>
> **Phase 6 (FINAL v1) shipped:** — v1 feature-complete (bank reconciliation (§7.9,
> `ReconciliationService` — NEVER writes the ledger, only marks `journal_lines` as cleared via
> a `reconciliation_lines` pivot; `complete()` refuses unless the live difference is exactly 0;
> `reopen()` is newest-first only, and `complete()` itself refuses an out-of-order statement date
> — both keep the `opening_reconciled_balance` chain consistent), a read-only audit-log viewer
> (§7.13, humanized via the shared `AuditHumanizer` so it can never drift from the dashboard's own
> recent-activity widget, and never leaks a row's raw before/after JSON), list-screen CSV exports
> + export-access logging across all 14 export endpoints (§11/§17.8, via the shared `CsvExport`
> helper — auditing is baked into the same call that streams the response, so it can't be skipped
> by omission), the Accountant Package one-click zip (§11 — General Ledger/Trial Balance/P&L/
> Balance Sheet/A/R aging/A/P aging, each as a CSV + a PDF, 12 entries total, built entirely from
> the existing Phase 5 report services with no new accounting math), the month-end close checklist
> (§17.5, `MonthEndCloseService` — 7 steps computed live over existing machinery: reconcile /
> aging-review / TB-review / drafts-sweep / integrity-check / period-lock / export, no new
> accounting logic, only 2 of the 7 steps are stored state), scheduled backups + a rehearsed
> restore procedure (§11 — 14-daily + 8-weekly retention via the pure `BackupRetentionPolicy`,
> off-host S3 copy config-gated, `docs/RESTORE.md` documents an actual rehearsal run), and
> monitoring/heartbeats (§17.9 — alert-the-moment-it-happens via email + optional Slack on backup
> failure or `ledger:verify` findings, dead-man's-switch heartbeat pings for the backup/integrity/
> queue-worker lifelines, both config-gated exactly like Sentry's DSN so monitoring ships inert by
> default) — built on branch `phase-6-close-ops`. Phase 6 closed out v1 against the binding spec;
> what follows is a real-world post-v1 phase, not spec scope-creep.
>
> **Last updated: end of Phase 7 — accountant essentials** (sub-accounts — a hierarchical chart of
> accounts via `accounts.parent_id/is_postable/depth/path`, leaf-only posting enforced three ways,
> `AccountService::createChild/makeParent/moveSubtree`, system accounts made un-nestable after a
> CRITICAL fix; consistent archiving across accounts/contacts/items/bank-accounts, hidden from new-
> document pickers but retained in history/reports; visible gap-free entry numbers
> (`journal_entries.entry_number`, `JE-000142`) allocated through the existing `DocumentNumberService`
> counter machinery; the **Statement of Cash Flows** — `accounts.cf_class` + `CashFlowReport` running
> TWO independently-coded engines (indirect NI+add-backs vs. direct cash-leg classification) that
> must agree; a report **drill-down library** (`TransactionDetailReport`, `AccountQuickReport`) that
> P&L/Balance Sheet/Trial Balance now link into; a **customer/vendor report library**
> (`ContactLedgerReport`, 9 methods — balance summary/detail, open invoices, unpaid bills,
> collections, statements, ×2 for AR/AP) all tying to 1100/2000; and a Settings → Backup/Export
> screen (`FullDataExportService` — a 9-CSV full-data zip, alongside the existing Accountant Package).
> **Every one of these is additive**: the two/three new migrations only add nullable columns +
> backfill existing rows into the pre-Phase-7 shape (every account becomes a top-level postable leaf;
> every journal entry gets a number) — zero existing balance moved. Built on branch
> `phase-7-accountant-essentials`. See §10 for what's still deferred (classes/segments, payroll,
> WHT/PST, estimates/POs, credit notes, UI customization — all later phases) and two open
> non-blocking notes from review.
> Companion docs: the binding spec is [`superpowers/specs/2026-07-08-accounting-software-v1-design.md`](superpowers/specs/2026-07-08-accounting-software-v1-design.md);
> phase plans live in [`superpowers/plans/`](superpowers/plans/) (Phase 7's is
> [`2026-07-12-phase-7-accountant-essentials.md`](superpowers/plans/2026-07-12-phase-7-accountant-essentials.md));
> per-task build/review evidence is in `.superpowers/sdd/` (git-ignored scratch — read
> `progress.md` there when resuming).

---

## 1. What SparkBooks is

A multi-tenant, QuickBooks-class accounting SaaS. A real double-entry ledger lives underneath
approachable screens; users never see debits/credits except on the accountant-facing screens.
First real user: SparkingAsia. Long-term: sell as SaaS. Stack: **Laravel 13 + Inertia + React +
TypeScript + Tailwind v3 + MySQL 8.4**, PHP 8.3 (Laragon).

**Currency decision (2026-07-08):** base currency PKR, **with a USD option** — foreign-currency
invoicing is being built into v1 (spec §17.1 Path 2 "FX-lite"), not deferred. See §7 below.

---

## 2. Status at a glance

| Area | State | Phase |
|---|---|---|
| Multi-tenancy, company context, company switcher | ✅ built | 0 |
| Roles & permissions (Owner/Admin/Accountant/Staff, §7.14 matrix) | ✅ built | 0 |
| Append-only audit log | ✅ built | 0 |
| Idempotency (exactly-once posting wrapper) | ✅ built | 0 |
| Auth + onboarding (create company, seed chart) | ✅ built | 0 |
| Error monitoring (Sentry, DSN-gated) + CI | ✅ built | 0 |
| Design system (palette, fonts, BalanceBeam) + app shell | ✅ built | 1 |
| Chart of accounts (23-account seed) + screens + drill-down | ✅ built | 1 |
| **LedgerService — the single ledger write-gate** | ✅ built | 1 |
| Manual journal entries (service + editor UI) | ✅ built | 1 |
| Period locks (settings + enforcement) | ✅ built | 1 |
| Trial Balance report + CSV export | ✅ built | 1 |
| Integrity checker (nightly watchdog) + health screen | ✅ built | 1 |
| Contacts (customers/vendors, type filter) | ✅ built | 2 |
| Items catalog (income account + tax rate defaults) | ✅ built | 2 |
| Tax rates (+ `Money::taxMinorUnits` half-up rounding helper) | ✅ built | 2 |
| Bank/cash accounts + transfers (P11) + other receipts (P14) | ✅ built | 2 |
| Opening balances (P8a–c, per-contact, re-run semantics) + CSV import templates | ✅ built | 2 |
| Multi-currency — **FULL (Phase 3)**: FX Gain/Loss accounts (4950/5950), plus the complete FX-lite posting rules — foreign-currency invoice send (P1fx) and foreign-currency payment with realized FX gain/loss (P2fx), settlement rounding, checker tie-out under FX. Was foundation-only at Phase 2 exit | ✅ built | 2→3 |
| Nav (Overview/Sales/Books/Reports/Settings) + dashboard (cash position + counts) | ✅ built | 2 |
| Integrity checker: source-type-aware (reversals never false-flagged; unrecognized source_type fails loud); Phase 3 extended it to invoice/invoice_payment source coverage + the FX-tolerant base-AR tie-out (check (c) split) + opening-invoice AR attribution; Phase 4 extended it again to expense/bill/bill_payment source coverage + bill document_balance/amount_paid/status tie-out + contact-level AP reconciliation for opening bills (the AR-side fbbe763 lesson applied proactively) + `postedInvoiceStatuses()`/`postedBillStatuses()` derived from each status enum's own `isPosted()` (was a hand-duplicated const list) | ✅ extended | 2→3→4 |
| **Invoicing (money in)** — lifecycle (draft→sent→partially_paid→paid, void), gap-free numbering, draft CRUD + per-line tax, send (P1) posting AR/income/tax, payments (P2/P3, row-locked, real cross-process concurrency proven), void with payments-first refusal (P9), opening-invoice reconciliation (payable via the unmodified payment path), FX-lite send+payment (P1fx/P2fx), branded PDF, decoupled email + resend, full UI. Phase 4 retro-fix: void() now also refuses (friendly 422, not a confusing 404) on an is_opening invoice — correcting one must go through Opening Balances instead | ✅ built | 3→4 |
| **Expenses & bills (money out) — posting lifecycle** — draft CRUD + amount-based line calc (`DocumentLineCalculator`, no qty×price, spec §7.8) + `CategoryAccountGuard` (dual-layer, mirrors `OtherReceiptService`'s guard shape: allows expense/asset/liability/equity categories — enables tax remittance via 2100 Tax Payable (P12), owner drawings via an equity account like 3900 (P13), fixed-asset purchases via an asset account like 1500 (P15); blocks control (AR/AP)/cash-bank/income/archived accounts); record expense (P4/P5 — Dr category per line, ONE combined Dr 2100 for input tax [a DEBIT, opposite invoicing's output-tax credit], Cr the paid-from bank/cash account); receive bill (P6/P6t — Cr 2000 AP with contact_id); pay bill (P7, row-locked overpayment guard, the exact AP mirror of invoice payments' proven concurrency-safe pattern); void (P9 reversals: void expense/bill-payment/bill, payments-first refusal, is_opening guard); opening vendor payables → is_opening Bills (payable via the completely unmodified P7 path) + `reconcile-opening-bills` backfill for pre-Task-7 opening payables | ✅ built | 4 |
| **Expenses & bills — attachments, UI, checker** — receipt/bill-scan attachments (spec §17.8, hardened: content-sniffed allowlist [pdf/jpg/png/webp via `finfo`, never the client-declared mime/extension], private `local` disk, uuid filenames, scoped download, full audit trail on upload/delete/download, draft-delete cascade); full Purchases UI (Expenses/Bills Index with status filters + Bills' overdue highlight, Show detail pages for every status, Edit draft editors, Record/Receive/Pay/Void dialogs, shared `Components/documents/*`); integrity checker extended to expense/bill/bill_payment source coverage + contact-level AP reconciliation for opening bills (the AR-side fbbe763 lesson applied proactively) | ✅ built | 4 |
| **Profit & Loss + Balance Sheet** — both built on `LedgerBalances` (the one shared per-account journal_lines aggregation every Phase 5 report reads through, reproducing TrialBalanceReport's own inclusion rule exactly) and `ReportPeriod` (fiscal-year math off `companies.fiscal_year_start_month`). P&L: income/expense movement over a period + monthly-comparison columns, net = income − expenses. Balance Sheet: assets/liabilities/equity as-of a date; Retained Earnings = account 3200's posted balance + every COMPLETED fiscal year's net income, with its own Current-Year-Earnings line — the roll-up's lower bound is `min(books_start_date, earliest posted entry_date)` so it never drops pre-books-start activity and always balances (incl. across a fiscal-year boundary) | ✅ built | 5 |
| **A/R & A/P Aging + General Ledger + Tax Summary** — ONE Aging engine (`AgingReport`, direction param) buckets each contact's outstanding balance (Current/1–30/31–60/61–90/90+) by ITS SOURCE DOCUMENT'S own due date — the one deliberate exception to "reports derive from journal_lines, never document totals" (§9), and only for the bucket date; amounts still tie to 1100/2000, incl. a date-gated opening-document contribution. General Ledger: per-account opening/lines/running-balance/closing — the drill-down target every other report links into; an optional contact filter narrows the listed lines but honestly hides the balance columns (they'd misrepresent a per-contact sub-ledger). Tax Summary: 2100 Tax Payable split collected (invoices) / input (expense·bill tax_total) / remittances (P12 category-2100 lines) / adjustments — classified by each entry's own `source_type`, NEVER by debit/credit column (a reversal swaps sides), adjustments computed as a structural RESIDUAL so the tie-out can't silently drift | ✅ built | 5 |
| **Dashboard (spec §7.12)** — cash position + counts (Phase 2, unchanged) plus: outstanding/overdue invoices (money from `AgingReport`'s base-correct AR total, NOT `Invoice.balance`, which is doc-currency on an FX invoice), unpaid bills (`Bill.balance` summed directly — bills are base-currency-only), a current-fiscal-year-to-date P&L snapshot, a 6-month income-vs-expense Chart.js bar chart (the app's only Chart.js surface), and a recent-activity feed gated on `audit.view` (empty list, never 403, for anyone else) | ✅ built | 5 |
| **Bank reconciliation** (§7.9, diff-0 gate, reopen-newest-first, no ledger write, FX-reconciles-at-0) | ✅ built | 6 |
| Audit-log viewer (§7.13, humanized action/subject, no-raw-JSON-leak) | ✅ built | 6 |
| List-screen CSV exports + export access logging (§17.8, 14 endpoints, every one audit-logged) | ✅ built | 6 |
| Accountant package — one-click zip (§11, 6 reports × CSV+PDF, 12 entries, zero new accounting math) | ✅ built | 6 |
| Month-end close checklist (§17.5, 7-step checklist computed over existing machinery) | ✅ built | 6 |
| Backups + rehearsed restore (§11, 14 daily + 8 weekly retention, off-host S3 config-gated, `docs/RESTORE.md`) | ✅ built | 6 |
| Monitoring/heartbeats (§17.9, alert-on-findings/backup-failure via email+Slack, dead-man's-switch heartbeats) | ✅ built | 6 |
| Sub-accounts — hierarchical chart of accounts (`parent_id`/`is_postable`/`depth`/materialized `path`, leaf-only posting enforced 3 ways, `AccountService::createChild/makeParent/moveSubtree`, system accounts un-nestable) | ✅ built | 7 |
| Account/contact/item/bank-account archiving — consistent, hidden from new-doc pickers, retained in history/reports, touches no balance | ✅ built | 7 |
| Visible entry numbers (`journal_entries.entry_number`, `JE-000142`, gap-free per company, `EntryNumberBackfiller` for legacy rows) | ✅ built | 7 |
| **Statement of Cash Flows** (indirect + direct engines cross-checked, `accounts.cf_class`, ties to Balance Sheet cash at both dates) | ✅ built | 7 |
| Report drill-down library — Transaction Detail + Account QuickReport (P&L/BS/TB figures now link into a live GL-style drill-down) | ✅ built | 7 |
| Customer/Vendor report library — balance summary+detail, Open Invoices, Unpaid Bills, Collections, Customer/Vendor Statement (9 methods, ties to 1100/2000) | ✅ built | 7 |
| Settings → Backup/Export screen (`FullDataExportService` 9-CSV full-data zip, alongside the existing Accountant Package) | ✅ built | 7 |

Tests: **1398 PHP + 61 JS**, all green on real MySQL (verified via `php artisan test` +
`npm run test:js` at Phase 7 exit — full suite, no isolation reruns needed). No float on any money
path (CI-enforced grep, zero hits in `app/`).
Every Phase 5 report carries a §12.9 tie-out test: P&L (net = income − expenses), Balance Sheet
(always balances, incl. across a fiscal-year boundary and pre-books-start activity), A/R & A/P
aging (ties to 1100/2000, incl. opening documents), Tax Summary (classified by source-type, never
debit/credit column), Trial Balance (debits == credits) — every report reads `journal_lines` via
`LedgerBalances`, with `AgingReport`'s own source-document join being the one deliberate exception
(see §5). Phase 6 adds its own tie-out coverage: reconciliation (difference-must-be-exactly-0,
the reconciliation_lines unique-index double-reconcile guard, newest-first reopen, an FX invoice
payment still reconciles to exactly 0 since P2fx posts actual-base-received to the bank ledger
line), month-end close (each of the 7 steps computed against real system state — reconcile/aging/
TB/drafts/integrity/lock/export — no double-counting), every export endpoint asserted to write its
`AuditLogger` row (§17.8), and the accountant package's bundled report figures asserted to match
each standalone report service's own output (debits==credits, balance sheet balances, net profit
ties out) — not byte-for-byte against the standalone CSV endpoints, since the package renders its
own CSVs into an in-memory zip rather than calling those controllers directly (see
`AccountantPackageService`'s own class docblock).
Phase 7 adds its own tie-out coverage: sub-accounts (a fresh migration run leaves every existing
account a top-level postable leaf — `parent_id`/`is_postable`/`depth`/`path` all at their pre-
Phase-7-equivalent default, TB/BS byte-identical before/after, tested; every report's rollup rolls
up leaf rows into parent subtotals WITHOUT double-counting — grand total is always Σleaves only);
the Statement of Cash Flows' own **six exact-integer assertions** (`CashFlowReportTest`, all
passing): Σdebit==Σcredit in-period, CFO+CFI+CFF+FX==closing−opening cash, CFO computed by the
indirect method == CFO computed by the direct method (the strongest cross-check — two
independently-coded engines must agree), SCF opening/closing cash == Balance Sheet cash at both
dates, every cash line classified (zero unclassified), and the depreciation add-back sourced from
the depreciation EXPENSE account's own movement — never accumulated depreciation's; the drill-down
library's `TransactionDetailReport` per-account subtotal ties to `LedgerBalances::movement` (the
same primitive every Phase 5 report already trusted); and the customer/vendor report library's 9
methods all tie to the 1100/2000 control accounts (aggregate AND per-contact), reviewer-probed
against a voided document, an overpayment, and boundary dates to prove the tie-out is independent
of `LedgerBalances`' flat SUM vs. the per-source GROUP BY these reports use.

---

## 3. How to run it (dev)

- Every shell first needs Laragon tools on PATH:
  `export PATH="/c/laragon/bin/php/php-8.3.30-Win32-vs16-x64:/c/laragon/bin/composer:/c/laragon/bin/nodejs/node-v22:/c/laragon/bin/mysql/mysql-8.4.3-winx64/bin:$PATH"`
- MySQL root has **no password**. Dev DB `sparkbooks`, test DB `sparkbooks_test`.
- Backend tests: `composer test` (→ `php artisan test`). Frontend: `npm run test:js`. Lint: `composer lint:check`.
- Dev server: preview config named `sparkbooks` on **port 8010** (`php artisan serve --port=8010`).
- Build assets: `npm run build`.

---

## 4. The spine — how one journal entry flows

Everything money-related is a variation of this path. Read it once and the architecture makes sense.

```
Browser (React page, e.g. Journal/New.tsx)
  │  POST /journal-entries  { entry_date, memo, lines:[{account_id, debit, credit}] }  + X-Idempotency-Key
  ▼
routes/web.php                       ── gate: can:ledger.post_manual
  ▼
SetCompanyContext (middleware)       ── sets CompanyContext = user's current company (tenant scope now active)
  ▼
ManualJournalController::store
  ▼
StoreManualJournalRequest            ── validates shape, one-sided lines, uuid key
  ▼
ManualJournalService::post
  │   IdempotencyService::execute(key)      ── exactly-once: replays prior result on retry
  │     └ DB::transaction                    ── document + ledger commit or roll back together
  │         ├ ManualJournal::create          ── the source document
  │         ├ LedgerService::post(EntryDraft) ── THE GATE (see §6). Validates iron rules, writes journal_entries + journal_lines
  │         └ AuditLogger::log('manual_journal.posted')
  ▼
Redirect → Journal/Index.tsx  (Inertia)
```

**Read paths** (reports) never write and never go through LedgerService — they only `SELECT` from
`journal_lines` (+ `journal_entries` for the date). All three read services derive balances the
**same** way (`SUM(debit) - SUM(credit)`), so they can never disagree:
`AccountBalances`, `Reports/TrialBalanceReport`, `IntegrityChecker`.

---

## 5. Dependency graph (who depends on whom)

Arrows mean "calls / depends on". `CompanyContext` and `BelongsToCompany` are ambient (almost
everything touches them) so they're drawn once.

```
                          ┌──────────────────────────────────────────────┐
  Tenancy backbone:       │  SetCompanyContext ─▶ CompanyContext (scoped) │
                          │  BelongsToCompany (trait) reads CompanyContext │ ◀── every tenant Model uses this trait
                          └──────────────────────────────────────────────┘

  WRITE side (money in/out):

    ManualJournalController ─▶ ManualJournalService ─┬─▶ IdempotencyService ─▶ IdempotencyKey (model)
                                                     ├─▶ LedgerService ──────▶ JournalEntry, JournalLine (models)
                                                     │        ▲                 (immutable; CHECK chk_one_sided;
                                                     │        │                  unique journal_entries_source_unique)
                                                     │   reads Account (control-account + archived rules,
                                                     │                  reads Company.books_locked_through for lock)
                                                     └─▶ AuditLogger ─────────▶ ActivityLog (model, append-only)

    LedgerService::reverse ── posts a mirror entry (entry_role=reversal) back through post()

    ── Phase 2 write-side services (same shape: Idempotency → LedgerService → Audit) ──

    TransferController ─▶ TransferService ──────────▶ LedgerService (P11: Dr to-account / Cr from-account)
                                                    └─▶ Transfer (model, source doc) + AuditLogger
                              reads BankAccount ×2 (tenant + archived + locked-period guards, from≠to, amount≥1)

    OtherReceiptController ─▶ OtherReceiptService ──▶ LedgerService (P14: Dr bank/cash / Cr category)
                                                    └─▶ OtherReceipt (model) + AuditLogger
                              guards: category must NOT be a bank/cash-linked or control (AR/AP) account
                              (IneligibleCategoryAccount, checked caller-independently inside the service)

    OpeningBalanceController ─▶ OpeningBalanceService ─▶ LedgerService (ONE combined entry, source_type=
                                                       │   'opening_balance_run': P8a Dr bank/cash lines,
                                                       │   P8b Dr 1100 AR per customer + contact_id, P8c Cr
                                                       │   2000 AP per vendor + contact_id, one 3100 OBE plug)
                                                       ├─▶ OpeningBalanceRun + OpeningDocument (models;
                                                       │     composite tenant FK contact_id+company_id)
                                                       └─▶ AuditLogger
                              re-run: reverses prior run's entry first (OpeningBalancesLocked guards once
                              any OTHER non-opening entry exists), then posts fresh
                              Phase 3 T6 / Phase 4 T7: each customer_lines/vendor_lines row ALSO
                              creates an is_opening Invoice/Bill respectively (see the Phase 3/4
                              write-side blocks below) — no ledger posting of its own, the P8b/P8c
                              line above already covers it
    OpeningCsvController ─▶ OpeningCsvParser ── STAGES ONLY, never posts (no LedgerService/OpeningBalanceService
                                                 call); Money::parseToMinor, all-or-nothing, line-numbered errors
                              also serves the §17.7 CSV templates (bank/customer-invoice/vendor-bill kinds)

    ── Phase 3 write-side services: invoicing (same Idempotency → LedgerService → Audit shape) ──

    InvoiceController ─▶ InvoiceService ──┬─▶ DocumentNumberService (insertOrIgnore + lockForUpdate
                          (draft CRUD,    │     row on document_counters(company,type); gap-free, race-safe)
                          send, void)     ├─▶ InvoiceCalculator (per-line qty×price half-up, then
                                          │     Money::taxMinorUnits per line, summed — never total×rate)
                                          ├─▶ FxConverter (send-time fx_rate resolution + toBase();
                                          │     single source of truth InvoicePaymentService also calls)
                                          ├─▶ LedgerService  P1  (domestic send): Dr 1100 AR (+contact_id) /
                                          │                       Cr income per line / Cr 2100 Tax Payable
                                          │                       (one combined line, only if tax_total>0)
                                          │                  P1fx (FX send): same shape, each component
                                          │                       converted to base + rounded half-up BEFORE
                                          │                       summing (never total×rate) — spec §17.1
                                          │                  P9  (void): generic reverse() — REFUSED while
                                          │                       any payment is still 'posted'
                                          │                       (InvoiceHasLivePayments)
                                          ├─▶ Invoice, InvoiceLine (models; lines FROZEN once posted —
                                          │     InvoiceLine::booted() guard — corrections are void+reissue)
                                          └─▶ AuditLogger
                              tax_rate_snapshot frozen at send (editing a tax rate later never touches a
                              sent invoice); $0-value lines skip their own ledger line but stay on the
                              document; all-$0/no-tax invoice rejected before a number is burned
                              (InvoiceHasNoValue)

    InvoiceController ─▶ InvoicePaymentService ─┬─▶ LedgerService  P2/P3 (domestic): Dr bank/cash / Cr AR
                          (recordPayment,        │                  (+contact_id)
                          voidPayment)            │                 P2fx (FX payment): Dr bank = actual base
                                                  │                  received; Cr AR = relief at the INVOICE's
                                                  │                  own fx_rate (not this payment's implied
                                                  │                  rate); diff → Cr 4950 FX Gain / Dr 5950
                                                  │                  FX Loss (no 3rd line if diff=0, always
                                                  │                  true domestically by construction)
                                                  ├─▶ FxConverter::toBase (partial-payment AR relief)
                                                  ├─▶ InvoicePayment (model) + AuditLogger
                                                  └─▶ BankAccount (resolves the receiving ledger account)
                              overpayment/double-payment guard: lockForUpdate() READ of the invoice row
                              INSIDE this method's own transaction — a second concurrent payment blocks on
                              that lock until the first commits, then re-reads the reduced balance and is
                              correctly rejected (proven under REAL cross-process contention — see
                              InvoicePaymentConcurrencyTest, Task 13)
                              settlement rounding: the payment that brings the DOCUMENT balance to exactly 0
                              relieves the entire REMAINING base AR (not rate×amount) — no phantom paisa of
                              AR can ever survive a fully paid invoice (spec §17.1)

    OpeningBalanceService (Phase 2, extended Task 6) ─▶ createOpeningInvoice() ─▶ Invoice (is_opening=true,
                              status=Sent) — NEVER calls LedgerService itself; the AR was already posted
                              once by THIS RUN's own P8b combined entry (keyed by contact_id, not by any
                              invoice id) — an is_opening invoice is a payable DOCUMENT wrapper around AR
                              that's already on the books, not a second posting. Payable via the completely
                              unmodified InvoicePaymentService (P2/P3) once Sent. Re-run voids prior
                              is_opening invoices — but voidPriorRun() is only ever reachable before any
                              real (non-opening) transaction exists company-wide, so a contact can never
                              have two live is_opening invoices at once (IntegrityChecker's opening-AR
                              attribution below relies on that).
                          ReconcileOpeningInvoices (artisan sparkbooks:reconcile-opening-invoices) ─▶ backfills
                              an is_opening Invoice for any pre-Task-6 opening receivable that doesn't have
                              one yet (idempotent; whole-branch carry-in closed Task 6; re-run at Phase 3
                              exit, Task 13, found 0 to do on the dev DB — the one existing opening document
                              already had its invoice link)

    InvoiceController ─▶ InvoicePdf ─▶ barryvdh/laravel-dompdf — branded PDF, dual-currency (doc + ≈base)
                              on a sent+ FX invoice; gated same as the invoice itself (tenant + status)

    InvoiceController ─▶ SendInvoiceEmail (queued Job) ─▶ InvoiceMail (Mailable, renders InvoicePdf as an
                              attachment) ─▶ EmailAttempt (model: status sent|failed + error, one row per
                              attempt) + Invoice.email_status ('not_sent'|'queued'|'sent'|'failed')
                              DECOUPLED (spec §17.6): a mail failure NEVER touches the ledger or the
                              invoice's posted state — only email_status/EmailAttempt change. Idempotency-
                              keyed (send + resend), so a double-click queues the job once.

    ── Phase 4 write-side services: expenses & bills (same Idempotency → LedgerService → Audit
       shape as invoicing above; base-currency only — no FX in this phase, spec §17) ──

    ExpenseController ─▶ ExpenseService ──┬─▶ IdempotencyService ─▶ IdempotencyKey (model)
                          (draft CRUD,     ├─▶ CategoryAccountGuard::guard (re-checked service-level,
                          record, void)    │     per line — the caller-independent half of the
                                          │     dual-layer guard; ExpenseController::createLines
                                          │     re-checks the same rule at draft-write time)
                                          ├─▶ DocumentLineCalculator (amount-based: tax per line on
                                          │     the typed net amount, no qty×price — spec §7.8)
                                          ├─▶ LedgerService  P4/P5 (record): Dr category account per
                                          │                       line / Dr ONE combined 2100 Tax
                                          │                       Payable line if tax>0 (input tax is
                                          │                       a DEBIT — nets against output tax,
                                          │                       opposite invoicing's output-tax
                                          │                       credit) / Cr the paid-from bank/cash
                                          │                       account's linked ledger account
                                          │                  P9  (void): generic reverse() — no
                                          │                       live-payments/is_opening concept
                                          │                       (an expense is a single cash-out
                                          │                       event, always reversal-simple)
                                          ├─▶ Expense, ExpenseLine (models; lines FROZEN once the
                                          │     expense leaves draft — INCLUDING void, unlike
                                          │     InvoiceLine which only freezes on isPosted() —
                                          │     ExpenseLine::booted() guard)
                                          └─▶ AuditLogger

    BillController ─▶ BillService ──┬─▶ IdempotencyService, CategoryAccountGuard, DocumentLineCalculator
                      (draft CRUD,  │     (all three identical to ExpenseService above — same shape)
                      receive,      ├─▶ LedgerService  P6/P6t (receive): Dr category per line / Dr
                      void)         │                       ONE combined 2100 Tax Payable line if
                                    │                       tax>0 / Cr 2000 Accounts Payable for the
                                    │                       gross total WITH the vendor's contact_id
                                    │                  P9  (void): generic reverse() — REFUSED while
                                    │                       any payment is still 'posted'
                                    │                       (BillHasLivePayments) or if is_opening
                                    │                       (BillIsOpening — manage via opening
                                    │                       balances instead; the exact mirror of
                                    │                       Invoicing\InvoiceIsOpening, folded into
                                    │                       InvoiceService::void this same phase as a
                                    │                       Phase 3 carry-in retro-fix)
                                    ├─▶ Bill, BillLine (models; lines frozen on ANY non-draft status
                                    │     incl. void, mirrors ExpenseLine::booted())
                                    └─▶ AuditLogger
                              extra guards vs. Expense: contact must be a vendor/both
                              (BillContactNotEligible), due_date is REQUIRED not defaulted
                              (BillDueDateRequired)

    BillController ─▶ BillPaymentService ─┬─▶ LedgerService  P7: Dr 2000 Accounts Payable (WITH
                        (recordPayment,    │                       contact_id) / Cr the paying bank/
                        voidPayment)       │                       cash account's linked ledger
                                          │                       account — two lines always, no FX
                                          │                       plug (bills are base-currency-only)
                                          ├─▶ BillPayment (model) + AuditLogger
                                          └─▶ BankAccount (resolves the paying ledger account)
                              overpayment guard: lockForUpdate() READ of the BILL row inside this
                              method's own transaction — exact AP-side mirror of
                              InvoicePaymentService's proven concurrency-safe pattern (Phase 3
                              Task 13); void() locks bill THEN payment (same order as record()) and
                              recomputes amount_paid/balance/status back toward
                              received/partially_paid, never voiding the bill itself

    ExpenseController/BillController ─▶ AttachmentService ─┬─▶ AttachmentContentSniffer (finfo
                        (storeAttachment,                   │     magic-byte sniff — NEVER the
                        downloadAttachment,                 │     client-declared mime or filename
                        destroyAttachment — spec §17.8)     │     extension; pdf/jpg/png/webp
                                                             │     allowlist shared with
                                                             │     App\Rules\AllowedAttachmentContent,
                                                             │     the FormRequest-layer friendly 422)
                                                             ├─▶ Attachment (model, morphs to
                                                             │     Expense|Bill) written to the
                                                             │     PRIVATE `local` disk, uuid-
                                                             │     randomized filename, under
                                                             │     attachments/{company_id}/
                                                             └─▶ AuditLogger (upload/delete/download,
                                                                   all three)
                              draft delete cascades attachments — rows now, disk files only once the
                              caller's transaction commits (DB::afterCommit; deleteAllFor() throws if
                              called outside a transaction, mirrors LedgerService::post's own guard)

    OpeningBalanceService (Phase 2 T8, extended Phase 3 T6 for invoices, Phase 4 T7 for bills) ─▶
                              createOpeningBill() ─▶ Bill (is_opening=true, status=Received) — the
                              exact AP-side mirror of createOpeningInvoice() (see above): NEVER calls
                              LedgerService itself, since the AP was already posted once by THIS
                              RUN's own P8c combined entry (keyed by contact_id, not by any bill id).
                              Payable via the completely unmodified BillPaymentService (P7) once
                              Received. Re-run voids prior is_opening bills the same status-only way
                              it voids prior is_opening invoices (no reversal — the AP was already
                              reversed once, at the RUN level).
                          ReconcileOpeningBills (artisan sparkbooks:reconcile-opening-bills) ─▶
                              backfills an is_opening Bill for any pre-Task-7 opening payable that
                              doesn't have one yet (idempotent; exact mirror of
                              ReconcileOpeningInvoices).

    ── Phase 2 reference-data CRUD (no ledger writes; feed the write-side above) ──

    ContactController ─▶ Contact (model) — customer/vendor/both; drill-down (Show.tsx) computes
                          outstanding balance from JournalLine WHERE contact_id (same read pattern as §4)
    ItemController ─▶ Item (model) — default_income_account_id (income-type, non-archived, tenant-owned)
                       + default_tax_rate_id (non-archived, tenant-owned), both nullable
    TaxRateController ─▶ TaxRate (model); Money::taxMinorUnits (bcmath half-up rounding, no float)
    BankAccountController ─▶ BankAccountService ─▶ auto-creates the linked 10xx Account (lockForUpdate
                                                     code-allocation on the 1000-1099 band, 3-retry on
                                                     UniqueConstraint, friendly band-exhaustion 422)
                              reads AccountBalances for each account's displayed balance

  READ side (reports — no writes):

    AccountController ─────────▶ AccountBalances ────────┐
    Reports/TrialBalanceController ─▶ TrialBalanceReport ┤──▶ JournalLine (+ journalEntry for date)   [SUM(debit)-SUM(credit)]
    VerifyLedgerIntegrity (cmd) ─▶ IntegrityChecker ─────┘         + Account (subtype), ManualJournal (orphan check)
    HealthController ─────────────▶ IntegrityChecker ─▶ IntegrityRun (model, persisted result)
                        IntegrityChecker is now source-type-aware (SOURCE_TABLES map): checks every
                        source_type actually present in journal_entries against its registered table,
                        fails loud on an unrecognized source_type, and never false-flags a reversal
                        (reversals share their source's source_type/source_id by design)
                        Phase 3 (Task 12) extended it with check (c): document_balance (document-currency,
                        invoice.balance == total − Σ non-void payments) + base_ar_reconciliation
                        (base-currency, scoped to account 1100 ONLY so FX gain/loss on 4950/5950
                        structurally can't perturb it — the mechanism the whole FX tolerance relies on).
                        Task 13 additionally taught it to attribute an is_opening invoice's AR by
                        contact_id (its P8b line lives in the ORIGINAL opening_balance_run entry, not a
                        source_type='invoice' entry of its own) — discovered live via the Task 13 browser
                        walkthrough as a false-positive finding on a freshly-paid opening invoice, fixed
                        same-task with a regression test (see §10).
                        Phase 4 (Task 10) extended check (c) to bills/bill_payments: document_balance
                        (bill.balance == total − Σ non-void payments, amount_paid tie-out, status-vs-
                        balance consistency) + ap_reconciliation (per-bill ledger tie-out for normal
                        bills, scoped to account 2000 ONLY, credit-normal sign convention — the AP-side
                        mirror of the AR check, minus any FX tolerance since bills are base-currency-
                        only in this phase). Opening bills are reconciled at the CONTACT level, same
                        fbbe763 lesson applied proactively this time (a vendor with several opening
                        bills in one run shares one ledger AP line keyed by contact_id, never bill_id).
                        POSTED_INVOICE_STATUSES/POSTED_BILL_STATUSES are now both derived from their
                        enum's own isPosted() rather than hand-duplicated (see §10 carried-cleanups note).

  ── Phase 5 report services (read-only; NO ledger writes) ──

    ProfitAndLossController ─▶ ProfitAndLossReport ────┐
    BalanceSheetController ─▶ BalanceSheetReport ──────┤
    ArAgingController/ApAgingController ─▶ AgingReport ┼──▶ LedgerBalances ──▶ JournalLine
    GeneralLedgerController ─▶ GeneralLedgerReport ─────┤      (+ journalEntry for date; same
    TaxSummaryController ─▶ TaxSummaryReport ───────────┘      inclusion rule as TrialBalanceReport)
                        LedgerBalances is the ONE shared per-account aggregation every report above
                        reads through (cumulativeAsOf = Trial-Balance-style as-of a date; movement =
                        P&L-style over a period) — they can never separately diverge on "what counts".
                        AgingReport and TaxSummaryReport ALSO write their own direct journal_lines
                        queries (grouped by journal_entries.source_id, mirroring IntegrityChecker's
                        actualLedgerBaseArByInvoice()/actualLedgerApByBill() shape) because
                        LedgerBalances is account-level only: AgingReport needs a per-contact +
                        per-source-document split (the due date each dollar buckets by);
                        TaxSummaryReport needs a per-source-type split (collected/input/
                        remittances). Both then join out to the source document (Invoice/Bill/
                        OpeningDocument, or ExpenseLine/BillLine) for CLASSIFICATION/bucket-date
                        only, never for the amount itself — the amount always ties back to
                        LedgerBalances by construction (see each class's own docblock for the full
                        tie-out proof).
                        BalanceSheetReport ─▶ ReportPeriod ─▶ reads Company.fiscal_year_start_month
                        (also used directly by ProfitAndLossController/GeneralLedgerController/
                        DashboardController for their own default-range fallback).
                        DashboardController ─▶ ProfitAndLossReport (fiscal-YTD snapshot + 6-month
                        chart buckets) + AgingReport::buildReceivables (base-correct AR totals for
                        the outstanding/overdue invoices widget — see DashboardController's own
                        class docblock's CURRENCY DISCIPLINE note for why Invoice.balance can't be
                        summed instead).

  SETUP / SUPPORT:

    OnboardingController ─▶ CompanySetupService ─▶ ChartOfAccountsSeeder ─▶ Account (seeds §5 chart,
                                     └─▶ AuditLogger                         now 25 rows incl. 4950/5950 FX)
    PeriodLockController ─▶ Company.books_locked_through (+ AuditLogger)   [enforcement lives in LedgerService]
    CompanySwitchController ─▶ User.current_company_id (+ AuditLogger)
    AppServiceProvider ─▶ registers permission Gates from config/permissions.php; binds CompanyContext + IdempotencyService (scoped)
    HandleInertiaRequests ─▶ shares auth.user, companies, currentCompany, can (as closures — NAV_ABILITIES
                              10-permission subset the sidebar/AppShell uses to hide nav items; every
                              linked route is still independently gated server-side) to every React page
    DashboardController ─▶ AccountBalances (cash position: non-archived bank/cash accounts' ledger
                            balances) + Contact/BankAccount counts (customers/vendors/bank accounts,
                            all tenant-scoped, read-only)
    BackfillFxAccounts (artisan sparkbooks:backfill-fx-accounts) ─▶ ChartOfAccountsSeeder::fxAccounts()
                              ─▶ Account — one-off idempotent migration helper for companies seeded
                                 before Task 4; run once against dev DB at Phase 2 exit (Task 12)
    ConcurrencyProbeInvoicePayment (artisan sparkbooks:concurrency-probe-invoice-payment) — TEST-SUPPORT
                              ONLY, not a product feature; refuses to run outside local/testing. Spawned
                              as a genuinely separate OS process by InvoicePaymentConcurrencyTest (Task 13)
                              to prove the overpayment guard under real cross-process row-lock contention
                              — see that test's class docblock for the full design.

  ── Phase 6 (FINAL) write-side + read-side services: reconciliation, close, exports, ops ──

    ReconciliationController ─▶ ReconciliationService ─┬─▶ IdempotencyService (complete/reopen only —
                          (start preview,               │     start() is a plain read, no key needed)
                          complete, reopen)              ├─▶ BankAccount (lockForUpdate on complete/reopen
                                                         │     — serializes two concurrent completions/
                                                         │     reopens against the same account)
                                                         ├─▶ JournalLine (READ only — bank ledger lines,
                                                         │     dated ≤ statement date, not already
                                                         │     reconciled by a LIVE reconciliation)
                                                         ├─▶ Reconciliation, ReconciliationLine (models;
                                                         │     NO LedgerService call anywhere in this
                                                         │     class — reconciliation only marks which
                                                         │     lines cleared, never posts)
                                                         └─▶ AuditLogger
                              complete(): REFUSES (ReconciliationOutOfBalance) unless statement_ending_
                              balance − (opening_reconciled_balance + Σ ticked lines) === 0 — nothing
                              stored on failure; ALSO refuses (ReconciliationOutOfOrder) a statement dated
                              on/before the account's latest completed reconciliation, since
                              opening_reconciled_balance is carried from that reconciliation's own ending
                              balance. reopen(): NEWEST-FIRST ONLY (ReconciliationNotNewest) — deletes the
                              target's reconciliation_lines rows (the unlock) and flips status to
                              'reopened'; ReconciliationAlreadyReopened guards a second reopen.
                              ReconciliationLineIneligible covers all three per-line ticked-line guards
                              (wrong account / dated after statement / already reconciled elsewhere).

    MonthEndCloseController ─▶ MonthEndCloseService (spec §17.5) — PURE READ, no new accounting logic,
                          (show, acknowledge,          computes 7 step statuses fresh every request:
                          runIntegrityCheck, lock,        1. reconcile   ─▶ Reconciliation (every active
                          complete)                            bank/cash account has a completed
                                                                reconciliation on/after the month end)
                                                        2-3. ar_ap_aging / trial_balance ─▶ MonthEndClose
                                                                (the ONLY two steps that are stored state
                                                                — a human "I reviewed this" acknowledge,
                                                                no system signal exists for that)
                                                        4. drafts      ─▶ Invoice/Bill/Expense (every
                                                                UNPOSTED draft dated ≤ the month end;
                                                                manual journals excluded — a draft manual
                                                                journal is structurally impossible, see
                                                                MonthEndCloseService::draftsStep's own
                                                                docblock)
                                                        5. integrity   ─▶ IntegrityRun (the SAME persisted
                                                                row HealthController::show reads — a run
                                                                from either screen updates both)
                                                        6. lock        ─▶ Company.books_locked_through
                                                                (the SAME column PeriodLockController
                                                                reads/writes — movable, not one-way)
                                                        7. export      ─▶ ActivityLog (best-effort: was an
                                                                accountant_package.exported row logged for
                                                                this exact month end — informational only,
                                                                never gates completion)
                              MonthEndCloseController::lock/runIntegrityCheck/complete each write through
                              the SAME existing mechanism a standalone screen would (Company.
                              books_locked_through, IntegrityChecker+IntegrityRun) rather than duplicating
                              it — this controller is a guided SCREEN over Phase 0-5 machinery.

    AuditLogController ─▶ ActivityLog (READ-ONLY — spec §7.13; adds NO new logging of its own) ─▶
                          AuditHumanizer::action/subject/module (shared with DashboardController's own
                          recent-activity widget — Phase 6 Task 2 extracted it FROM there, so the two
                          screens can never disagree on what an action code means). Deliberately never
                          serializes a row's before/after JSON to the client (that column can hold
                          another user's full field values).

    7 list controllers (BankAccount/Bill/Contact/Expense/Invoice/Item/ManualJournal)::exportCsv
                          (Phase 6 Task 3, spec §11/§17.8) ─▶ CsvExport::stream ─┬─▶ AuditLogger
                                                                                  (baked into the SAME
                                                                                  call that builds the
                                                                                  streamed response — an
                                                                                  export can't be
                                                                                  obtained without the
                                                                                  audit row also being
                                                                                  written)
                                                                                 └─▶ Money::minorToDecimalString
                              (each mirrors that Index page's own list scope; ManualJournal's is the
                              only one with a genuine status+date-range filter, since a manual journal
                              has no other natural single-value filter like invoice status)

    AccountantPackageController ─▶ AccountantPackageService (spec §11) ─┬─▶ GeneralLedgerReport,
                          (show, download)                              │   TrialBalanceReport,
                                                                          │   ProfitAndLossReport,
                                                                          │   BalanceSheetReport, AgingReport
                                                                          │   (×2, receivables+payables) —
                                                                          │   the SAME Phase 5 report
                                                                          │   services every standalone
                                                                          │   report/export already reads;
                                                                          │   this class performs NO
                                                                          │   accounting math of its own
                                                                          ├─▶ barryvdh/laravel-dompdf
                                                                          │   (renders resources/views/
                                                                          │   pdf/reports/* — mirrors
                                                                          │   InvoicePdf's own palette/
                                                                          │   money-formatting convention)
                                                                          ├─▶ ZipArchive (12-entry zip:
                                                                          │   6 reports × CSV+PDF; every
                                                                          │   failure path after tempnam()
                                                                          │   unlinks the temp file —
                                                                          │   "no temp-file leak on
                                                                          │   success OR exception")
                                                                          └─▶ AuditLogger
                              (`accountant_package.exported`, after the zip is built, before it streams)

    VerifyLedgerIntegrity (cmd) ─▶ [existing IntegrityChecker path, unchanged] ─┬─▶ Heartbeat::ping('integrity')
                          Phase 6 Task 7 extended handle() to alert/ping AROUND│   (on a fully clean run)
                          the existing check, never inside it:                └─▶ AlertNotifier::alert
                                                                                    (on ANY finding, OR on
                                                                                    the pre-existing
                                                                                    zero-companies vacuous-
                                                                                    check guard)

    BackupDatabase (artisan sparkbooks:backup, spec §11) ─┬─▶ Process (mysqldump --result-file, no shell
                          scheduled daily 02:00           │     redirection — portable Windows/Linux)
                                                            ├─▶ ZipArchive (database.sql + a recursive copy
                                                            │     of the private attachments disk +
                                                            │     manifest.json into one timestamped
                                                            │     artifact)
                                                            ├─▶ Storage::disk('backups') (off-host S3 copy
                                                            │     — config-gated on a bucket being set;
                                                            │     Storage::disk() is never even called
                                                            │     when it isn't, so an unconfigured backups
                                                            │     disk can never throw)
                                                            ├─▶ BackupRetentionPolicy::partition (pure fn —
                                                            │     14 daily + 8 weekly, calendar-anchored,
                                                            │     an undatable filename is never kept)
                                                            ├─▶ SecretScrubber::scrub (redacts
                                                            │     --password=/-p<secret> from every
                                                            │     Process failure message before it reaches
                                                            │     the console, logs, OR the alert body —
                                                            │     closes a real cleartext-DB-password leak
                                                            │     found in review, Task 7 follow-up)
                                                            ├─▶ Heartbeat::ping('backup') (on success)
                                                            └─▶ AlertNotifier::alert (on ANY failure branch
                                                                — directory creation, mysqldump, archive
                                                                build, or the off-host copy)

    RestoreDatabase (artisan sparkbooks:restore) ─▶ Process (mysql client, SQL piped via stdin) — REQUIRES
                          an explicit --database=<target>; NEVER guesses/defaults to the app's own
                          configured database; demands an interactive confirmation (or --force) if the
                          target happens to equal that configured database. Rehearsed once end-to-end
                          against the dev DB + a disposable scratch DB (docs/RESTORE.md, evidence
                          captured verbatim).

    QueueHeartbeat (artisan sparkbooks:queue-heartbeat, scheduled daily 04:00) ─▶ QueueHeartbeatJob
                          (dispatched onto the queue) ─▶ Heartbeat::ping('queue') — the ping fires ONLY
                          from inside the job's own handle(), i.e. only once a REAL queue:work process
                          pulls it off the queue; a dead/stalled worker leaves the job queued forever and
                          the ping never fires, which is the entire point (proves worker liveness, not
                          just "cron fired the command").

    AlertNotifier ─▶ Mail::to(config('monitoring.alert_email'))->send(MonitoringAlertMail) (synchronous,
                     deliberately NOT ShouldQueue — an alert about a broken system shouldn't wait behind a
                     possibly-also-broken queue worker) + Http::post(config('monitoring.slack_webhook'))
                     — either, both, or neither may be configured; neither configured still logs a
                     warning so a real failure stays visible in application logs.

  ── Phase 7 write-side + read-side services: sub-accounts, archiving, entry numbers, SCF,
     drill-downs, customer/vendor reports, backup/export ──

    AccountController ─▶ AccountService (Task 1, spec new) ─┬─▶ createChild(parent, attrs): flips the
                          (makeParent, createChild,          │     parent's own is_postable false the
                          moveSubtree)                       │     FIRST time it gains a child (never
                                                             │     inferred at read time), sets depth/
                                                             │     path from the parent — SystemAccount-
                                                             │     NotNestable blocks it on any is_system
                                                             │     account (3200/1100/2000/2100/3100/
                                                             │     4950/5950 can never become a parent or
                                                             │     child — the CRITICAL fix below)
                                                             ├─▶ makeParent(leaf): promotes a POSTED leaf
                                                             │     — moves its existing journal_lines to a
                                                             │     new "{name} — Other" child in the SAME
                                                             │     transaction, so every report's grand
                                                             │     total is unchanged (the one sanctioned
                                                             │     ledger-line account_id move in the whole
                                                             │     app; audited `account.promoted`)
                                                             ├─▶ moveSubtree(account, newParent): cascades
                                                             │     path/depth to every descendant in one
                                                             │     transaction (service-only — no UI yet,
                                                             │     see §10)
                                                             └─▶ AuditLogger
                              CRITICAL FIX (Task 1 review): promoting an is_system account (e.g. 3200
                              Retained Earnings) broke Balance Sheet — RE is read straight off 3200's own
                              balance by account CODE, and a promoted 3200 stops posting, silently zeroing
                              RE. Now structurally impossible (SystemAccountNotNestable, enforced in
                              createChild/makeParent/moveSubtree AND Account's own creating/updating model
                              guards, belt-and-suspenders); regression test proves BS can't be driven out
                              of balance this way.
                              LedgerService::validate now throws NonPostableAccount (app/Exceptions/
                              Ledger/) if any line targets a non-leaf account — the second of three
                              enforcement layers (IntegrityChecker's own non_postable_posting rule is the
                              third, a nightly backstop).
                              TrialBalanceReport/BalanceSheetReport/ProfitAndLossReport/GeneralLedgerReport
                              all read through AccountRollup (Phase 7 Task 1/5, shared with the drill-down
                              reports below): depth-first orders accounts (a parent immediately followed by
                              its descendants) and rolls up each parent's row as a pure SUM of its leaf
                              descendants — a company with zero sub-accounts degenerates back to the exact
                              same flat code-ordered list, byte-identical to pre-Phase-7 (tested).

    AccountController/ContactController/ItemController/BankAccountController ─▶ archive/unarchive
                              (Task 2 — closed pre-existing gaps, made consistent): archived rows are
                              HIDDEN from every new-document picker (`formOptions` scopes `->active()`;
                              CategoryAccountGuard/ValidatesDocumentLines re-check server-side, dual-layer
                              422) but stay fully visible in history + every report (reports never filter
                              `is_archived` — structural, not incidental). Archiving writes NO ledger entry
                              and changes no balance (TB byte-identical before/after archiving anything).
                              NEW: `SystemAccountCannotBeArchived` (Account's own `updating` guard,
                              unbypassable) — closed a real latent bug where archiving 1100 (AR) would have
                              bricked invoicing.

    LedgerService::post ─▶ DocumentNumberService::nextNumber('JE') (Task 3 — visible entry numbers):
                              the SAME row-locked counter machinery invoices/bills already use, just a new
                              series. `journal_entries.entry_number` (unique per company) + `JournalEntry::
                              displayNumber()` → "JE-000142"; a reversal gets its OWN number (linked via
                              `reversesEntry()`), never reuses the original's. `EntryNumberBackfiller`
                              (invoked once, from the migration) numbers every legacy row in
                              entry_date-then-id order, above each company's existing max — idempotent
                              (only touches `entry_number IS NULL` rows), so a re-run after new out-of-
                              order backdated rows land can never collide on the unique index. Purely
                              additive: not part of `EntryDraft`, so no posting/balance logic changed.

    Reports/CashFlowController ─▶ CashFlowReport (Task 4, spec's Statement of Cash Flows) ─▶
                          (show, exportCsv)         LedgerBalances ─▶ JournalLine
                              Runs TWO independently-coded engines that must AGREE (the strongest
                              cross-check in the whole reports layer):
                                Engine B (indirect): Net Income + non-cash add-backs (the depreciation
                                  EXPENSE account's own movement — NEVER accumulated depreciation's, which
                                  would double-count) + working-capital deltas (AR/AP/other operating
                                  accounts' `cf_class`).
                                Engine A (direct): classifies each CASH line by the OTHER leg(s)'
                                  `cf_class` (operating/investing/financing/excluded) — a bank-to-bank
                                  Transfer's two legs cancel to exactly zero, never showing as investing/
                                  financing.
                              `accounts.cf_class` (Task 4's own additive column, nullable, backfilled by
                              code/subtype — see the migration's own docblock) drives Engine A's per-leg
                              classification; 3200/4950/5950 are special-cased by CODE (never cf_class
                              alone) since NI already carries them. FX realized gain/loss gets its OWN
                              "Effect of FX on cash" line, never buried inside Operating. Opening-balance
                              entries (dated ≤ from−1) are excluded from every section but DO count toward
                              opening cash. See §2's tie-out paragraph for the six exact assertions.

    Reports/TransactionDetailController ─▶ TransactionDetailReport (Task 5) ┬─▶ LedgerBalances
                          Reports/AccountQuickReportController ─▶ AccountQuickReport ┘   (movement/
                                                                                    cumulativeAsOf)
                              TransactionDetailReport: every posted line in a period, grouped by account,
                              each account's subtotal TIES to `LedgerBalances::movement` (the gate every
                              other Phase 5 report already trusts) — entry#/source/contact/memo columns,
                              running balance. No per-account filter by design (that's QuickReport's job).
                              AccountQuickReport: ONE account's running balance from
                              `cumulativeAsOf(from−1)` through `cumulativeAsOf(to)` — a LEAF account shows
                              only its own lines; a PARENT (header) account rolls up every descendant
                              leaf's lines interleaved by date (via AccountRollup). P&L/Balance Sheet/
                              Trial Balance figures now link `?account_id=&from=&to=` into
                              `reports/account-quickreport` — a real drill-down, round-trip browser-
                              verified.

    Reports/CustomerBalanceController/VendorBalanceController ─┬─▶ ContactLedgerReport (Task 6 — 9
    Reports/OpenInvoicesController/UnpaidBillsController        │     methods total) ─▶ LedgerBalances +
    Reports/CollectionsController                               │     direct journal_lines queries
    Reports/CustomerStatementController/VendorStatementController ┘     grouped by contact_id + source
                              document (same shape as AgingReport/IntegrityChecker's own per-contact
                              reconstruction) — every total ties to 1100 (AR) / 2000 (AP).
                              customerBalanceSummary/vendorBalanceSummary: aggregate outstanding per
                              contact. customerBalanceDetail/vendorBalanceDetail: one contact's own
                              open items. openInvoices/unpaidBills: unpaid+partially-paid documents only,
                              outstanding shown base-correct (an FX invoice contributes its BASE remainder,
                              never the doc-currency balance). collections: overdue AR by customer with
                              days-overdue + contact phone/email (mirrors AgingReport's bucketing, contact-
                              facing framing). customerStatement/vendorStatement: opening balance → period
                              activity → closing balance, and closing MUST equal the contact's own control-
                              account balance (tested) — printable + a PDF via `StatementPdf` (mirrors
                              `InvoicePdf`'s own palette/money convention). A voided document, an
                              overpayment, and boundary dates were reviewer-probed directly against these
                              tie-outs (not just happy-path).

    BackupExportController ─▶ FullDataExportService (Task 7, spec §11) ─▶ ZipArchive (9 CSVs — accounts,
                          (show, downloadAccountantPackage,      contacts, items, journal_entries,
                          downloadFullExport)                    journal_lines, invoices, invoice_payments,
                                                                  bills, bill_payments — each scoped by the
                                                                  SAME BelongsToCompany trait every model
                                                                  already uses, proven company-B-absent)
                              downloadAccountantPackage reuses the EXISTING Phase 6 AccountantPackageService
                              (no duplicated report logic) — this screen is a process-UI over two already-
                              proven export paths, not new accounting math. Both downloads audit-logged
                              (`accountant_package.exported` / `full_data_export.exported`). Backup-status
                              widget is a read-only filesystem scan (graceful fallback if the backups dir
                              is unreadable). Gated `exports.run` like every other export route.
```

---

## 6. The one gate: LedgerService (app/Services/LedgerService.php)

**The only code in the entire app that writes `journal_entries` / `journal_lines`.** Enforced by
review + a whole-branch grep. Every iron rule from spec §4 lives here:

- `post(EntryDraft)` — must run inside a caller-owned DB transaction (throws if `transactionLevel()===0`).
  Validates BEFORE writing anything; a rejected draft persists nothing.
- Balance invariant: `SUM(debit) === SUM(credit)` or `UnbalancedEntry`.
- Each line: exactly one of debit/credit > 0, both ≥ 0 (also a DB CHECK `chk_one_sided`).
- Control accounts (subtype receivable/payable): every line needs `contact_id`; a `manual_journal`
  source may never touch them (`ControlAccountViolation`).
- Period lock: `entry_date <= Company.books_locked_through` → `LockedPeriod`.
- Archived account on a NEW (primary) entry → `ArchivedAccount`; **reversals are exempt** (history
  must always be correctable).
- `reverse(entry, date, memo)` — posts a mirror entry (debit↔credit swapped), `entry_role=reversal`,
  `reverses_entry_id` set. Second reverse of the same source is rejected by the unique index.
- Models `JournalEntry`/`JournalLine` throw `ImmutableLedger` on any update/delete. Corrections are
  reversals, never edits. (Known gap: raw bulk query-builder ops bypass model events — spec-accepted,
  Phase 6 DB-trigger candidate.)

DTOs: `app/Domain/Ledger/EntryDraft.php`, `LineDraft.php` (readonly). Enum: `EntryRole`.
Exceptions: `app/Exceptions/Ledger/*` (all extend `LedgerException`).

---

## 7. Money & currency rules (invariants)

- **All money is BIGINT minor units** (paisa/cents). No float anywhere on a money path — CI greps
  `app/` for `(float)`/`(double)` casts on money and fails the build. Parsing user input:
  `resources/js/lib/money.ts::parseMoneyInput` uses string/integer math only (never parseFloat).
- **Backend money helper (Phase 2, `app/Support/Money.php`):** `Money::parseToMinor` mirrors the TS
  parser (string/integer math, no float) for server-side CSV/form input; `Money::taxMinorUnits`
  computes tax on a minor-unit amount using bcmath half-up rounding (verified against hand-computed
  tie/neighbor cases). **Closed in Phase 3 (Task 2):** `taxMinorUnits` now throws loud on a
  negative/5-decimal input instead of silently truncating, ahead of invoicing (its first real
  caller) needing that guard.
- **Display always forces 2 decimals** (`money.ts::formatMoney` pins `min/maxFractionDigits: 2`), so
  a value renders identically in every browser regardless of locale/ICU. Money is always shown via
  `Components/ui/MoneyText.tsx` (Spline Sans Mono, tabular-nums, right-aligned).
- **Currency — §17.1 decision recorded; FX posting now LIVE (Phase 3):** base currency per company
  (PKR for SparkingAsia), plus a USD option. The **FX Gain (4950) / FX Loss (5950) accounts** are
  seeded for new companies by `ChartOfAccountsSeeder` and were backfilled onto pre-existing dev
  companies at Phase 2 exit. Phase 2's transfers/other-receipts/opening-balances stayed base-currency
  only (no FX ever arose there); **Phase 3's invoicing is what actually exercises the FX-lite posting
  rules (spec §17.1 Path 2)**, built and verified end-to-end:
  - **P1fx (foreign-currency send)** — `app/Support/FxConverter.php` is the single source of truth
    for resolving/validating a send-time `fx_rate` and converting to base; each line (and its tax)
    converts to base and rounds half-up BEFORE summing, so `base_total` always equals the sum of
    converted components and the entry balances by construction (never `round(total × rate)`).
  - **P2fx (foreign-currency payment)** — `InvoicePaymentService` posts Dr bank = the actual base
    amount the user enters off the bank statement (never rate-derived); Cr AR = relief at the
    *invoice's own* fx_rate (via `FxConverter::toBase`); the difference plugs to 4950/5950. The
    payment that brings the document balance to exactly 0 relieves the entire *remaining* base AR
    (settlement rounding) rather than `rate × amount`, so a fully paid invoice's base AR always
    zeroes out exactly — verified live in the browser (USD 1,000 + 18% tax @ 280 → Rs 330,400.00;
    paid with Rs 335,000.00 actually received → Rs 4,600.00 posted to 4950 FX Gain, Trial Balance
    still balanced).
  - Tax is fixed as a base-currency liability at send time and never re-measured at payment — 2100
    Tax Payable is untouched by any FX plug.
  - The integrity checker's check (c) tolerates FX structurally: it sums account 1100 ONLY, and
    4950/5950 postings can never land there, so an FX gain/loss line is invisible to the base-AR
    tie-out no matter which invoice it came from.
  - Still excluded (launch track, per spec §17.1): foreign-currency bank accounts, unrealized
    period-end revaluation, more than one foreign currency at a time.

---

## 8. Annotated file tree (hand-written source only — vendor/framework omitted)

### Backend — `app/`

```
Console/Commands/
  VerifyLedgerIntegrity.php   cron `ledger:verify` (daily 3am, routes/console.php). Iterates ALL
                              companies, runs IntegrityChecker, persists IntegrityRun, FAILS LOUD
                              (non-zero exit) on findings OR a vacuous/empty check.
  BackfillFxAccounts.php      Phase 2 Task 4: `sparkbooks:backfill-fx-accounts` — adds 4950/5950 to
                              companies seeded before FX accounts existed. Idempotent, not scheduled;
                              run by hand once against dev DB at Phase 2 exit (Task 12).
  ReconcileOpeningInvoices.php  Phase 3 Task 6: `sparkbooks:reconcile-opening-invoices` — backfills an
                              is_opening Invoice for any pre-Task-6 opening receivable that doesn't
                              have one yet. Idempotent, not scheduled; run by hand at Phase 3 exit
                              (Task 13; found 0 to do on the dev DB).
  ConcurrencyProbeInvoicePayment.php  Phase 3 Task 13, TEST-SUPPORT ONLY (refuses outside local/
                              testing) — real cross-process row-lock contention probe for
                              InvoicePaymentConcurrencyTest. Not a product feature.
  ReconcileOpeningBills.php   Phase 4 Task 7: `sparkbooks:reconcile-opening-bills` — the exact
                              AP-side mirror of ReconcileOpeningInvoices; backfills an is_opening
                              Bill for any pre-Task-7 opening payable that doesn't have one yet.
                              Idempotent, not scheduled; NEVER posts a second ledger entry (the AP
                              was already posted by the original opening_balance_run).
  BackupDatabase.php          Phase 6 Task 6/7 (spec §11): `sparkbooks:backup` — dumps the DB
                              (mysqldump --result-file) + a recursive copy of the private
                              attachments disk into one timestamped zip, copies it off-host when
                              the `backups` S3 disk is configured, prunes via
                              BackupRetentionPolicy (14 daily + 8 weekly). Scheduled daily 02:00.
                              Pings the `backup` heartbeat on success / alerts on any failure
                              branch (Task 7); every Process failure message is scrubbed via
                              SecretScrubber before it reaches logs/console/alert body.
  RestoreDatabase.php          Phase 6 Task 6 (spec §11): `sparkbooks:restore <file>
                              --database=<target>` — NEVER guesses a target (refuses without
                              --database=); demands interactive confirmation (or --force) if the
                              target equals the app's own configured database. Accepts a
                              sparkbooks:backup .zip (extracts database.sql to a cleaned-up temp
                              dir) or a raw .sql dump. Rehearsed once end-to-end — see
                              docs/RESTORE.md.
  QueueHeartbeat.php            Phase 6 Task 7 (spec §17.9): `sparkbooks:queue-heartbeat` —
                              dispatches QueueHeartbeatJob onto the queue; scheduled daily 04:00.
                              The command's own execution proves nothing about a worker being
                              alive (cron fires regardless) — only the JOB's handle() actually
                              pings, and only once a real queue:work process pulls it off.
Domain/Ledger/
  EntryDraft.php              readonly DTO: date, memo, source, lines[], role, reversesEntryId.
  LineDraft.php               readonly DTO: accountId, debit, credit, memo, contactId.
Enums/
  Role.php                    Owner|Admin|Accountant|Staff (+ permissions() from config).
  AccountType.php             Asset|Liability|Equity|Income|Expense (+ isDebitNormal()).
  AccountSubtype.php          cash/bank/receivable/payable/tax/… (+ type() → AccountType).
  EntryRole.php               primary|reversal.
  ContactType.php             Phase 2: customer|vendor|both — drives the Contacts type filter and
                              opening-balance customer_lines/vendor_lines eligibility.
  InvoiceStatus.php            Phase 3: draft|sent|partially_paid|paid|void (+ isPosted(), canEdit(),
                              canReceivePayment(), canVoid()) — the invoice lifecycle state machine.
  ExpenseStatus.php             Phase 4 T1: draft|recorded|void (+ isPosted(), canEdit(),
                              canRecord(), canVoid()) — simpler than Bill/Invoice: no partial-paid
                              state, an expense is a single cash-out event.
  BillStatus.php                 Phase 4 T1: draft|received|partially_paid|paid|void (+ isPosted(),
                              canEdit(), canReceive(), canReceivePayment(), canVoid()) — the exact
                              AP-side mirror of InvoiceStatus.
  CashFlowClass.php              Phase 7 T4: operating|investing|financing|cash|excluded — drives
                              CashFlowReport's Engine A per-leg classification. Backing column
                              (`accounts.cf_class`) is nullable; NULL means "unclassified", surfaced
                              loudly by the report's own diagnostics rather than guessed.
Exceptions/
  MissingCompanyContext.php   thrown when a tenant query runs with no company set (fail-closed).
  Ledger/LedgerException.php  base for all ledger errors; + UnbalancedEntry, EmptyEntry,
                              LockedPeriod, ControlAccountViolation, ArchivedAccount, ImmutableLedger.
  IneligibleCategoryAccount.php  Two independent rules share this one exception (Phase 4 T2 doc
                              update): Phase 2 Task 7's OtherReceiptService rejects bank/cash-linked,
                              control (AR/AP), or archived accounts as a money-IN "category"; Phase 4
                              Task 2's CategoryAccountGuard (money-OUT — expense/bill lines) rejects
                              the same three PLUS an income-type account (only expense/asset/
                              liability/equity are eligible categories for money going out).
  OpeningBalancesLocked.php   Phase 2 Task 8: blocks a second opening-balance run once any OTHER
                              (non-'opening_balance_run') JournalEntry exists for the company.
  AttachmentRejected.php        Phase 4 Task 8 (spec §17.8): thrown by AttachmentService::store on
                              an oversize or content-sniffed-ineligible upload — the caller-
                              independent backstop behind App\Rules\AllowedAttachmentContent's
                              friendly 422. Mirrors IneligibleCategoryAccount's shape.
  Invoicing/*.php              Phase 3: the invoice/payment guard hierarchy — InvoiceSendException
                              (InvoiceNotDraft, InvoiceHasNoLines, InvoiceHasNoValue,
                              InvoiceContactNotEligible, InvoiceFxRateInvalid, InvoiceFxRateRequired),
                              PaymentException (InvoiceNotPayable, PaymentExceedsBalance,
                              InvalidPaymentAmount, PaymentBankAccountIneligible, PaymentAlreadyVoided),
                              InvoiceVoidException (InvoiceNotVoidable, InvoiceHasLivePayments,
                              InvoiceIsOpening — Phase 4 T6 retro-fix: an is_opening invoice carries
                              no P1 entry of its own to reverse, so voiding it directly now surfaces
                              this friendly 422 instead of a confusing 404),
                              PaymentVoidException, InvoiceNotEmailable, InvoiceEmailException —
                              checked BEFORE LedgerService on every invoice/payment write path.
  Documents/*.php               Phase 4 T3-T6: the expense/bill guard hierarchy, shared by BOTH
                              document types where the rule is identical (mirrors Invoicing/*.php's
                              own shape) — DocumentPostException (DocumentNotDraft, DocumentHasNoLines,
                              DocumentHasNoValue, ExpenseBankAccountRequired, BillContactNotEligible,
                              BillDueDateRequired), DocumentVoidException (DocumentNotVoidable,
                              BillHasLivePayments, BillIsOpening), BillPaymentException (BillNotPayable,
                              InvalidBillPaymentAmount, BillPaymentExceedsBalance,
                              BillPaymentBankAccountIneligible), BillPaymentVoidException
                              (BillPaymentAlreadyVoided) — all deliberately NOT LedgerException (these
                              are business rules checked BEFORE LedgerService, not ledger posting-time
                              violations); caught as one type per family by ExpenseController/
                              BillController to surface a friendly 422.
  Reconciliation/*.php          Phase 6 Task 1 (spec §7.9): the reconciliation guard hierarchy,
                              base `ReconciliationException` deliberately NOT a LedgerException
                              (this feature never calls LedgerService at all) —
                              ReconciliationOutOfBalance (difference != 0 at complete()),
                              ReconciliationLineIneligible (a ticked line fails one of its three
                              eligibility checks), ReconciliationOutOfOrder (a statement dated
                              on/before the account's latest completed reconciliation),
                              ReconciliationNotNewest (reopening anything but the account's
                              newest completed reconciliation), ReconciliationAlreadyReopened (a
                              second reopen of the same reconciliation) — caught as one type by
                              ReconciliationController to surface a friendly 422.
  Accounts/*.php                 Phase 7 T1 (sub-accounts): AccountException base +
                              CrossTypeSubAccount (a child must share its parent's AccountType),
                              InvalidSubtreeMove (moveSubtree rejects a cycle/moving under one's own
                              descendant), MaxDepthExceeded (a sane nesting-depth cap),
                              SystemAccountNotNestable (the Task 1 CRITICAL-fix guard — no is_system
                              account, e.g. 3200/1100/2000/2100/3100/4950/5950, can ever become a
                              parent OR a child — see §5's AccountService entry for the full story).
  Ledger/NonPostableAccount.php   Phase 7 T1: LedgerService::validate throws this when any posted
                              line targets a non-leaf (is_postable=false) account — enforcement layer
                              2 of 3 (createChild/makeParent already prevent reaching this in the
                              normal UI path; IntegrityChecker's non_postable_posting rule is layer 3,
                              a nightly backstop for anything that slipped through, e.g. a raw
                              query-builder write).
Http/Controllers/
  DashboardController.php       /dashboard — cash position + customer/vendor/bank-account counts
                                (Phase 2, unchanged) PLUS Phase 5 T7 (spec §7.12): outstanding &
                                overdue invoices (money from AgingReport::buildReceivables, base-
                                correct AR — NEVER summed from Invoice.balance, which is doc-
                                currency on an FX invoice), unpaid bills (Bill.balance summed
                                directly — bills are base-currency-only), a current-fiscal-year-
                                to-date P&L snapshot + a 6-month income-vs-expense chart (both thin
                                reads through ProfitAndLossReport), and a recent-activity feed
                                gated on audit.view (empty list, never 403, for anyone else). Every
                                widget reads an EXISTING report service or a document model — no
                                new ledger aggregation of its own; performs NO ledger writes. Phase
                                6 Task 2: its own private humanizeAction/humanizeSubject helpers were
                                EXTRACTED into the shared App\Support\AuditHumanizer so the new Audit
                                log viewer reuses the exact same logic (this controller now just
                                calls AuditHumanizer::action/subject) — no behavior change.
  OnboardingController.php     create company → CompanySetupService.
  CompanySwitchController.php  switch current company (audit-logged, tenant-checked → 404).
  AccountController.php         CoA index/show(drill-down)/store/update/archive/unarchive. Reads
                                AccountBalances. Phase 7 T1/T2: gained a tree-shaped index (parent/
                                child rows via AccountRollup), createChild/makeParent/moveSubtree
                                actions → AccountService, and archive/unarchive now refuse an
                                is_system account (SystemAccountCannotBeArchived).
  ManualJournalController.php   journal list / new / store / reverse. → ManualJournalService.
                                Phase 6 Task 3: gained exportCsv (list-screen CSV, spec §11/§17.8) —
                                the one list export with a genuine status+entry_date-range filter
                                (a manual journal has no other natural single-value filter);
                                `journal_entries.exported` audit action via CsvExport.
  PeriodLockController.php      /settings/books view + set lock date.
  Reports/TrialBalanceController.php  trial balance view + CSV export (integer→decimal string);
                                Phase 5 T1 extracted the money-formatting helper into
                                Money::minorToDecimalString so every later report CSV reuses it.
  Reports/ProfitAndLossController.php  Phase 5 T2: show + exportCsv (Section/Code/Name + one CSV
                                column per month bucket + Total); defaults to the current fiscal
                                year to date (ReportPeriod) when no from/to given.
  Reports/BalanceSheetController.php  Phase 5 T3: show + exportCsv (as-of date picker); gated
                                reports.view / exports.run like every other report here.
  Reports/ArAgingController.php  Phase 5 T4: show + exportCsv — A/R side of the one AgingReport
                                engine (direction='ar').
  Reports/ApAgingController.php  Phase 5 T4: show + exportCsv — A/P side (direction='ap'), same
                                shape as ArAgingController, control account 2000 instead of 1100.
  Reports/GeneralLedgerController.php  Phase 5 T5: show + exportCsv; accepts the drill-down query
                                params other reports link in with (?account=&from=&to=&contact=);
                                the CSV mirrors the screen — drops the Balance/Opening/Closing
                                columns under a contact filter (see GeneralLedgerReport's own
                                docblock for why those figures aren't meaningful per-contact).
  Reports/TaxSummaryController.php  Phase 5 T6: show + exportCsv (period picker; 2100 Tax Payable
                                split collected/input/remittances/adjustments).
  Reports/CashFlowController.php  Phase 7 T4: show + exportCsv (period picker; the six-assertion
                                Statement of Cash Flows — see §5's CashFlowReport entry); included
                                in the Accountant Package (Phase 6 service, no change needed there).
  Reports/TransactionDetailController.php  Phase 7 T5: show + exportCsv — all posted lines in a
                                period grouped by account, no per-account filter by design.
  Reports/AccountQuickReportController.php  Phase 7 T5: show + exportCsv — one account's running
                                balance; the drill-down TARGET every other report links `?account_id=
                                &from=&to=` into (mirrors GeneralLedgerController's own drill-down-
                                target role from Phase 5).
  Reports/CustomerBalanceController.php  Phase 7 T6: summary (all customers) + detail (one contact,
                                via ?contact_id=) + exportCsv ×2 — ties to 1100.
  Reports/VendorBalanceController.php  Phase 7 T6: summary + detail + exportCsv ×2 — the AP mirror
                                of CustomerBalanceController, ties to 2000.
  Reports/OpenInvoicesController.php  Phase 7 T6: show + exportCsv — unpaid/partially-paid invoices
                                only, outstanding shown base-correct.
  Reports/UnpaidBillsController.php  Phase 7 T6: show + exportCsv — the AP mirror of
                                OpenInvoicesController.
  Reports/CollectionsController.php  Phase 7 T6: show + exportCsv — overdue AR by customer,
                                days-overdue + contact phone/email.
  Reports/CustomerStatementController.php  Phase 7 T6: show + pdf (?contact_id=&from=&to=) —
                                opening→activity→closing, closing ties to the contact's own 1100
                                sub-balance; PDF via StatementPdf.
  Reports/VendorStatementController.php  Phase 7 T6: show + pdf — the AP mirror, ties to 2000.
  HealthController.php          /settings/health — latest IntegrityRun + "Run now".
  ProfileController.php, Auth/* Breeze auth + profile (Phase 0, restyled Phase 1).
  ContactController.php         Phase 2 T1: contacts index (type filter) /store/update/archive/
                                unarchive/show(drill-down: outstanding balance from JournalLine).
                                Phase 6 Task 3: gained exportCsv (list-screen CSV, spec §11/§17.8),
                                registered BEFORE /contacts/{contact} (wildcard-ordering hazard,
                                same reasoning as invoices.export) — `contacts.exported` audit
                                action via CsvExport.
  TaxRateController.php         Phase 2 T3: tax rates CRUD. tax_rates.manage gate (pre-existed).
  ItemController.php            Phase 2 T2: items catalog CRUD + archive/unarchive. items.manage gate.
                                Phase 6 Task 3: gained exportCsv (list-screen CSV, spec §11/§17.8) —
                                no wildcard-ordering hazard (items has no dedicated show page);
                                `items.exported` audit action via CsvExport.
  BankAccountController.php     Phase 2 T5: bank/cash account index/store/update/archive/unarchive.
                                → BankAccountService for linked-CoA-account creation. Phase 6 Task 3:
                                gained exportCsv (list-screen CSV, spec §11/§17.8) — the closest
                                existing "Banking" list screen (no separate bank-transactions index
                                exists yet); `bank_accounts.exported` audit action via CsvExport.
  TransferController.php        Phase 2 T6: /transfers/new + store. → TransferService (P11).
  OtherReceiptController.php    Phase 2 T7: /other-receipts/new + store. → OtherReceiptService (P14).
  OpeningBalanceController.php  Phase 2 T8: /setup/opening-balances create/store (P8a-c, re-run).
                                → OpeningBalanceService. settings.manage gate (Owner+Admin only —
                                Accountant explicitly excluded here, unlike most Phase-2 gates).
  OpeningCsvController.php       Phase 2 T9: CSV template download (§17.7) + import (stage-only,
                                → OpeningCsvParser, never posts).
  InvoiceController.php          Phase 3: index/new/store/show/edit/update/destroy (draft only) +
                                send/void/recordPayment/voidPayment/pdf/email/resendEmail. Every
                                mutating action gated per its own `invoices.*` permission (draft vs.
                                send vs. void are independently gated — §7.14). Phase 6 Task 3: gained
                                exportCsv (list-screen CSV, spec §11/§17.8), registered BEFORE
                                /invoices/{invoice} (wildcard-ordering hazard) and additionally gated
                                `exports.run` (Staff holds invoices.draft but not exports.run) —
                                `invoices.exported` audit action via CsvExport.
  ExpenseController.php          Phase 4 T2/T3/T6/T8/T9: index/new/store/show/edit/update/destroy
                                (draft only) + record/void + storeAttachment/downloadAttachment/
                                destroyAttachment (spec §17.8). Show (T9) is the canonical landing
                                page for every status; Edit stays the draft-only editor. No type
                                restriction on the payee contact (spec §7.8, unlike Bill). Phase 6
                                Task 3: gained exportCsv (list-screen CSV, spec §11/§17.8) —
                                `expenses.exported` audit action via CsvExport.
  BillController.php             Phase 4 T2/T4/T5/T6/T8/T9: index/new/store/show/edit/update/destroy
                                (draft only) + receive/recordPayment/void/voidPayment +
                                storeAttachment/downloadAttachment/destroyAttachment. contact_id is
                                REQUIRED and must be a vendor/both (spec §7.8); `reference` carries
                                the vendor's own bill number (no internal numbering). Show (T9) also
                                renders the payments list + the opening-bill banner (relocated here
                                from Edit). Phase 6 Task 3: gained exportCsv (list-screen CSV, spec
                                §11/§17.8) — `bills.exported` audit action via CsvExport.
  ReconciliationController.php    Phase 6 Task 1 (spec §7.9): index (pick-a-bank-account landing) /
                                show (history + a READ-only start() preview when a statement date +
                                ending balance are given as query params) / complete / reopen. $bankAccount
                                and $reconciliation are plain route params (SetCompanyContext ordering,
                                §9 rule 7). ReconciliationException family caught to a friendly 422 on
                                a generic `status` field (mirrors InvoiceController::recordPayment).
  MonthEndCloseController.php     Phase 6 Task 5 (spec §17.5): show (renders MonthEndCloseService's 7
                                step statuses) / acknowledge (the 2 manual-review steps) /
                                runIntegrityCheck (same IntegrityRun persistence HealthController::run
                                uses) / lock (same books_locked_through mechanism PeriodLockController
                                uses, pinned to this checklist's month end) / complete (requires the
                                period actually locked through the month end first). Gated entirely by
                                `periods.lock` — no read-only viewer split, unlike PeriodLockController.
  AuditLogController.php          Phase 6 Task 2 (spec §7.13): index — a READ-ONLY, paginated
                                (50/page), filterable (user/module/date-range) view over
                                `activity_log`. Adds NO new logging of its own. Humanizes via the
                                shared AuditHumanizer; NEVER serializes a row's raw before/after JSON
                                to the client. Gated `can:audit.view` (Owner/Accountant), same
                                middleware pattern as HealthController.
  Reports/AccountantPackageController.php   Phase 6 Task 4 (spec §11): show (period picker screen) /
                                download (builds the zip via AccountantPackageService, audit-logs
                                `accountant_package.exported`, streams via response()->download()
                                ->deleteFileAfterSend(true); a try/catch around the audit call
                                unlinks the temp file on a pre-stream failure too). Sits in the
                                `reports.view` group; download additionally gated `exports.run`,
                                same double-gate shape as every other report's own CSV export route.
  BackupExportController.php     Phase 7 T7 (spec §11): /settings/backup-export — show (renders
                                backup-status via a read-only filesystem scan, graceful fallback if
                                unreadable) / downloadAccountantPackage (thin re-use of the Phase 6
                                AccountantPackageService — no duplicated report logic) /
                                downloadFullExport → FullDataExportService. Gated `exports.run`
                                (Staff 403 on all three routes, same as every other export surface).
Http/Middleware/
  SetCompanyContext.php        resolves current company → CompanyContext; repairs stale/foreign id.
  RequireCompany.php           redirects users with no company to onboarding.
  HandleInertiaRequests.php    shares auth.user, companies, currentCompany, can (CLOSURES) to React.
                                `can` is a minimal ability map (config/permissions.php subset, incl.
                                NAV_ABILITIES — Phase 2 T10, hand-maintained, extend when a new nav
                                link needs gating) the sidebar nav uses to decide what to render —
                                every linked route still has its own server-side gate. Phase 3 extended
                                NAV_ABILITIES for the Invoices nav link; Phase 4 extended it again for
                                expenses.draft/bills.draft (nav links), expenses.record/bills.receive
                                and expenses.void/bills.void (the Expenses/Bills Edit and Show pages'
                                action-bar gating). Phase 6 extended it twice more:
                                `reconciliation.manage` (the Reconciliation nav link + Show's Reopen
                                action) and `exports.run` (every list screen's Export CSV button).
Http/Requests/
  StoreCompanyRequest, StoreAccountRequest, UpdateAccountRequest, StoreManualJournalRequest,
  ProfileUpdateRequest, Auth/LoginRequest   — form validation.
  StoreContactRequest, UpdateContactRequest, StoreTaxRateRequest, UpdateTaxRateRequest,
  StoreItemRequest, UpdateItemRequest, StoreBankAccountRequest, UpdateBankAccountRequest,
  StoreTransferRequest, StoreOtherReceiptRequest, StoreOpeningBalanceRequest   — Phase 2 form validation.
  StoreInvoiceRequest, UpdateInvoiceRequest   — Phase 3: draft shape validation (lines array,
                                currency_code, per-line qty/unit_price/income_account/tax_rate).
  StorePaymentRequest            Phase 3: amount ≥ 1 (+ base_amount ≥ 1 required for a foreign-
                                currency invoice), bank_account_id, payment_date.
  Store/UpdateExpenseRequest, Store/UpdateBillRequest   — Phase 4 T2: draft shape validation
                                (lines array, per-line description/category_account_id/tax_rate_id/
                                amount — amount-based, no qty×price). Bill's contact_id is required +
                                must be a vendor/both; Expense's is optional, no type restriction.
                                Both use Concerns/ValidatesDocumentLines for the shared per-line
                                category/tax-rate semantic checks.
  StoreBillPaymentRequest        Phase 4 T5: amount ≥ 1, bank_account_id, payment_date — no
                                base_amount (bills are base-currency-only, unlike StorePaymentRequest).
  StoreAttachmentRequest         Phase 4 T8 (spec §17.8): file required, max:10240 (10MB, the
                                friendly HTTP-path half of the size guard) + App\Rules\
                                AllowedAttachmentContent (the content-sniff half).
  Concerns/ValidatesDocumentLines.php   Phase 4 T2: shared trait for the four expense/bill
                                FormRequests above — per-line category-account eligibility
                                (delegates to CategoryAccountGuard::guard) + tax-rate tenant/archived
                                checks, each its own field-level error.
  CompleteReconciliationRequest.php   Phase 6 Task 1 (spec §7.9): shape validation for
                                ReconciliationController::complete — lifts X-Idempotency-Key into a
                                virtual field (mirrors StoreTransferRequest/StorePaymentRequest);
                                `ticked_journal_line_ids` is `present` not `required` (an explicit
                                empty array is legal — a no-clearing-activity statement still
                                reconciles to its opening balance). Authorization is the
                                `can:reconciliation.manage` route middleware, not this class.
Rules/
  AllowedAttachmentContent.php   Phase 4 T8 (spec §17.8): ValidationRule wrapping
                                AttachmentContentSniffer — rejects a spoofed upload (e.g. a renamed
                                .exe claiming to be a .pdf) with a friendly field-level 422 before
                                AttachmentService is ever reached.
Models/
  Company.php                  tenant root: name, base_currency, fiscal_year_start_month, books_locked_through.
  User.php                     + companies() pivot (role), current_company_id, roleIn() (cached).
  Concerns/BelongsToCompany.php  trait: global company scope + auto-fill company_id. NEVER put company_id in $fillable.
  Account.php                  chart-of-accounts row; system-account guards; isControlAccount().
                                Phase 7 T1 (sub-accounts): + parent_id (self-FK, nullable,
                                restrictOnDelete)/is_postable (explicit bool, NEVER derived from
                                "has children" — flipped false only inside AccountService::makeParent's
                                own transaction)/depth/path (materialized ancestor path, e.g. "/12/152/",
                                indexed for prefix-scan descendant lookups) — all pure derivations of
                                parent_id, computed in the model's own created()/updating() events,
                                NEVER fillable. isPostable()/parent()/children()/descendants()
                                (path-prefix query, no recursive N+1). creating()/updating() guards
                                block SystemAccountNotNestable + reject a raw parent_id write that
                                would bypass AccountService's cascade. Phase 7 T4: + cf_class
                                (nullable, App\Enums\CashFlowClass) for the Statement of Cash Flows.
  JournalEntry.php, JournalLine.php   the ledger. Immutable. Written ONLY by LedgerService.
                                journal_lines.contact_id now has a composite tenant FK →
                                contacts(id, company_id) (Phase 2 T1; was non-null-only in Phase 1).
                                Phase 7 T3: + entry_number (nullable at the schema level only —
                                every row LedgerService::post creates always has one) + displayNumber()
                                → "JE-000142" + reversesEntry() BelongsTo (a reversal's own entry
                                number can point back at "reverses JE-000142").
  ManualJournal.php            source document for a manual JE.
  ActivityLog.php              append-only audit rows. Phase 6 Task 2 added a user() BelongsTo (the
                                actor, nullable) so the Audit log viewer can eager-load names for display.
  IdempotencyKey.php           (company_id,key) unique; stores replay snapshot.
  IntegrityRun.php             persisted integrity-check result (clean? findings json).
  Contact.php                  Phase 2 T1: customer/vendor/both; name/email/phone/tax_number/
                                billing_address/notes; archivable. Phase 7 T2: + scopeActive/
                                scopeArchived (shared shape with Account/Item/BankAccount below).
  TaxRate.php                  Phase 2 T3: name + rate (basis points, integer).
  Item.php                     Phase 2 T2: name/unit_price(minor units)/default_income_account_id/
                                default_tax_rate_id (both nullable, tenant+type validated); archivable.
                                Phase 7 T2: + scopeActive/scopeArchived.
  BankAccount.php               Phase 2 T5: bank|cash, currency (=base, enforced), linked Account
                                (auto-created 10xx code); archivable (doesn't cascade-archive the CoA row).
                                Phase 6 Task 1 added a reconciliations() HasMany. Phase 7 T2: +
                                scopeActive/scopeArchived — every invoice/bill/expense payment
                                bank-account picker now applies ->active().
  Transfer.php                  Phase 2 T6: source document for a P11 transfer (from/to BankAccount, amount).
  OtherReceipt.php               Phase 2 T7: source document for a P14 receipt (bank, category account, payer).
  OpeningBalanceRun.php         Phase 2 T8: one row per opening-balance setup/re-run; status active|void.
  OpeningDocument.php            Phase 2 T8: per-contact opening AR/AP line (reference/original_date/
                                due_date/amount/status); composite tenant FK on contact_id+company_id
                                (contact_id NOT NULL, unlike journal_lines); feeds Phase 5 aging.
                                Phase 3 T6 added invoice_id (nullable FK → invoices) linking a
                                receivable document to its is_opening Invoice. Phase 4 T7 added
                                bill_id (nullable FK → bills, nullOnDelete) — the exact AP-side
                                mirror, linking a payable document to its is_opening Bill.
  Invoice.php                    Phase 3: number (nullable=draft; gap-free once assigned), status
                                (InvoiceStatus), currency_code/fx_rate + base_subtotal/base_tax_total/
                                base_total (doc vs. base split, spec §3), subtotal/tax_total/total/
                                amount_paid/balance, is_opening, email_status, sent_at/voided_at.
                                lines() frozen once posted (InvoiceLine::booted() guard).
  InvoiceLine.php                Phase 3: description/quantity/unit_price/income_account_id/
                                tax_rate_id/tax_rate_snapshot (frozen at send)/line_subtotal/line_tax.
                                booted() throws if a line on a posted invoice is touched.
  InvoicePayment.php              Phase 3: amount (doc currency)/base_amount (actual PKR received)/
                                base_ar_relief (AR credit at the invoice's OWN rate, spec §17.1
                                settlement rounding)/fx_rate (implied, display-only)/status
                                (posted|void)/bank_account_id.
  EmailAttempt.php                Phase 3 T10: one row per send/resend attempt — status (sent|failed)
                                + error, append-only history distinct from Invoice.email_status
                                (the CURRENT state) — spec §17.6 decoupled delivery.
  Expense.php                     Phase 4 T1: contact_id (nullable — payee is optional, no type
                                restriction, spec §7.8)/status (ExpenseStatus)/expense_date/
                                bank_account_id/subtotal/tax_total/total/notes. lines() + morphMany
                                attachments(). Row fields (status/recorded_at/voided_at) keep
                                changing post-draft; only LINES freeze (see ExpenseLine).
  ExpenseLine.php                  Phase 4 T1: description/category_account_id/tax_rate_id/
                                tax_rate_snapshot (frozen at record)/amount (minor units, net — no
                                qty/unit_price)/line_tax. booted() throws once the parent expense
                                leaves draft — INCLUDING void (unlike InvoiceLine, which only
                                freezes on isPosted()).
  Bill.php                        Phase 4 T1: contact_id (NOT NULL, must be vendor/both)/reference
                                (the vendor's OWN bill number — no internal numbering)/status
                                (BillStatus)/bill_date/due_date/subtotal/tax_total/total/amount_paid/
                                balance/is_opening. lines() + payments() + morphMany attachments() +
                                openingDocument() (Phase 4 T7, AP mirror of Invoice::openingDocument).
  BillLine.php                     Phase 4 T1: description/category_account_id/tax_rate_id/
                                tax_rate_snapshot/amount/line_tax. booted() freeze guard — exact
                                mirror of ExpenseLine's (freezes on ANY non-draft status incl. void).
  BillPayment.php                  Phase 4 T1: bill_id/payment_date/amount (minor units — no
                                base_amount/fx_rate/base_ar_relief, bills are base-currency-only,
                                unlike InvoicePayment)/bank_account_id/status (posted|void)/memo.
  Attachment.php                   Phase 4 T8 (spec §17.8): disk_path/original_name/mime/size/
                                uploaded_by; morphTo attachable() (Expense|Bill). attachable_id/type
                                deliberately absent from $fillable — set via the morph relation's
                                setAttribute(), never mass-assigned.
  Reconciliation.php                Phase 6 Task 1 (spec §7.9): one completed (or since-reopened)
                                bank statement reconciliation — bank_account_id/statement_date/
                                statement_ending_balance/opening_reconciled_balance (carried from
                                the prior completed reconciliation's own ending balance, 0 for the
                                first)/status ('completed'|'reopened')/completed_by/completed_at.
                                lines() HasMany ReconciliationLine. NEVER writes a ledger entry —
                                see ReconciliationService's own class docblock.
  ReconciliationLine.php             Phase 6 Task 1: THE "reconciled" marker — a journal_line is
                                reconciled iff a row exists here under a still-'completed'
                                reconciliation. (company_id, journal_line_id) unique index enforces
                                "a line can't be reconciled twice while live"; reopen() deletes a
                                reconciliation's rows here, which is what unlocks its lines again.
  MonthEndClose.php                  Phase 6 Task 5 (spec §17.5): one row per company per month-end
                                date — holds ONLY what can't be derived from existing tables: the
                                two manual-acknowledge timestamps (ar_ap_reviewed_at/
                                trial_balance_reviewed_at) and completed_at/completed_by. The other
                                4 of the 7 close steps are computed live by MonthEndCloseService and
                                never stored here.
Providers/AppServiceProvider.php  registers Gates from config/permissions.php; scoped bindings.
Services/
  CompanySetupService.php      create company, attach Owner, seed defaults.
  ChartOfAccountsSeeder.php    seeds the §5 chart into a new company — now **25 accounts** (Phase 2
                                Task 4 added 4950 FX Gain / 5950 FX Loss); exposes fxAccounts() for
                                the backfill command.
  LedgerService.php            ⭐ THE GATE (see §6). Unchanged in Phase 2 — every new write-side
                                service posts through it. Phase 7 T1: validate() now throws
                                NonPostableAccount if any line targets a non-leaf account. Phase 7 T3:
                                post() allocates entry_number via DocumentNumberService's new 'JE'
                                series (not part of EntryDraft — no posting/balance logic changed).
  ManualJournalService.php     manual JE post/reverse (LedgerService + Idempotency + Audit).
  AccountService.php            Phase 7 T1 (sub-accounts): ⭐ createChild/makeParent/moveSubtree — see
                                §5's full writeup. The one service allowed to move an EXISTING
                                journal_line's account_id (makeParent's leaf→child move), always
                                inside the same transaction as the row that stops being postable, so
                                every report's grand total is provably unchanged.
  AccountBalances.php          signed per-account balances from journal_lines (read).
  IntegrityChecker.php         source-type-aware checks per company (read) — see §5 READ side note.
  Reports/TrialBalanceReport.php  trial balance from journal_lines (read). Phase 7 T1: now reads
                                through AccountRollup — depth-first ordered rows, a parent (header)
                                row is a pure rollup of its descendant leaves' net (never counted a
                                second time into total_debit/total_credit), byte-identical to
                                pre-Phase-7 for a company with no sub-accounts.
  Reports/AccountRollup.php      Phase 7 T1/T5: ⭐ shared by TrialBalance/BalanceSheet/ProfitAndLoss/
                                GeneralLedger/TransactionDetail/AccountQuickReport — order() depth-
                                first sorts accounts (a parent immediately followed by its
                                descendants); rollup() sums each parent's row as a pure SUM of its
                                leaf descendants' raw net. The ONE place "how do sub-account subtotals
                                roll up" is decided, so every report agrees.
  Reports/LedgerBalances.php     Phase 5 T1: ⭐ the ONE shared per-account journal_lines
                                aggregation every report below reads through — cumulativeAsOf()
                                (Trial-Balance-style, as-of a date) / movement() (P&L-style, over a
                                period), each optionally filtered by account id or AccountType.
                                Reproduces TrialBalanceReport's own inclusion rule exactly (posted
                                lines whose entry_date falls in the window — no separate "posted"
                                flag exists; LedgerService's rows are always already-posted).
  Reports/ReportPeriod.php       Phase 5 T1: readonly value object — fiscal-year math off
                                companies.fiscal_year_start_month (currentFiscalYearStart(),
                                completedFiscalYears(asOf, since)); never calls now()/today() itself,
                                so it stays pure and trivially testable.
  Reports/ProfitAndLossReport.php  Phase 5 T2: income/expense movement over a period + the same
                                figures split into calendar-month columns (feeds both the report's
                                own comparison columns and the dashboard's chart); net_profit =
                                income − expenses, positive-is-normal sign convention throughout.
                                Phase 7 T1: now reads through AccountRollup like TrialBalanceReport.
  Reports/BalanceSheetReport.php  Phase 5 T3: assets/liabilities/equity as-of a date; Retained
                                Earnings = account 3200's posted balance + every COMPLETED fiscal
                                year's net income (ReportPeriod::completedFiscalYears), Current-Year
                                Earnings its own separate equity line. Roll-up's lower bound is
                                min(books_start_date, earliest posted entry_date) — a
                                books_start_date-only bound would drop any activity dated before it
                                and silently break the MUST-BALANCE identity; the min() fix landed
                                as a same-phase (Task 3) review fix once real data exposed it.
                                Phase 7 T1: rows AccountRollup-ordered like TrialBalanceReport;
                                is_balanced/difference unaffected (parent subtotals are display-only).
  Reports/AgingReport.php        Phase 5 T4: ONE engine for both A/R and A/P (direction param) —
                                outstanding balance per contact bucketed by the SOURCE DOCUMENT's
                                own due date (Current/1–30/31–60/61–90/90+), the one deliberate
                                exception to §9's "never document totals" rule (for the bucket DATE
                                only — amounts still tie to 1100/2000). Writes its own direct
                                journal_lines queries grouped by source_id (LedgerBalances is
                                account-level only, this needs a per-document split); an opening
                                document's own-total contribution is date-gated on its opening run's
                                primary entry date so a report dated before the run takes effect
                                still ties to the control account.
  Reports/GeneralLedgerReport.php  Phase 5 T5: per-account opening/lines/running-balance/closing
                                over a period — the drill-down target every other Phase 5 report
                                links into. Optional contact filter narrows the LISTED lines (e.g.
                                from an aging row) but does not attempt a per-contact opening
                                balance, so it deliberately hides the balance columns once active
                                (they'd misrepresent a per-contact sub-ledger the class was never
                                asked to build). Phase 7 T1/T5: account picker now includes header
                                (parent) accounts; AccountQuickReport is the newer, simpler single-
                                account alternative most Phase 7 drill-down links use instead.
  Reports/TaxSummaryReport.php   Phase 5 T6: 2100 Tax Payable movement over a period, split
                                collected (source_type='invoice') / input (expense·bill tax_total) /
                                remittances (expense·bill lines whose OWN category account is 2100 —
                                a P12 tax remittance) / adjustments — classified by each journal
                                entry's own source_type, NEVER by debit/credit column (a reversal
                                swaps sides, so a naive debit=input rule would misclassify a voided
                                invoice's reversal). Adjustments is a structural RESIDUAL
                                (totalMovement − collected + input + remittances) so the tie-out to
                                the ledger can't silently drift as new source types touch 2100 (a
                                manual journal, or an other_receipt whose category is 2100 — spec-
                                allowed, see §10 carry-forward).
  Reports/CashFlowReport.php     Phase 7 T4: ⭐ the Statement of Cash Flows — see §5's full writeup
                                (two independently-coded engines that must agree). accounts.cf_class
                                drives the direct engine; the indirect engine's add-backs read the
                                depreciation EXPENSE account's own movement only.
  Reports/TransactionDetailReport.php  Phase 7 T5: every posted line in a period grouped by account
                                (AccountRollup-ordered), each account's subtotal TIES to
                                LedgerBalances::movement — the drill-down library's "all activity"
                                view.
  Reports/AccountQuickReport.php  Phase 7 T5: one account's running balance over a period
                                (cumulativeAsOf(from−1)→cumulativeAsOf(to)); a PARENT account
                                interleaves every descendant leaf's lines by date via AccountRollup, a
                                LEAF shows only its own — the drill-down TARGET every other report
                                links into (mirrors GeneralLedgerReport's own role, account-scoped).
  Reports/ContactLedgerReport.php  Phase 7 T6: ⭐ the customer/vendor report library — 9 methods
                                (customerBalanceSummary/Detail, vendorBalanceSummary/Detail,
                                openInvoices, unpaidBills, collections, customerStatement,
                                vendorStatement) — see §5's full writeup. Every total ties to 1100
                                (AR) / 2000 (AP); outstanding amounts are base-correct on an FX
                                invoice (never the doc-currency balance).
  BankAccountService.php        Phase 2 T5: creates a BankAccount + its linked 10xx Account together;
                                concurrency-safe code allocation (lockForUpdate + 3-retry).
  TransferService.php           Phase 2 T6: P11 posting + guards (from≠to, amount≥1, tenant+archived,
                                locked period) — "nothing posted" on any guard failure.
  OtherReceiptService.php       Phase 2 T7: P14 posting + IneligibleCategoryAccount guard.
  OpeningBalanceService.php     Phase 2 T8: ⭐ P8a-c combined-entry posting + re-run void-then-repost;
                                OpeningBalancesLocked guard distinguishes reversals from real activity.
                                Phase 3 T6: also creates/voids an is_opening Invoice per customer
                                opening receivable (createOpeningInvoice() — never calls
                                LedgerService itself, see §5). Phase 4 T7: exact AP-side mirror —
                                also creates/voids an is_opening Bill per vendor opening payable
                                (createOpeningBill()), same "never posts its own entry" reasoning.
  OpeningCsvParser.php           Phase 2 T9: CSV → staged rows for bank/customer-invoice/vendor-bill
                                kinds; Money::parseToMinor; NEVER calls LedgerService/OpeningBalanceService.
  InvoiceCalculator.php          Phase 3 T2: per-line total = quantity × unit_price (half-up, incl.
                                the .5-tie-rounds-up case) then Money::taxMinorUnits per line, summed
                                — never a whole-document total × rate.
  InvoiceService.php             Phase 3 T1/T3/T5/T7: draft CRUD + ⭐ send() (P1/P1fx) + void() (P9).
                                Freezes tax_rate_snapshot + recomputes line totals BEFORE the
                                status flip (still-draft), assigns the number via
                                DocumentNumberService, then posts through LedgerService. void()
                                refuses while any payment is 'posted' (InvoiceHasLivePayments) and —
                                Phase 4 T6 retro-fix, closing a Phase 3 carry-in — now throws a
                                friendly InvoiceIsOpening 422 on an is_opening invoice (manage via
                                opening balances instead) rather than 404ing on the missing entry.
  InvoicePaymentService.php      Phase 3 T4/T8: ⭐ recordPayment() (P2/P3/P2fx) + voidPayment() (P9's
                                payment-side mirror). The overpayment guard is a lockForUpdate() READ
                                of the invoice row INSIDE this method's own transaction — proven
                                under genuine cross-process contention by
                                tests/Feature/InvoicePaymentConcurrencyTest (Task 13).
  InvoicePdf.php                  Phase 3 T9: renders resources/views/pdf/invoice.blade.php via
                                barryvdh/laravel-dompdf; dual-currency block gated on sent+ status
                                (never fabricates an FX rate on a still-draft foreign invoice).
  DocumentNumberService.php       Phase 3 T1: gap-free, race-safe numbering — insertOrIgnore +
                                lockForUpdate on a per-(company,type) document_counters row; no
                                TOCTOU window. Bills/expenses carry NO internal numbering (spec
                                decision, Phase 4 brief) — was invoice-only through Phase 6. Phase 7
                                T3 added a SECOND series ('JE') — LedgerService::post now allocates a
                                gap-free entry_number through this same counter machinery on every
                                posting, no new race-safety logic needed.
  EntryNumberBackfiller.php       Phase 7 T3: one-shot backfill (invoked once, from the
                                add_entry_number_to_journal_entries migration, not a standalone
                                artisan command) — numbers every UN-numbered journal_entries row, per
                                company, in entry_date-then-id order, above that company's current
                                max entry_number (single UPDATE...JOIN window function, no PHP loop),
                                then advances document_counters' 'JE' row via GREATEST() so it's never
                                behind. IDEMPOTENT (only touches entry_number IS NULL rows) — a
                                review fix after the first version re-ranked ALL rows and collided
                                with the unique(company_id,entry_number) index on any re-run after new
                                out-of-order backdated rows landed.
  DocumentLineCalculator.php      Phase 4 T2: pure expense/bill-totals calculator, the AP-side
                                mirror of InvoiceCalculator — AMOUNT-based lines (no qty×price,
                                spec §7.8): tax per line on the typed net amount via
                                Money::taxMinorUnits, summed per-line-then-total, never on a
                                pre-summed subtotal. No DB access, no float.
  ExpenseService.php              Phase 4 T3: ⭐ record() (P4/P5) + void() (P9). Re-validates every
                                line's category account (CategoryAccountGuard, caller-independent),
                                freezes tax_rate_snapshot + recomputes totals BEFORE the status flip
                                (still-draft — ExpenseLine::booted() would otherwise reject the
                                write), then posts through LedgerService. Requires a non-archived,
                                tenant-owned bank_account_id to record (ExpenseBankAccountRequired).
  BillService.php                 Phase 4 T4/T6: ⭐ receive() (P6/P6t) + void() (P9). Identical
                                shape to ExpenseService::record — same freeze-then-post ordering,
                                same CategoryAccountGuard re-check. Extra guards vs. Expense: vendor
                                contact required (BillContactNotEligible), due_date required
                                (BillDueDateRequired). void() refuses while any payment is 'posted'
                                (BillHasLivePayments, payments-first) or if is_opening (BillIsOpening).
  BillPaymentService.php          Phase 4 T5: ⭐ record() (P7) + void() (P9's payment-side mirror).
                                The overpayment guard is a lockForUpdate() READ of the BILL row
                                INSIDE this method's own transaction — the exact AP-side mirror of
                                InvoicePaymentService's pattern proven under genuine cross-process
                                contention (Phase 3 Task 13). No FX (bills are base-currency-only).
  AttachmentService.php           Phase 4 T8 (spec §17.8): ⭐ THE attachment read/write gate, shared
                                by ExpenseController and BillController — store() (10MB cap +
                                content-sniffed allowlist via AttachmentContentSniffer, uuid
                                filename, private `local` disk), delete(), deleteAllFor() (draft-
                                delete cascade — rows now, files after the caller's txn commits),
                                download() (streams back the ORIGINAL filename). Every op audited.
  ReconciliationService.php        Phase 6 Task 1 (spec §7.9): ⭐ bank reconciliation — writes NO
                                ledger entries anywhere in this class. start() is a READ-only
                                preview (unreconciled lines + opening_reconciled_balance). complete()
                                REFUSES (ReconciliationOutOfBalance) unless the live difference is
                                exactly 0, and REFUSES (ReconciliationOutOfOrder) a statement dated
                                on/before the account's latest completed reconciliation — both guard
                                the opening_reconciled_balance chain. reopen() is NEWEST-FIRST ONLY
                                (ReconciliationNotNewest) for the same reason, and refuses a second
                                reopen (ReconciliationAlreadyReopened). Shape mirrors
                                InvoicePaymentService/TransferService: Idempotency → DB::transaction →
                                lockForUpdate (bank account row on complete, + the reconciliation row
                                too on reopen) → guards → mutation → AuditLogger.
  MonthEndCloseService.php         Phase 6 Task 5 (spec §17.5): PURE READ, no new accounting logic —
                                computes the 7-step close checklist's done/not-done status fresh
                                every request over existing machinery (T1 reconciliations, draft
                                documents, IntegrityChecker's persisted IntegrityRun, the period
                                lock). Only steps() is public; the two manual-acknowledge steps read
                                MonthEndClose, the other five are derived from real tables with no
                                storage of their own.
  AccountantPackageService.php      Phase 6 Task 4 (spec §11): ⭐ the one-click Accountant Package —
                                General Ledger/Trial Balance/P&L/Balance Sheet/A/R aging/A/P aging
                                for a chosen period, each as a CSV + a PDF (dompdf, mirrors
                                InvoicePdf's own palette/money-formatting), zipped into 12 entries.
                                Every figure comes from the SAME Phase 5 report services every
                                standalone report/export already reads — this class performs NO
                                accounting math of its own, only formats/bundles. Every failure
                                branch after tempnam() unlinks the temp file before rethrowing — no
                                temp-file leak on success OR exception.
  FullDataExportService.php        Phase 7 T7 (spec §11): streams a per-company ZIP of 9 CSVs
                                (accounts, contacts, items, journal_entries, journal_lines, invoices,
                                invoice_payments, bills, bill_payments) — every query scoped by the
                                SAME BelongsToCompany trait every model already uses (proven company-B
                                rows absent). Deliberately excludes invoice_lines/bill_lines
                                (journal_lines already carries the full-fidelity postings) — see §10
                                for the additive-follow-up note if line-level detail is ever wanted.
  StatementPdf.php                 Phase 7 T6: renders a customer/vendor statement to PDF (dompdf,
                                mirrors InvoicePdf's own palette/money convention) — a thin view over
                                ContactLedgerReport::customerStatement/vendorStatement, no accounting
                                math of its own.
Support/
  CompanyContext.php           request-scoped current company; get() throws if unset.
  IdempotencyService.php       exactly-once execute() wrapper (baseline txn guard).
  AuditLogger.php              writes ActivityLog rows.
  Currencies.php               allowed ISO currency list (PKR, USD, …).
  Money.php                     Phase 2 T3: parseToMinor (string→minor units, no float, mirrors
                                money.ts) + taxMinorUnits (bcmath half-up tax rounding; Phase 3 T2
                                hardened to throw loud on negative/5-decimal input). Phase 5 T1
                                added minorToDecimalString (exact integer-math decimal string, no
                                number_format()/float division) — extracted from
                                TrialBalanceController::exportCsv so every later report CSV export
                                reuses the same money-rendering helper.
  FxConverter.php                Phase 3 T7: single source of truth for resolving/validating a send-
                                time fx_rate and converting a minor-unit amount to base (half-up,
                                bcmath) — both InvoiceService::send() and InvoicePaymentService
                                delegate to it rather than each rolling their own conversion.
  CategoryAccountGuard.php        Phase 4 T2 (spec §7.8): THE category-eligibility guard for
                                expense/bill lines — allows expense/asset/liability/equity accounts
                                (enables P12 tax remittance via 2100, P13 owner drawings via an
                                equity account, P15 fixed-asset purchases via an asset account);
                                blocks income/control(AR-AP)/cash-bank/archived. Static/stateless;
                                eligibleQuery() keeps the category-picker option list in lockstep
                                with guard()'s own rule (see its own test for the drift-proof check).
  AttachmentContentSniffer.php    Phase 4 T8 (spec §17.8): THE server-side content sniff — reads a
                                file's magic bytes via ext-fileinfo (finfo), NEVER the client-
                                declared Content-Type or filename extension. pdf/jpg/png/webp
                                allowlist shared by both App\Rules\AllowedAttachmentContent (HTTP-
                                path 422) and AttachmentService::store (caller-independent guard) so
                                the two can never drift apart. MAX_BYTES = 10MB.
  CsvExport.php                    Phase 6 Task 3 (spec §11/§17.8): shared streamed-CSV response
                                builder for every list-screen export endpoint. `$audit` is a
                                REQUIRED argument, logged BEFORE the streamed response is built — a
                                caller cannot get a CsvExport::stream() response without an audit row
                                also being written. The pre-existing Phase 5 report CSV exports
                                predate this helper and log inline instead (unchanged, no regression
                                risk); this is the completeness guarantee for every NEW export.
  AuditHumanizer.php                Phase 6 Task 2: turns a raw activity_log `action` code + subject
                                reference into human-readable strings — action() ('invoice.sent' ->
                                "Invoice sent"), subject() (morph class + id -> "Bill Payment #7"),
                                module() (the action prefix before the first dot, e.g. 'bill_payment'
                                — feeds the Audit log viewer's own module filter). Extracted FROM
                                DashboardController's recent-activity widget so both surfaces can
                                never drift on what an action code means. Never touches before/after.
  BackupRetentionPolicy.php         Phase 6 Task 6 (spec §11): pure fn — THE `sparkbooks:backup`
                                retention rule, "14 daily + 8 weekly, prune the rest." No disk I/O,
                                no clock reads of its own (filenames + "today" passed in) — fully
                                testable with fabricated filenames. Daily tier is calendar-anchored
                                (not a plain top-N cut, so a missed day can't pull an extra old
                                backup into the fresh window); weekly tier keeps the single
                                most-recent backup per ISO (year, week). An undatable filename is
                                always marked for deletion, never silently kept.
  Heartbeat.php                     Phase 6 Task 7 (spec §17.9): dead-man's-switch ping
                                (healthchecks.io-style) — ping('backup'|'integrity'|'queue').
                                Config-gated exactly like Sentry's DSN: a blank URL is a clean no-op.
                                A network failure while pinging is swallowed (logged, never thrown)
                                — the whole point of a dead-man's-switch is that the EXTERNAL service
                                pages on silence, not on an exception here.
  AlertNotifier.php                  Phase 6 Task 7 (spec §17.9): alert-the-moment-it-happens —
                                email + optional Slack, both config-gated (unset = inert). Called by
                                VerifyLedgerIntegrity (findings, or zero companies) and
                                BackupDatabase (any failure) the instant the condition is detected.
                                Neither channel configured still logs a warning so a real failure
                                stays visible in logs; each channel's own send failure is caught and
                                logged separately — a broken alert channel never throws back into the
                                command reporting a real failure.
  SecretScrubber.php                 Phase 6 Task 7 review fix: redacts `--password=<secret>`/
                                `-p<secret>` from free text before it's logged or alerted. Closes a
                                real leak — mysqldump's Process exception messages (timeout/
                                start-failure) interpolate the FULL command line, password included;
                                every BackupDatabase failure message is scrubbed through here before
                                it reaches the console, logs, OR the alert body.
Jobs/
  SendInvoiceEmail.php            Phase 3 T10: queued job — sets CompanyContext via runFor, sends
                                InvoiceMail, records an EmailAttempt, updates Invoice.email_status.
                                Dispatched AFTER the posting transaction commits (never inside it) —
                                a mail failure can never roll back or touch the ledger.
  QueueHeartbeatJob.php            Phase 6 Task 7 (spec §17.9): ⭐ dispatched by
                                `sparkbooks:queue-heartbeat` (scheduled daily 04:00). The heartbeat
                                ping fires ONLY from inside this job's own handle() — proves a real
                                queue:work process is alive, unlike the scheduled command's own
                                execution (cron fires regardless of any worker existing). A dead
                                worker means this job sits queued forever and the ping never fires.
Mail/
  InvoiceMail.php                  Phase 3 T10: Mailable — renders InvoicePdf as an attachment.
  MonitoringAlertMail.php           Phase 6 Task 7 (spec §17.9): the monitoring alert email, sent by
                                AlertNotifier the instant a backup fails or ledger:verify finds
                                anything. Deliberately NOT ShouldQueue — an alert about a broken
                                system shouldn't wait behind a possibly-also-broken queue worker.
```

### Frontend — `resources/js/`

```
lib/
  money.ts (+ money.test.ts)   formatMoney (forced 2dp) + parseMoneyInput (string→minor units). No float.
                                computeLineTax is now called by BOTH InvoiceLineEditor AND (Phase 4
                                T2) the shared Components/documents/DocumentLineEditor — the latter
                                calls it directly on a typed amount (no multiplyByQuantity step
                                first, spec §7.8's amount-based lines).
  contactLabels.ts              Phase 2: shared customer/vendor/both label helpers (Contacts + opening
                                balances both use these — hoisted to avoid duplication).
  overdue.ts (+ overdue.test.ts)   Phase 4 T9: isBillOverdue (received/partially_paid + due_date <
                                today — the AP mirror of Invoices/Index's own long-untested inline
                                rule) + todayIsoDate. Deliberately extracted into its own tested
                                module (unlike the invoice side, still untested since Phase 3) so
                                this addition has direct vitest coverage. Client Date()-based — same
                                pre-existing PKR-timezone footgun as the invoice side (see §10).
  summarize.ts (+ summarize.test.ts)   Phase 4 T9: summarizeList — truncates a name list into a
                                "A, B +N more" display string for Expenses/Bills Index's "categories"
                                column.
  incomeExpenseChartData.ts (+ .test.ts)   Phase 5 T7: buildIncomeExpenseChartData — pure
                                transform from the dashboard's {labels, income, expenses} payload
                                into Chart.js's dataset shape; the only non-component logic behind
                                IncomeExpenseChart.tsx, tested independently of the canvas.
  textColor.ts (+ .test.ts)      Phase 5 T2: hasTextColorClass — detects an existing Tailwind
                                text-COLOR utility (vs. a text-size/alignment one) in a className
                                string; the predicate MoneyText.tsx uses to let a caller's color
                                override win deterministically (see Components/ui/ note below).
Components/ui/                  Button, Sheet, Field, MoneyText, StatTile — design-system primitives.
                                StatTile added Phase 2 T10 for the dashboard's 3 stat cards.
                                MoneyText.tsx (Phase 5 T2 review fix): omits its own default
                                text-ink class when the caller's className already carries a
                                text-color utility (lib/textColor.ts::hasTextColorClass), so an
                                override (e.g. a P&L loss shown in text-clay) wins deterministically
                                regardless of Tailwind's own stylesheet ordering. MoneyText.test.ts
                                (Phase 5 T2) is this component's first direct test.
                                ExportCsvLink.tsx (Phase 6 Task 3, spec §11/§17.8): a plain `<a>` (not
                                an Inertia Link, so the browser navigates natively and streams the
                                CSV download) — factored out of Reports/TrialBalance.tsx's own inline
                                link since every list Index.tsx (Invoices/Bills/Expenses/Contacts/
                                Items/Banking/Journal) needs the identical button. Callers gate
                                rendering on `can['exports.run']` themselves; the component always
                                renders once mounted.
Components/dashboard/           Phase 5 T7 (spec §7.12): the Dashboard's own widget components.
  DocumentSummaryCard.tsx         shared shape for "Outstanding & overdue invoices" / "Unpaid
                                bills" — same card, different noun/figures; the overdue subset only
                                gets its own (amber) callout when non-zero.
  IncomeExpenseChart.tsx          the app's only Chart.js surface — a small 6-month income-vs-
                                expense bar chart, styled from the app's own design tokens (Chart.js
                                draws to a <canvas>, so token hex values are copied in literally, not
                                resolved from Tailwind); animation skipped under
                                prefers-reduced-motion, mirroring every other transition in the app.
  PlSnapshotCard.tsx               current-fiscal-year-to-date income/expenses/net profit, tone
                                (spark/clay) matching Reports/ProfitAndLoss.tsx's own Net Profit
                                convention, links to the full report.
  RecentActivityCard.tsx           humanized action + subject + relative time list; renders
                                nothing at all when the caller passes an empty list
                                (DashboardController gates the underlying data on audit.view
                                server-side).
Components/ledger/
  BalanceBeam.tsx              ⭐ signature element: tilts to the heavier side, settles level + glows when balanced.
  LineEditor.tsx               the debit/credit line grid for the JE editor.
Components/invoices/            Phase 3 T11: invoice UI pieces.
  InvoiceLineEditor.tsx          the draft line-item grid (qty/price/income account/tax rate), live
                                subtotal/tax/total — mirrors InvoiceCalculator's own math client-side.
  PaymentModal.tsx                Record-payment dialog; adds the base-amount (PKR received) + live
                                implied-rate display when the invoice is foreign-currency.
  VoidDialog.tsx                  ⭐ the accounting-correct void flow: lists live payments with a
                                per-payment "Reverse" action, only reveals the final void-the-invoice
                                form once none remain (spec §6 payments-first rule).
  StatusChip.tsx                   draft|sent|partially_paid|paid|void pill, shared by Index/Show.
Components/documents/           Phase 4 T2/T5/T6/T8/T9: expense/bill UI pieces, the AP-side mirror
                                of Components/invoices/ — shared by BOTH Expenses/* and Bills/*.
  DocumentLineEditor.tsx          the amount-based line grid (category account + amount + tax rate,
                                no qty×price — spec §7.8), live per-line/document totals mirroring
                                DocumentLineCalculator's own math client-side.
  AttachmentSection.tsx           receipt/bill-scan list + upload (spec §17.8) — works on a draft OR
                                posted document (attachments addable anytime); upload/delete gates
                                (canUpload/canDelete) passed in per caller, enforced server-side too.
  BillPaymentModal.tsx            Record-payment dialog for a bill (Bills-only — Expenses have no
                                separate payments concept). No FX/implied-rate display (unlike
                                invoices/PaymentModal) — bills are base-currency-only.
  VoidDialog.tsx                   the accounting-correct void flow, generalized over documents that
                                may or may not carry a payments concept (Expense: none, straight to
                                confirm; Bill: full live-payments-first flow, mirrors
                                Components/invoices/VoidDialog). No date/memo fields — the server
                                always reverses dated today with a generic memo.
  PostConfirmDialog.tsx            shared "post it" confirmation for Expenses/Show's Record action
                                and Bills/Show's Receive action — shows the computed totals about to
                                post before the click fires.
  StatusChip.tsx                   the expense/bill status chip (EXPENSE_STATUS_LABELS/
                                BILL_STATUS_LABELS), shared by both Index pages so the two document
                                types never drift on color/shape — mirrors invoices/StatusChip.
Components/shell/               Wordmark, SidebarLink (gained `activeMatch` in Phase 2 T10 so e.g.
                                Banking stays lit while on Transfers/Other-receipts), CompanyMenu,
                                UserMenu — the app-shell chrome.
Components/ (Breeze originals)  Modal, Dropdown, TextInput, InputError, … (still used by auth/profile forms).
Layouts/
  AppShell.tsx                 ⭐ the ink-sidebar shell wrapping every in-app page. Phase 2 T10: filters
                                nav items by the shared `can` map and drops empty groups. Phase 4 T9
                                added a "Purchases" nav group (Expenses/Bills, gated
                                expenses.draft/bills.draft) alongside the existing Sales group.
                                Phase 5 filled out the "Reports" nav group (was Trial Balance only)
                                with Profit & loss / Balance sheet / A/R aging / A/P aging / General
                                ledger / Tax summary, all gated the pre-existing `reports.view`
                                ability (no NAV_ABILITIES change needed — it already covered this).
                                Phase 6 added: "Reconciliation" + "Month-end close" to the Banking
                                group (gated reconciliation.manage/periods.lock), "Accountant
                                package" to Reports (gated reports.view), and "Audit log" to Settings
                                (gated audit.view) — 3 new nav links.
  GuestLayout.tsx              centered card for auth pages. AuthenticatedLayout.tsx = Breeze leftover (unused).
Pages/
  Dashboard.tsx                cash position + customer/vendor/bank-account StatTile cards (Phase 2
                                T10); friendly empty-state hint when there's nothing yet. Phase 5 T7
                                (spec §7.12) added: outstanding/overdue invoices + unpaid bills
                                (DocumentSummaryCard ×2), a P&L snapshot (PlSnapshotCard), the
                                income-vs-expense chart (IncomeExpenseChart), and a gated recent-
                                activity feed (RecentActivityCard, renders nothing for a viewer
                                without audit.view).
  Onboarding/CreateCompany.tsx company setup form.
  Accounts/Index.tsx, Show.tsx chart of accounts + account ledger drill-down. Phase 7 T1/T2: Index.tsx
                                grew into a tree view (indented parent/child rows), createChild/
                                makeParent/moveSubtree actions (gated, hidden for system accounts),
                                archive/unarchive with a show-archived toggle.
  Journal/New.tsx, Index.tsx   ⭐ manual JE editor (BalanceBeam home) + journal list. Phase 6 Task 3:
                                Index.tsx gained an ExportCsvLink (status + entry-date-range filter,
                                gated exports.run). Phase 7 T3: Index.tsx displays each entry's
                                JE-000142 displayNumber() instead of the raw DB id.
  Reports/TrialBalance.tsx     trial balance report + CSV export button.
  Reports/ProfitAndLoss.tsx     Phase 5 T2: income statement — period picker (defaults to current
                                fiscal YTD), monthly comparison columns, CSV export, print.
  Reports/BalanceSheet.tsx      Phase 5 T3: as-of date picker, assets/liabilities/equity, a visible
                                balanced/unbalanced tie-out indicator (is_balanced/difference),
                                Retained Earnings detail (posted balance + completed-FY rollup),
                                CSV export, print.
  Reports/ArAging.tsx            Phase 5 T4: as-of date picker, per-contact bucket table, a
                                ties-to-control tie-out indicator, CSV export, print.
  Reports/ApAging.tsx            Phase 5 T4: A/P mirror of ArAging.tsx — same shape, 2000 control
                                account.
  Reports/GeneralLedger.tsx      Phase 5 T5: account/date-range/contact filters (accepts the same
                                drill-down query params other reports link in with), per-account
                                opening/lines/running-balance/closing, hides the balance columns
                                under an active contact filter (mirrors GeneralLedgerReport), CSV
                                export, print.
  Reports/TaxSummary.tsx         Phase 5 T6: period picker, collected/input/remittances/adjustments
                                breakdown + net tax payable, CSV export, print.
  Reports/CashFlow.tsx            Phase 7 T4: period picker, Operating/Investing/Financing/FX
                                sections + reconciles-to-cash indicator, CSV export, print; included
                                in the Accountant Package.
  Reports/TransactionDetail.tsx   Phase 7 T5: period picker, all posted lines grouped by account
                                with per-account subtotals, CSV export, print.
  Reports/AccountQuickReport.tsx  Phase 7 T5: account + period picker, running balance — the
                                drill-down landing page P&L/Balance Sheet/Trial Balance figures link
                                into (?account_id=&from=&to=, round-trips).
  Reports/CustomerBalanceSummary.tsx, CustomerBalanceDetail.tsx  Phase 7 T6: AR balance summary (all
                                customers) + per-contact detail, ties to 1100, CSV export.
  Reports/VendorBalanceSummary.tsx, VendorBalanceDetail.tsx  Phase 7 T6: AP mirror, ties to 2000.
  Reports/OpenInvoices.tsx, UnpaidBills.tsx  Phase 7 T6: unpaid/partially-paid documents only,
                                outstanding base-correct, CSV export.
  Reports/Collections.tsx         Phase 7 T6: overdue AR by customer, days-overdue + contact
                                phone/email, CSV export.
  Reports/CustomerStatement.tsx, VendorStatement.tsx  Phase 7 T6: contact picker + period, opening→
                                activity→closing, printable + a "Download PDF" (StatementPdf) button.
  Reports/AccountantPackage.tsx  Phase 6 Task 4 (spec §11): the one-click Accountant Package screen —
                                a from/to period picker (mirrors Reports/ProfitAndLoss.tsx's own),
                                lists the 6 bundled reports, a single Download button (gated
                                `canExport`/`exports.run`) that hits
                                /reports/accountant-package/download.
  Reconciliation/Index.tsx       Phase 6 Task 1 (spec §7.9): pick-a-bank-account landing page —
                                mirrors Banking/Index's account list, links into Reconciliation/Show
                                per account.
  Reconciliation/Show.tsx        Phase 6 Task 1: ⭐ the reconciliation workspace — statement date +
                                ending balance form, unreconciled-lines tick list with a LIVE
                                difference readout, Finish enabled ONLY at difference 0, a completed/
                                reopened history list with a Reopen action (newest-completed only).
                                Accountant screen — debit/credit words allowed.
  Close/Index.tsx                 Phase 6 Task 5 (spec §17.5): the month-end close checklist screen —
                                renders MonthEndCloseService's 7 step statuses (reconcile/aging-
                                review/TB-review/drafts-sweep/integrity/lock/export), each with its
                                own action (acknowledge/run check/lock/link to the accountant
                                package), plus a final "Complete close" action once the period is
                                locked through the month end.
  Audit/Index.tsx                  Phase 6 Task 2 (spec §7.13): the read-only audit-log viewer —
                                paginated (50/page) table (timestamp/user/humanized action/subject/
                                IP), filters by user/module/date-range. Never renders a row's raw
                                before/after JSON.
  Settings/Books.tsx, Health.tsx  period lock + integrity health.
  Settings/BackupExport.tsx     Phase 7 T7 (spec §11): backup-status widget (read-only fs scan) +
                                two download buttons — Accountant Package (period picker, reuses the
                                Phase 6 flow) and Full Data Export (9-CSV zip), both gated
                                `exports.run`.
  Settings/TaxRates.tsx         Phase 2 T3: tax rates CRUD screen.
  Contacts/Index.tsx            Phase 2 T1: contacts list, type filter (All/Customers/Vendors),
                                show-archived toggle, "New contact" modal. Phase 6 Task 3 gained an
                                ExportCsvLink (gated exports.run).
  Contacts/Show.tsx             Phase 2 T1: contact drill-down — outstanding balance + activity feed.
  Items/Index.tsx                Phase 2 T2: items catalog CRUD screen. Phase 6 Task 3 gained an
                                ExportCsvLink (gated exports.run).
  Banking/Index.tsx             Phase 2 T5: bank/cash accounts list + "Record receipt"/"Transfer"/
                                "New account" actions, per-account balance. Phase 6 Task 3 gained an
                                ExportCsvLink (gated exports.run).
  Banking/Transfer.tsx           Phase 2 T6: transfer-between-accounts form.
  Banking/OtherReceipt.tsx       Phase 2 T7: record-a-receipt form (bank, category, amount, payer).
  Setup/OpeningBalances.tsx     Phase 2 T8-T9: ⭐ the opening-balances wizard — books start date,
                                bank balances, customer invoices, vendor bills (each section supports
                                add-row, CSV template download, CSV upload), live OBE-plug summary,
                                re-run ("Re-enter opening balances") after a successful run.
  Invoices/Index.tsx             Phase 3 T11: invoice list, status filter (Draft/Sent/Partially
                                paid/Paid/Void), "New invoice". Phase 6 Task 3 gained an
                                ExportCsvLink (gated exports.run).
  Invoices/Edit.tsx               draft editor — customer/currency/dates + InvoiceLineEditor +
                                Save draft/Send invoice (FX rate prompt on send if foreign currency).
  Invoices/Show.tsx               ⭐ the invoice detail screen — status, dual-currency total/balance
                                (≈ PKR + rate when foreign), line items, payments table (PKR
                                received/implied rate columns on FX invoices), Record payment/Void/
                                Download PDF/Send email(+Resend)/email status, all idempotency-keyed.
  Expenses/Index.tsx              Phase 4 T9: expense list, status filter (Draft/Recorded/Void),
                                search (payee/notes), "categories" summary column (summarizeList).
                                Phase 6 Task 3 gained an ExportCsvLink (gated exports.run).
  Expenses/Edit.tsx                the draft editor — payee/date/bank account + DocumentLineEditor +
                                AttachmentSection + Save draft; Record moved to Show this task.
  Expenses/Show.tsx                ⭐ the canonical detail page for every status — line items,
                                attachments, Record (PostConfirmDialog)/Void (VoidDialog) actions,
                                all idempotency-keyed. Mirrors Invoices/Show's shape.
  Bills/Index.tsx                  Phase 4 T9: bill list, status filter incl. a synthetic "Overdue"
                                chip (isBillOverdue), row highlight on an overdue bill, "categories"
                                summary column, search (vendor/reference/notes). Phase 6 Task 3
                                gained an ExportCsvLink (gated exports.run).
  Bills/Edit.tsx                   the draft editor — vendor/reference/dates + DocumentLineEditor +
                                AttachmentSection + Save draft; Receive/payments moved to Show.
  Bills/Show.tsx                   ⭐ the canonical detail page for every status — line items,
                                payments table, opening-bill banner (relocated here from Edit),
                                Receive (PostConfirmDialog)/Record payment (BillPaymentModal)/Void +
                                per-payment Reverse (VoidDialog) actions, Overdue tag, all
                                idempotency-keyed.
  Auth/*, Profile/*            Breeze auth + profile (restyled to tokens).
types/                         index.d.ts (shared Inertia props incl. currentCompany), global.d.ts.
app.tsx                        Inertia bootstrap; fonts; progress-bar color.
```

### Views — `resources/views/` (Blade — new in Phase 3, everything else is Inertia/React)

```
pdf/invoice.blade.php           rendered by InvoicePdf via barryvdh/laravel-dompdf; dual-currency
                                block only when sent+ and foreign-currency.
emails/invoice.blade.php        rendered by InvoiceMail; dev mail driver is `log` (MAIL_MAILER),
                                so a sent email is inspected via storage/logs/laravel.log, not a
                                real inbox.
pdf/reports/layout.blade.php    Phase 6 Task 4 (spec §11): shared inline-CSS layout every
                                Accountant Package PDF extends — mirrors invoice.blade.php's own
                                header/palette so a report PDF and the invoice PDF read as one
                                product family. dompdf renders inline CSS only, never Tailwind.
pdf/reports/{aging,balance-sheet,general-ledger,profit-and-loss,trial-balance}.blade.php
                                Phase 6 Task 4: one view per Accountant Package report — each takes
                                the SAME data array its own Reports/*Controller::show already
                                builds (no separate PDF-only data shaping), rendered via
                                AccountantPackageService::renderPdf (Pdf::loadView(...)->setPaper('a4')).
emails/monitoring-alert.blade.php   Phase 6 Task 7 (spec §17.9): rendered by MonitoringAlertMail —
                                a plain subject/body layout, the alert's own free-text body already
                                scrubbed of secrets (SecretScrubber) before it ever reaches this view.
```

### Data — `database/migrations/` (in apply order)

```
0001_01_01_*               Laravel defaults (users, cache, jobs).
2026_07_07_233425 companies          | 233438 company_user | 233451 users.current_company_id     (Phase 0)
2026_07_08_000000 activity_log       | 000001 idempotency_keys                                    (Phase 0)
2026_07_08_000002 accounts           | 000003 journal_entries (unique source idx)                 (Phase 1)
2026_07_08_000004 journal_lines (CHECK chk_one_sided) | 000005 manual_journals | 000006 integrity_runs  (Phase 1)
2026_07_09_000000 contacts           | 000001 add_fk_to_journal_lines_contact_id (composite tenant FK)  (Phase 2 T1)
2026_07_09_000002 tax_rates          | 000003 items                                                (Phase 2 T2-T3)
2026_07_09_000004 bank_accounts      | 000005 transfers | 000006 other_receipts                    (Phase 2 T5-T7)
2026_07_09_000007 opening_balance_runs | 000008 opening_documents (composite tenant FK on contact_id) (Phase 2 T8)
2026_07_09_000010 document_counters (Phase 3 T1; numbers jump 8→10, cosmetic) | 000011 invoices | 000012 invoice_lines | 000013 invoice_payments  (Phase 3 T1)
2026_07_09_000014 add_invoice_id_to_opening_documents (Phase 3 T6) | 000015 add_base_ar_relief_to_invoice_payments (Phase 3 T8)
2026_07_10_000000 email_attempts                                                                    (Phase 3 T10)
2026_07_10_000001 expenses | 000002 expense_lines                                                   (Phase 4 T1)
2026_07_10_000003 bills (contact_id composite tenant FK, NOT NULL) | 000004 bill_lines               (Phase 4 T1)
2026_07_10_000005 bill_payments (no base_amount/fx_rate — base-currency only)                        (Phase 4 T1)
2026_07_10_000006 attachments (polymorphic attachable, private disk path, uuid filename)              (Phase 4 T8)
2026_07_10_000007 add_bill_id_to_opening_documents (Phase 4 T7, nullable, nullOnDelete — AP mirror of invoice_id) (Phase 4 T7)
2026_07_11_000000 reconciliations (bank_account_id FK, statement_date, statement_ending_balance/
                    opening_reconciled_balance BIGINT signed, status, completed_by/at)             (Phase 6 T1)
2026_07_11_000001 reconciliation_lines (reconciliation_id cascade, journal_line_id FK restrict,
                    amount BIGINT; UNIQUE (company_id, journal_line_id) — THE reconciled marker)   (Phase 6 T1)
2026_07_11_000002 month_end_closes (UNIQUE (company_id, month_end); ar_ap_reviewed_at/
                    trial_balance_reviewed_at/completed_at/completed_by — only the 2 manual-
                    acknowledge steps + the completion marker; every other close step is derived)  (Phase 6 T5)
2026_07_12_000000 add_sub_accounts_to_accounts_table (parent_id self-FK restrictOnDelete,
                    is_postable bool default true, depth unsignedTinyInt default 0, path indexed
                    string, nullable — PURE ADDITIVE: one-statement backfill sets every EXISTING
                    account's path to '/{id}/', parent_id/is_postable/depth already correct at their
                    column defaults; zero journal_lines rows touched)                              (Phase 7 T1)
2026_07_12_000001 add_entry_number_to_journal_entries_table (unsignedBigInteger nullable,
                    unique(company_id, entry_number) — PURE ADDITIVE: EntryNumberBackfiller numbers
                    every legacy row in entry_date-then-id order, no existing debit/credit/amount/
                    entry_date touched)                                                             (Phase 7 T3)
2026_07_12_000002 add_cf_class_to_accounts_table (string(20) nullable — PURE ADDITIVE: one CASE-
                    expression backfill derives every EXISTING account's cf_class from its own
                    code/subtype, mirroring ChartOfAccountsSeeder's identical mapping for new
                    companies going forward; zero journal_lines rows touched)                       (Phase 7 T4)
```

### Data — `database/factories/`

```
Existing factories (Company/User/Account/ManualJournal/Contact/TaxRate/Item/BankAccount/Transfer/
OpeningBalanceRun/OpeningDocument/Invoice/InvoiceLine/InvoicePayment) unchanged. Phase 4 T1/T8 added
their AP-side siblings, same shape as InvoiceFactory/InvoiceLineFactory/InvoicePaymentFactory:
ExpenseFactory, ExpenseLineFactory, BillFactory, BillLineFactory, BillPaymentFactory,
AttachmentFactory. Phase 6 T1/T5 added ReconciliationFactory, ReconciliationLineFactory,
MonthEndCloseFactory (same BelongsToCompany-aware shape as every factory above).
```

### Config / routes

```
config/permissions.php   the §7.14 catalog + per-role permission lists (the authorization source of truth).
                          invoices.draft/send/void and payments.record were already in the Phase 0
                          catalog (built ahead of need, per §7.14); Phase 3 is the first phase to
                          actually wire routes/gates to them — no config change needed. Same story
                          for expenses.draft/record/void and bills.draft/receive/void: already in the
                          Phase 0 catalog, Phase 4 is the first phase to wire routes/gates to them —
                          no config change needed here either. Phase 6 is a THIRD instance of the same
                          pattern: `reconciliation.manage`, `exports.run`, `audit.view` were all
                          already in the Phase 0 catalog (audit.view pre-existing since HealthController;
                          reconciliation.manage/exports.run built ahead of need) — Phase 6 is simply
                          the first phase to wire routes/UI to reconciliation.manage/exports.run. No
                          config/permissions.php change was needed for any of Phase 6.
routes/web.php           all in-app routes, each gated (see the gate on each group). Phase 3 added the
                          /invoices/* group, split into invoices.draft / invoices.send / payments.record /
                          invoices.void gate groups (each narrower than invoices.draft). Phase 4 added
                          /expenses/* (expenses.draft / expenses.record / expenses.void gate groups)
                          and /bills/* (bills.draft / bills.receive / payments.record [shared with
                          invoices] / bills.void gate groups) the same way; attachment upload/download
                          share their document's own draft gate, delete reuses the void gate (spec
                          §17.8's Owner/Admin/Accountant decision — the tightest existing ability that
                          already maps to that exact role set, not a new permission). Phase 5 added
                          /reports/* for profit-and-loss, balance-sheet, ar-aging, ap-aging,
                          general-ledger, tax-summary (trial-balance already existed) — the whole
                          group sits behind one `can:reports.view` route-group middleware, with every
                          `/export.csv` sibling additionally gated `can:exports.run` (stricter: view
                          AND export). Phase 6 added: a `can:reconciliation.manage` group for
                          /reconciliations/* (index/show/complete/reopen); a `can:periods.lock` group
                          for /close/* (show/acknowledge/integrity-check/lock/complete); `/reports/
                          accountant-package` (+ /download, additionally gated exports.run) inside the
                          existing reports.view group; `/settings/audit-log` inside the existing
                          audit.view group; and 7 new `/*/export.csv` routes (journal-entries,
                          contacts, invoices, expenses, bills, items, bank-accounts), each additionally
                          gated can:exports.run and — for invoices/expenses/bills/contacts — registered
                          BEFORE that resource's own `/{id}` wildcard route (same ordering hazard every
                          earlier `/edit`-before-`/{id}` comment in this file already documents).
routes/auth.php          Breeze auth routes.
routes/console.php       the ledger:verify schedule (unchanged, daily 03:00) PLUS Phase 6: a daily
                          02:00 `sparkbooks:backup` (withoutOverlapping — runs BEFORE ledger:verify so
                          a bad night's data is still backed up before the watchdog catches it) and a
                          daily 04:00 `sparkbooks:queue-heartbeat`. The backup/integrity heartbeat
                          pings themselves are NOT scheduled separately — they fire from inside
                          BackupDatabase/VerifyLedgerIntegrity's own handle() on success.
config/backup.php         Phase 6 T6/T7 (spec §11): mysqldump/mysql binary paths, `local_path`
                          (default storage/app/backups), `offhost_disk` name, retention.daily/weekly
                          (defaults 14/8) — every value env-gated, same Sentry-DSN "inert until
                          configured" pattern.
config/monitoring.php     Phase 6 T7 (spec §17.9): heartbeat URLs (backup/integrity/queue) +
                          alert_email + slack_webhook — every value defaults to null; Heartbeat/
                          AlertNotifier both no-op cleanly whenever the relevant value is unset.
config/filesystems.php    Phase 6 T6 added the `backups` disk — an S3-compatible off-host target for
                          sparkbooks:backup, config-gated on `bucket` being set (falls back to the
                          app's own AWS_* vars, with its own BACKUP_S3_* overrides for a
                          separate backups-only account).
composer.json             Phase 6 T6: added league/flysystem-aws-s3-v3 ^3.35 (the `backups` S3 disk's
                          driver package).
.env.example              Phase 6 T6/T7: added the backup (BACKUP_MYSQLDUMP_PATH/BACKUP_MYSQL_PATH/
                          BACKUP_S3_*/BACKUP_RETENTION_*) and monitoring (BACKUP_HEARTBEAT_URL/
                          INTEGRITY_HEARTBEAT_URL/QUEUE_HEARTBEAT_URL/MONITORING_ALERT_EMAIL/
                          MONITORING_SLACK_WEBHOOK) env keys, all blank by default — same
                          wired-but-inert pattern as the pre-existing SENTRY_LARAVEL_DSN line.
package.json              Phase 5 T2: added chart.js ^4.5.1 (bundled npm dep) — the Dashboard's
                          income-vs-expense chart is the app's only Chart.js surface.
vitest.config.ts          Phase 5 T2: added the `@` → resources/js/* alias (mirrors tsconfig) so a
                          component test (e.g. MoneyText.test.ts) can import via the same alias the
                          component itself uses internally.
```

---

## 9. Cross-cutting rules to never break (a checklist for future work)

1. Every tenant table has `company_id` NOT NULL + FK, accessed only via `BelongsToCompany`. Never put
   `company_id` in `$fillable`; never accept it from request input.
2. All ledger writes go through `LedgerService::post`. Nothing else writes the journal tables.
3. Money is integer minor units end-to-end. No float. Display via `MoneyText` (2 decimals forced).
4. Every mutating/settings/report route is gated by a `config/permissions.php` ability.
5. Every mutating action writes an `AuditLogger` row (creates/updates/posts/voids/locks/exports/switches).
6. Posted ledger entries are immutable; corrections are reversals.
7. Controllers resolve `{model}` via `Model::query()->findOrFail()` (tenant-scoped → foreign = 404),
   not implicit route-model binding (it would run before SetCompanyContext).
8. Debit/credit words appear only on accountant screens (JE editor, account drill-down, Trial Balance),
   never on general screens. UI uses only design tokens (ink/paper/rule/spark/lagoon/amber/clay + the
   reserved gradient).
9. Reports derive from `journal_lines`, never from document totals.
10. A contact-owning table's FK to `contacts` should be the **composite tenant FK** pattern
    (`(contact_id, company_id) → contacts(id, company_id)`), not a plain FK — established by
    `journal_lines.contact_id` (Phase 2 T1) and reused by `opening_documents` (Phase 2 T8). It
    proves referential integrity AND tenant ownership in one constraint.

---

## 10. Known carry-forward items (from reviews; not blocking)

**Cleared in Phase 3:**
- `Money::taxMinorUnits` negative/5-decimal guard (Task 2, ahead of invoicing needing it).
- `opening_documents` → `invoices` reconciliation path (Task 6: is_opening Invoice per customer
  opening receivable, `ReconcileOpeningInvoices` backfill for pre-existing ones).
- The spec §12.6 "real two-process concurrency test" exit criterion carried since Task 4 — closed
  Task 13 with `tests/Feature/InvoicePaymentConcurrencyTest.php` (genuinely separate OS processes/
  MySQL connections, a third "lock holder" process makes the contention window deterministic; see
  that file's class docblock for exactly what's proven vs. assumed).
- `NAV_ABILITIES` extended for the Invoices nav link (stays a hand-maintained subset — same reminder
  as before, just discharged for this phase's own new link).

**Cleared in Phase 2:** the `journal_lines.contact_id` real FK (was carried from Phase 1 — done in
Task 1, composite tenant FK). Integrity-checker source coverage extended to all Phase 2 source
types (Task 11).

**Still open (pre-existing, unchanged):**
- Collapse the double `CompanyContext::runFor` between `VerifyLedgerIntegrity` and `IntegrityChecker`
  (left intentionally in Task 11 — protects `HealthController::run`'s self-containment).
- Document the Owner/Accountant-vs-Admin ability split (Admin lacks ledger.post_manual/periods.lock/audit.view).
- CoA drill-down loads an account's whole line history in memory — fine at current volume, revisit before bulk import.
- Bulk query-builder ops bypass model immutability guards — flagged since Phase 1 as a "DB-trigger
  hardening candidate"; Phase 6 (the final phase) did not add one. No future phase remains to pick
  this up automatically — carry it as a genuine post-v1 hardening item if this project resumes work
  after launch.
- Minor polish list (amber stock-shade purge, focus-glow token, store/update spoofed-field asymmetry,
  a few missing UI regression tests) tracked in `.superpowers/sdd/progress.md`.

**New from Phase 2 (carried, still open — see Phase 3 items below for what Phase 4 should pick up):**
- Customers/vendors CSV import has a parser + tests + template (Phase 2 T9 built the type), but no
  UI upload path yet — only the three opening-balance CSV kinds (bank/customer-invoice/vendor-bill)
  are wired into a screen. Bulk contact import is a separate, not-yet-built flow.
- Opening-balance re-run reverses the prior entry dated at the **prior** entry_date — could hit
  `LockedPeriod` if the period lock moved between the original run and the re-run (low risk, untested).
- The opening-balances "locked" messaging and `books_start_date` share one field in the UI — revisit
  if they ever need to diverge.
- 2 archived-reject tests in Items don't assert `count === 0` (harmless, not tightened); redundant
  `company_id` index on `items`; 2 archived-reject tests aside, no other test-hygiene gaps flagged.

**New from Phase 3 (carry to Phase 4+):**
- **Discovered + fixed live in the Task 13 browser walkthrough:** paying a fresh opening invoice to
  Paid produced a false `base_ar_reconciliation` finding — `IntegrityChecker::
  actualLedgerBaseArByInvoice()` had no way to attribute an is_opening invoice's AR debit (posted
  once, by the ORIGINAL `opening_balance_run` entry, keyed by contact_id) back to that invoice's own
  id. Fixed same-task (contact_id-based attribution, safe because a contact can have at most one
  live is_opening invoice — see the method's docblock for the full reasoning) with 2 regression tests
  (`test_clean_when_an_opening_invoice_is_fully_paid`/`..._is_partially_paid`) that reproduce the
  exact false positive and would fail again if the attribution regresses. Underlying ledger was
  never actually wrong — verified independently via the whole-ledger net-zero assertion in the
  same tests and by the live Trial Balance during the walkthrough — this was purely a checker
  coverage gap, not a corruption. **Residual, deliberately not built:** the fix's safety argument
  depends on `OpeningBalanceService::voidPriorRun()`'s `hasRealTransaction` guard continuing to make
  "two live is_opening invoices for one contact" unreachable; if that guard is ever relaxed, this
  attribution would need to become opening_balance_run-id-scoped, not just contact_id-scoped.
- `Money::taxMinorUnits`'s new negative/5-decimal guard throws a generic exception on non-numeric
  input (e.g. `'abc'` → `ValueError`, not the same `InvalidArgumentException` family) — unreachable
  via HTTP today (upstream regex validation prevents it) but worth tightening if a non-HTTP caller
  is ever added (carried from Task 2's own review).
- ~~`IntegrityChecker::POSTED_INVOICE_STATUSES` duplicates `InvoiceStatus::isPosted()`~~ — **cleared
  Phase 4 T10**: replaced with a `postedInvoiceStatuses()` method derived from the enum directly (plus
  its new bill-side sibling `postedBillStatuses()` from `BillStatus::isPosted()`); no more
  hand-maintained duplicate list on either side.
- ~~Opening payables have no symmetric checker attribution~~ — **cleared Phase 4 T10**:
  `IntegrityChecker` now covers bills/bill_payments end to end (`document_balance` +
  `ap_reconciliation`), including the exact contact-level aggregate the fbbe763 AR fix established —
  a vendor with multiple opening bills in one run reconciles correctly — see §5's IntegrityChecker
  READ-side paragraph above (Phase 4 T10 entry) for the full contact-level reconciliation writeup.
- `NAV_ABILITIES` (HandleInertiaRequests) is a hand-maintained subset of `config/permissions.php` —
  remember to extend it whenever a future task gates a new nav link (Phase 3 itself needed this for
  Invoices — no drift yet, but it's manual every time).
- Contact type (customer/vendor/both) is enforced against `customer_lines`/`vendor_lines` at the
  opening-balance layer (tenant + archived + type all checked) — noted here only because it was a
  carried gap mid-Task-8 that got closed same-task; no outstanding gap.

**New from Phase 4 (carry to Phase 5+):**
- **LIVE BROWSER DRIVE of the purchases UI is PENDING.** Phase 4 was verified by the full test
  suite (894 PHP + 48 JS) + `ledger:verify` + curl-rendered Inertia pages (Trial Balance
  debits==credits on live data, Expenses/Show + Bills/Show render correctly), and Task 9's reviewer
  did an exhaustive control→route audit standing in for a browser (every action button traced to
  its route+method+idempotency, every gate-conditional render verified) — but the interactive
  browser tooling was broken for the whole session (claude-in-chrome frame-error on every tab/url),
  so a genuine human-style click-through of expense/bill record→pay→void, category enablement
  (P12/P13/P15), and attachment upload/spoofed-extension-rejection was never done. **Do this before
  the pre-handoff walkthrough** — it's the one Task 11 exit-criterion item not independently proven
  live, unlike every other Phase since Phase 0.
- **The client-side `todayIsoDate()` overdue computation is a standing PKR-timezone footgun** — a
  pre-existing convention from Invoices/Index (Phase 3, still uncorrected), now duplicated a second
  time for Bills (`isBillOverdue`/`todayIsoDate` in `lib/overdue.ts`, plus a third private copy
  inside `BillPaymentModal.tsx`): "today" is computed from the *browser's* local clock, not the
  server's. A viewer in a different timezone than the company's base currency's home timezone (PKR)
  sees a different overdue boundary than the server would compute. Not a Phase 4 regression — flag
  for a future server-date (or at least company-timezone-aware) fix across both invoice and bill
  overdue logic together, ideally consolidating all three copies into one.
- ~~The contact-level opening reconciliation pattern (fbbe763, originally an AR-only fix) is now
  proven on both AR and AP ... Phase 5's aging report will likely want the same reasoning — reuse
  the pattern rather than re-deriving it~~ — **cleared Phase 5 T4, adapted rather than reused
  verbatim**: aging needs a per-DOCUMENT split (each doc keeps its own due date) the contact-only
  `IntegrityChecker` pattern can't provide, so `AgingReport` instead treats an opening document's
  own contribution as its own `total` column, date-gated on its opening run's primary entry date
  (see `AgingReport`'s "THE OPENING-DOCUMENT EXCEPTION" docblock for the full reasoning) — same
  spirit, adapted shape.
- ~~Phase 5 aging will need to tie AR/AP agings to their control accounts (1100/2000) via each
  source document's own due date ... AR aging must define a fallback bucket for invoices with a
  null due_date before it is built~~ — **cleared Phase 5 T4**: built exactly as anticipated —
  `AgingReport`'s Current bucket holds both not-yet-due AND null-due-date documents (a document
  with no established due date has no deadline it could be judged "past" against), ties to
  1100/2000 via a per-document journal_lines reconstruction.
- `2000`/`2100` account lookups in ExpenseService/BillService/BillPaymentService are `firstOrFail`
  (a raw 500, not a friendly error, if either seed account were ever missing) — the exact same
  accepted risk as Phase 3's AR/Tax-Payable lookups (both accounts are seeded + non-deletable, so
  unreachable in practice today).
- `Money::taxMinorUnits`'s non-numeric-input guard (carried since Phase 3 Task 2) now has MORE
  callers reaching it indirectly — `DocumentLineCalculator::compute()` (Expense/Bill lines) joins
  `InvoiceCalculator` — but is still unreachable via HTTP (upstream regex/integer validation on
  `lines.*.amount`/`tax_rate_id` prevents a non-numeric rate from ever reaching it either way).
- `NAV_ABILITIES` needed extending again this phase (6 new entries: expenses.draft/record/void,
  bills.draft/receive/void) — still a hand-maintained subset of `config/permissions.php`, still
  manual every time a future task gates a new nav link or action-bar button.

**New from Phase 5 (carry to Phase 6+):**
- **LIVE BROWSER DRIVE is PENDING for Phase 5 too** — the same standing gap as the Phase 4 item
  above, not closed this phase either: interactive browser tooling was broken again this session.
  Phase 5 was verified via the full test suite (1057 PHP + 61 JS) + `ledger:verify` + curl-rendered
  Inertia pages for every report and the dashboard — including a real-data Balance Sheet as-of
  check that is what actually CAUGHT the pre-books-start Retained-Earnings bug (fixed same-phase,
  Task 3 review fix; see `BalanceSheetReport::resolveSinceDate()`'s docblock) — but a genuine
  human-style click-through (period pickers, CSV downloads, print, the dashboard's chart rendering)
  was never done. Do this before the pre-handoff walkthrough, same as the still-open Phase 4 item.
- `OtherReceiptService`'s category guard (Phase 2 T7) does not exclude the Tax subtype (2100) from
  eligible "category" accounts for an other-receipt — a receipt shouldn't semantically be
  categorizable as a tax payment. `TaxSummaryReport`'s residual "Adjustments" bucket is now
  STRUCTURALLY robust to this (any such receipt just falls into adjustments and the tie-out still
  holds), so it's no longer a correctness bug for Phase 5's own report — but the semantic guard gap
  itself is still open and pre-existing from Phase 4. Fix candidate for whichever future phase
  tightens `IneligibleCategoryAccount`.
- `GeneralLedgerReport::sourceLabel()` renders "{Type} #{id}" (e.g. "Invoice #42") using the
  document's OWN id, not its user-facing reference number (invoice number, a bill's own vendor
  reference, etc.) — deliberate (a per-source_type join wasn't judged worth it for a label whose
  job is just "what kind of thing posted this"), but worth revisiting if a future phase wants the
  GL to read more like a bank/vendor statement.
- All-accounts General Ledger (no `account` filter) and any big-period report load every matching
  `journal_lines` row into memory in one query — the same pre-existing volume caveat as
  `AccountController::show`'s own drill-down (§10, carried since Phase 1); fine at current volume,
  revisit together before a bulk-history company hits either path.
- No dark mode anywhere in the app, Phase 5 included — `IncomeExpenseChart.tsx`'s Chart.js colors
  are hardcoded light-mode token hex values (a `<canvas>` can't resolve Tailwind classes/CSS custom
  properties), so a future dark-mode pass would need to re-render the chart, not just re-theme CSS.
- ~~Phase 6 (reconciliation, month-end close, exports, backups) has three natural hooks already
  built this phase~~ — **Phase 6 built two of the three exactly as anticipated, the third
  differently on purpose**: `ReportPeriod`'s fiscal-year math IS the anchor both
  `MonthEndCloseController::exportRange` and `AccountantPackageController::resolveRange` use for
  their own default period; every Phase 6 export (`CsvExport`, `AccountantPackageService`) DOES
  reuse the same `Money::minorToDecimalString` convention, so there's still only one money-rendering
  helper to reconcile, not several. The Retained-Earnings-durability idea did NOT materialize as
  forecast, deliberately: month-end close (`MonthEndCloseService`) is a live-computed 7-step
  CHECKLIST layered over existing machinery (spec §17.5's own framing), not a persistence layer over
  `BalanceSheetReport`'s roll-up — Retained Earnings is still recomputed live on every request,
  unchanged since Phase 5. The close's own "irreversible-ish" act is still just moving
  `Company.books_locked_through` (unchanged since Phase 1's `LedgerService` enforcement), not a new
  frozen-figures table.

**New from Phase 6 (FINAL) — v1 COMPLETE. Everything below is launch-track / deploy-time
configuration, not a missing feature — see the spec's own §14 pre-trial checklist / §17
launch-track list for the authoritative source:**
- **LIVE BROWSER DRIVE is STILL PENDING** — now carried across three phases (4, 5, 6) without ever
  being closed: interactive browser tooling status this phase is unverified from this doc-update
  pass (this update was a documentation task, not a build/verify task). Phase 6 was verified via the
  full test suite (1192 PHP + 61 JS, confirmed green by re-running both suites for this update) +
  the existing `ledger:verify`/`HealthController` machinery + the rehearsed `docs/RESTORE.md`
  procedure (a REAL end-to-end restore, the closest thing to a live-system proof any phase has run)
  — but a genuine human-style click-through of reconciliation (tick lines, watch the difference
  converge, Finish enabling at 0, reopen newest-only), the month-end close checklist's own UI flow,
  the audit-log viewer's filters, and the accountant-package download was never independently
  confirmed live in a browser. **Do this before the real pre-trial walkthrough** — it is the one
  standing exit-criterion item not independently proven live since Phase 3.
- **2FA** (spec §17.8 explicitly defers it to the launch track) — not built; still single-factor auth
  (Breeze) for every role including Owner.
- **Custom roles / permission editing** — the 4 fixed roles (Owner/Admin/Accountant/Staff) and their
  `config/permissions.php` matrix are still hard-coded; there is no UI or mechanism to define a new
  role or hand-edit an existing one's ability list.
- **Real accountant Gate B review** — the ARTIFACTS a real accountant would need now exist end-to-end
  (Accountant Package one-click zip, audit-log viewer, every Phase 5 report + CSV export, the
  month-end close checklist) — what hasn't happened is an actual external accountant sitting down
  with them and signing off. This is a human/process step the codebase can't close by itself.
- **Hosting decision** — not yet made; nothing in this codebase assumes a specific host, but the
  DEPLOY-CONFIG items immediately below are all host-dependent and can't be finalized without one.
- **DEPLOY-CONFIG checklist** (environment/ops setup, not code — everything below is already wired
  to a config-gated env var that defaults to inert, same Sentry-DSN pattern used everywhere else in
  this app):
  - Production PHP needs the **`zip` extension (ZipArchive)** enabled — both
    `AccountantPackageService::build` and `BackupDatabase::buildArchive` require it; this dev
    environment already has it (both `AccountantPackageTest` and `BackupTest` pass here), but a
    fresh production PHP install may not.
  - Set the **`BACKUP_S3_*` env vars** (`BACKUP_S3_BUCKET`/`_ACCESS_KEY_ID`/`_SECRET_ACCESS_KEY`/
    `_REGION`/`_ENDPOINT`) so `sparkbooks:backup`'s off-host copy actually runs — today it's
    local-artifact-only (the `backups` disk skips cleanly with no bucket set, see
    `BackupDatabase::copyOffHost`).
  - Create real **healthchecks.io (or equivalent) heartbeat URLs** and set `BACKUP_HEARTBEAT_URL` /
    `INTEGRITY_HEARTBEAT_URL` / `QUEUE_HEARTBEAT_URL` — with none set, `Heartbeat::ping` is a
    permanent no-op and the dead-man's-switch protects nothing yet.
  - Set **`MONITORING_ALERT_EMAIL`** and/or **`MONITORING_SLACK_WEBHOOK`** — with neither set,
    `AlertNotifier::alert` only ever logs a warning; a real backup failure or `ledger:verify`
    finding would currently be silent in production unless someone is tailing logs.
  - On a shared host, prefer passing the mysqldump/mysql password via the **`MYSQL_PWD` environment
    variable** rather than `BackupDatabase`/`RestoreDatabase`'s current `--password=<secret>` CLI
    argument — `SecretScrubber` redacts the password from LOG/ALERT text, but the live OS process
    table (`ps`/task manager) still briefly shows the full command line with the CLI-arg approach,
    which `MYSQL_PWD` avoids entirely. Not fixed in code this phase — a deploy-time invocation
    choice, since `config/backup.php`'s `mysqldump_path`/`mysql_path` are the only pieces wired to
    override the binary, not its argv shape.
  - Add **`--no-tablespaces`** to `BackupDatabase::dumpDatabase`'s mysqldump argv if the production
    DB user lacks the `PROCESS` privilege (common on restricted shared-hosting MySQL accounts) —
    without it, mysqldump can fail outright trying to read tablespace metadata it has no permission
    to see. Not needed against this dev environment's root account; flag before pointing
    `sparkbooks:backup` at a locked-down production DB user.

**New from Phase 7 (accountant essentials) — carry to Phase 8+:**

Deferred features (not built this phase, not regressions — later phases per the roadmap):
classes/segments (P9), payroll (P10–12), WHT/PST + estimates/POs/recurring transactions/batch
operations/sub-items/credit notes (all P8), UI customization (P13). None of these were in Phase 7's
scope; nothing here narrows or blocks them.

One open, non-blocking note carried directly from review (P7-T4, `CashFlowReport`):
- **Income-side disposal add-back is untested** — there is no fixed-asset-disposal feature yet (that
  lands with the assets/depreciation work in P10–12), so `CashFlowReport`'s indirect-method handling
  of a gain/loss ON DISPOSAL showing up on the income side has no scenario to exercise it today. Not
  a known bug — just an assertion this phase couldn't write because the triggering feature doesn't
  exist. Revisit once P10–12 ships disposals.

**RESOLVED by Phase 7 Task 8** (the whole-branch reviewer's CRITICAL `cf_class` finding — see
`.superpowers/sdd/task-8-cfclass-fix-report.md`):
- ~~No `cf_class` edit UI~~ — an accountant can now reclassify any non-cash/bank account's `cf_class`
  directly from the Chart of Accounts' own "Edit account" dialog (`UpdateAccountRequest` validates it,
  `AccountController::update` persists it). A Cash/Bank subtype account's control is hidden (always
  shows "Cash — every cash/bank account is always part of the cash pool") since `Account::booted()`'s
  `saving` hook forces it structurally anyway.
- ~~`createChild` does NOT inherit the parent's `cf_class`~~ — it now does (falling back to
  `CashFlowClass::defaultFor()` off the child's own subtype/code only when the parent has none), and
  `AccountService::makeParent()`'s auto-created "— Other" sibling inherits the promoted leaf's
  `cf_class` the same way. More fundamentally: NO account of any subtype can be created or saved with
  a NULL `cf_class` anymore, from ANY path (`BankAccountService::createOnce`, `AccountController::
  store`, `AccountService::createChild`, `ChartOfAccountsSeeder`, `AccountFactory`, future code) —
  `Account::booted()`'s `saving` hook is the structural, no-bypass fix for the bug this whole section
  used to describe (a runtime-created account silently falling out of the Statement of Cash Flows'
  cash pool).

Other minor carries (all noted directly by task reviewers, none blocking):
- `AccountService::moveSubtree` (Task 1) has no UI yet — service-only, reachable only via a future
  screen or tinker.
- Trial Balance's CSV export emits a parent (header) subtotal row un-indented (the on-screen tree
  view IS indented) — cosmetic only, the number itself is correct.
- `CashFlowReport`'s FX-exclude behavior (routing 4950/5950 to their own "Effect of FX on cash" line
  rather than Operating) currently relies on the SEEDER having set their `cf_class` correctly — the
  report's own assertion (3) is the backstop that would catch a seeder drift, but there's no second,
  independent guard.
- No credit-note document type exists yet (same gap `AgingReport` already had, Phase 5) — Collections/
  OpenInvoices/UnpaidBills have nothing to net against a credit note because the feature itself
  isn't built (P8).
- `FullDataExportService`'s 9-CSV export deliberately excludes `invoice_lines`/`bill_lines` (the
  posted `journal_lines` already carries full-fidelity postings) — an additive follow-up, not a gap,
  if a future need wants document-level line detail alongside the ledger-level export.
- **LIVE BROWSER DRIVE is STILL PENDING** — now carried across four phases (4, 5, 6, 7) without ever
  being closed. Phase 7's own exit verification (this update) used the sanctioned curl-login +
  X-XSRF-TOKEN recipe against the dev server to confirm every new report/settings page renders a
  real Inertia component with real report data (HTTP 200, correct `component`/`props` shape) — not a
  genuine human-style click-through (tree-view drag/promote/archive interactions, the SCF's period
  picker, drill-down round-trips, the customer/vendor statement PDF download, the Backup/Export
  screen's two download buttons). **Do this before the real pre-trial walkthrough** — still the one
  standing exit-criterion item not independently proven live since Phase 3.
