# SparkBooks — Project Map

> **This is the living map of the whole codebase.** It exists so that no matter how much
> conversation context is lost between sessions, anyone (human or AI) can open this one file
> and understand what exists, how it connects, and what is not built yet.
>
> **Maintenance rule:** update this file at the end of every phase (and whenever a new
> service/controller/screen/table is added). It is committed to git. If it ever disagrees with
> the code, the code wins — fix this file.
>
> Last updated: **end of Phase 2** (contacts, items, tax rates, bank/cash accounts,
> transfers, other receipts, opening balances + CSV templates, nav/dashboard, integrity-checker
> extension — merged on branch `phase-2-contacts-bank`, exit-verified Task 12).
> Companion docs: the binding spec is [`superpowers/specs/2026-07-08-accounting-software-v1-design.md`](superpowers/specs/2026-07-08-accounting-software-v1-design.md);
> phase plans live in [`superpowers/plans/`](superpowers/plans/); per-task build/review evidence
> is in `.superpowers/sdd/` (git-ignored scratch — read `progress.md` there when resuming).

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
| Multi-currency — **foundation only**: FX Gain/Loss accounts (4950/5950) seeded + `§17.1` currency decision recorded. Full FX-lite invoicing posting (P1fx/P2fx) is Phase 3 | ✅ foundation built | 2 |
| Nav (Overview/Sales/Books/Reports/Settings) + dashboard (cash position + counts) | ✅ built | 2 |
| Integrity checker: source-type-aware (reversals never false-flagged; unrecognized source_type fails loud) | ✅ extended | 2 |
| Invoicing (money in) | ⛔ not built | 3 |
| Expenses & bills (money out) | ⛔ not built | 4 |
| P&L, Balance Sheet, agings, GL, tax summary, dashboard | ⛔ not built | 5 |
| Reconciliation, month-end close, exports, backups | ⛔ not built | 6 |

Tests: **396 PHP + 23 JS**, all green on real MySQL. No float on any money path (CI-enforced).

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
    OpeningCsvController ─▶ OpeningCsvParser ── STAGES ONLY, never posts (no LedgerService/OpeningBalanceService
                                                 call); Money::parseToMinor, all-or-nothing, line-numbered errors
                              also serves the §17.7 CSV templates (bank/customer-invoice/vendor-bill kinds)

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
  tie/neighbor cases). Carry to Phase 3: add a loud guard for negative/5-decimal inputs (currently
  silently truncates; upstream validation prevents it today, invoicing must not rely on that).
- **Display always forces 2 decimals** (`money.ts::formatMoney` pins `min/maxFractionDigits: 2`), so
  a value renders identically in every browser regardless of locale/ICU. Money is always shown via
  `Components/ui/MoneyText.tsx` (Spline Sans Mono, tabular-nums, right-aligned).
- **Currency — §17.1 decision recorded, foundation seeded (Phase 2), FX posting still Phase 3:**
  base currency per company (PKR for SparkingAsia), plus a USD option. Schema is already
  multi-currency-native (spec §3). The **FX Gain (4950) / FX Loss (5950) accounts now exist** on
  every company — seeded for new companies by `ChartOfAccountsSeeder` (25-account chart as of
  Phase 2) and backfilled onto pre-existing dev companies via
  `php artisan sparkbooks:backfill-fx-accounts` (idempotent; run once at Phase 2 exit, Task 12). No
  foreign-currency postings occur yet: transfers/other-receipts/opening balances in Phase 2 are all
  base-currency, so no FX gain/loss has arisen in practice — the accounts exist but are at Rs 0.00.
  The actual FX-lite posting rules (P1fx/P2fx, spec §17.1 Path 2) are exercised by invoicing in
  Phase 3; ledger lines will continue to store base-currency amounts only.

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
Exceptions/
  MissingCompanyContext.php   thrown when a tenant query runs with no company set (fail-closed).
  Ledger/LedgerException.php  base for all ledger errors; + UnbalancedEntry, EmptyEntry,
                              LockedPeriod, ControlAccountViolation, ArchivedAccount, ImmutableLedger.
  IneligibleCategoryAccount.php  Phase 2 Task 7: OtherReceiptService rejects bank/cash-linked or
                              control (AR/AP) accounts as a receipt "category" — caller-independent.
  OpeningBalancesLocked.php   Phase 2 Task 8: blocks a second opening-balance run once any OTHER
                              (non-'opening_balance_run') JournalEntry exists for the company.
Http/Controllers/
  DashboardController.php       /dashboard — cash position (sum of non-archived bank/cash accounts'
                                linked ledger balances via AccountBalances) + customer/vendor/bank-
                                account counts (tenant-scoped, read-only). Final Phase 2 nav shape
                                (Overview/Sales/Books/Reports/Settings) wired in Task 10; real report
                                widgets are still Phase 5.
  OnboardingController.php     create company → CompanySetupService.
  CompanySwitchController.php  switch current company (audit-logged, tenant-checked → 404).
  AccountController.php         CoA index/show(drill-down)/store/update/archive/unarchive. Reads AccountBalances.
  ManualJournalController.php   journal list / new / store / reverse. → ManualJournalService.
  PeriodLockController.php      /settings/books view + set lock date.
  Reports/TrialBalanceController.php  trial balance view + CSV export (integer→decimal string).
  HealthController.php          /settings/health — latest IntegrityRun + "Run now".
  ProfileController.php, Auth/* Breeze auth + profile (Phase 0, restyled Phase 1).
  ContactController.php         Phase 2 T1: contacts index (type filter) /store/update/archive/
                                unarchive/show(drill-down: outstanding balance from JournalLine).
  TaxRateController.php         Phase 2 T3: tax rates CRUD. tax_rates.manage gate (pre-existed).
  ItemController.php            Phase 2 T2: items catalog CRUD + archive/unarchive. items.manage gate.
  BankAccountController.php     Phase 2 T5: bank/cash account index/store/update/archive/unarchive.
                                → BankAccountService for linked-CoA-account creation.
  TransferController.php        Phase 2 T6: /transfers/new + store. → TransferService (P11).
  OtherReceiptController.php    Phase 2 T7: /other-receipts/new + store. → OtherReceiptService (P14).
  OpeningBalanceController.php  Phase 2 T8: /setup/opening-balances create/store (P8a-c, re-run).
                                → OpeningBalanceService. settings.manage gate (Owner+Admin only —
                                Accountant explicitly excluded here, unlike most Phase-2 gates).
  OpeningCsvController.php       Phase 2 T9: CSV template download (§17.7) + import (stage-only,
                                → OpeningCsvParser, never posts).
Http/Middleware/
  SetCompanyContext.php        resolves current company → CompanyContext; repairs stale/foreign id.
  RequireCompany.php           redirects users with no company to onboarding.
  HandleInertiaRequests.php    shares auth.user, companies, currentCompany, can (CLOSURES) to React.
                                `can` is a minimal ability map (config/permissions.php subset, incl.
                                NAV_ABILITIES — Phase 2 T10, hand-maintained, extend when a new nav
                                link needs gating) the sidebar nav uses to decide what to render —
                                every linked route still has its own server-side gate.
Http/Requests/
  StoreCompanyRequest, StoreAccountRequest, UpdateAccountRequest, StoreManualJournalRequest,
  ProfileUpdateRequest, Auth/LoginRequest   — form validation.
  StoreContactRequest, UpdateContactRequest, StoreTaxRateRequest, UpdateTaxRateRequest,
  StoreItemRequest, UpdateItemRequest, StoreBankAccountRequest, UpdateBankAccountRequest,
  StoreTransferRequest, StoreOtherReceiptRequest, StoreOpeningBalanceRequest   — Phase 2 form validation.
Models/
  Company.php                  tenant root: name, base_currency, fiscal_year_start_month, books_locked_through.
  User.php                     + companies() pivot (role), current_company_id, roleIn() (cached).
  Concerns/BelongsToCompany.php  trait: global company scope + auto-fill company_id. NEVER put company_id in $fillable.
  Account.php                  chart-of-accounts row; system-account guards; isControlAccount().
  JournalEntry.php, JournalLine.php   the ledger. Immutable. Written ONLY by LedgerService.
                                journal_lines.contact_id now has a composite tenant FK →
                                contacts(id, company_id) (Phase 2 T1; was non-null-only in Phase 1).
  ManualJournal.php            source document for a manual JE.
  ActivityLog.php              append-only audit rows.
  IdempotencyKey.php           (company_id,key) unique; stores replay snapshot.
  IntegrityRun.php             persisted integrity-check result (clean? findings json).
  Contact.php                  Phase 2 T1: customer/vendor/both; name/email/phone/tax_number/
                                billing_address/notes; archivable.
  TaxRate.php                  Phase 2 T3: name + rate (basis points, integer).
  Item.php                     Phase 2 T2: name/unit_price(minor units)/default_income_account_id/
                                default_tax_rate_id (both nullable, tenant+type validated); archivable.
  BankAccount.php               Phase 2 T5: bank|cash, currency (=base, enforced), linked Account
                                (auto-created 10xx code); archivable (doesn't cascade-archive the CoA row).
  Transfer.php                  Phase 2 T6: source document for a P11 transfer (from/to BankAccount, amount).
  OtherReceipt.php               Phase 2 T7: source document for a P14 receipt (bank, category account, payer).
  OpeningBalanceRun.php         Phase 2 T8: one row per opening-balance setup/re-run; status active|void.
  OpeningDocument.php            Phase 2 T8: per-contact opening AR/AP line (reference/original_date/
                                due_date/amount/status); composite tenant FK on contact_id+company_id
                                (contact_id NOT NULL, unlike journal_lines); feeds Phase 5 aging.
Providers/AppServiceProvider.php  registers Gates from config/permissions.php; scoped bindings.
Services/
  CompanySetupService.php      create company, attach Owner, seed defaults.
  ChartOfAccountsSeeder.php    seeds the §5 chart into a new company — now **25 accounts** (Phase 2
                                Task 4 added 4950 FX Gain / 5950 FX Loss); exposes fxAccounts() for
                                the backfill command.
  LedgerService.php            ⭐ THE GATE (see §6). Unchanged in Phase 2 — every new write-side
                                service posts through it.
  ManualJournalService.php     manual JE post/reverse (LedgerService + Idempotency + Audit).
  AccountBalances.php          signed per-account balances from journal_lines (read).
  IntegrityChecker.php         source-type-aware checks per company (read) — see §5 READ side note.
  Reports/TrialBalanceReport.php  trial balance from journal_lines (read).
  BankAccountService.php        Phase 2 T5: creates a BankAccount + its linked 10xx Account together;
                                concurrency-safe code allocation (lockForUpdate + 3-retry).
  TransferService.php           Phase 2 T6: P11 posting + guards (from≠to, amount≥1, tenant+archived,
                                locked period) — "nothing posted" on any guard failure.
  OtherReceiptService.php       Phase 2 T7: P14 posting + IneligibleCategoryAccount guard.
  OpeningBalanceService.php     Phase 2 T8: ⭐ P8a-c combined-entry posting + re-run void-then-repost;
                                OpeningBalancesLocked guard distinguishes reversals from real activity.
  OpeningCsvParser.php           Phase 2 T9: CSV → staged rows for bank/customer-invoice/vendor-bill
                                kinds; Money::parseToMinor; NEVER calls LedgerService/OpeningBalanceService.
Support/
  CompanyContext.php           request-scoped current company; get() throws if unset.
  IdempotencyService.php       exactly-once execute() wrapper (baseline txn guard).
  AuditLogger.php              writes ActivityLog rows.
  Currencies.php               allowed ISO currency list (PKR, USD, …).
  Money.php                     Phase 2 T3: parseToMinor (string→minor units, no float, mirrors
                                money.ts) + taxMinorUnits (bcmath half-up tax rounding).
```

### Frontend — `resources/js/`

```
lib/
  money.ts (+ money.test.ts)   formatMoney (forced 2dp) + parseMoneyInput (string→minor units). No float.
  contactLabels.ts              Phase 2: shared customer/vendor/both label helpers (Contacts + opening
                                balances both use these — hoisted to avoid duplication).
Components/ui/                  Button, Sheet, Field, MoneyText, StatTile — design-system primitives.
                                StatTile added Phase 2 T10 for the dashboard's 3 stat cards.
Components/ledger/
  BalanceBeam.tsx              ⭐ signature element: tilts to the heavier side, settles level + glows when balanced.
  LineEditor.tsx               the debit/credit line grid for the JE editor.
Components/shell/               Wordmark, SidebarLink (gained `activeMatch` in Phase 2 T10 so e.g.
                                Banking stays lit while on Transfers/Other-receipts), CompanyMenu,
                                UserMenu — the app-shell chrome.
Components/ (Breeze originals)  Modal, Dropdown, TextInput, InputError, … (still used by auth/profile forms).
Layouts/
  AppShell.tsx                 ⭐ the ink-sidebar shell wrapping every in-app page. Phase 2 T10: filters
                                nav items by the shared `can` map and drops empty groups.
  GuestLayout.tsx              centered card for auth pages. AuthenticatedLayout.tsx = Breeze leftover (unused).
Pages/
  Dashboard.tsx                cash position + customer/vendor/bank-account StatTile cards (Phase 2
                                T10, real DashboardController data); friendly empty-state hint when
                                there's nothing yet. Real report widgets are still Phase 5.
  Onboarding/CreateCompany.tsx company setup form.
  Accounts/Index.tsx, Show.tsx chart of accounts + account ledger drill-down.
  Journal/New.tsx, Index.tsx   ⭐ manual JE editor (BalanceBeam home) + journal list.
  Reports/TrialBalance.tsx     trial balance report + CSV export button.
  Settings/Books.tsx, Health.tsx  period lock + integrity health.
  Settings/TaxRates.tsx         Phase 2 T3: tax rates CRUD screen.
  Contacts/Index.tsx            Phase 2 T1: contacts list, type filter (All/Customers/Vendors),
                                show-archived toggle, "New contact" modal.
  Contacts/Show.tsx             Phase 2 T1: contact drill-down — outstanding balance + activity feed.
  Items/Index.tsx                Phase 2 T2: items catalog CRUD screen.
  Banking/Index.tsx             Phase 2 T5: bank/cash accounts list + "Record receipt"/"Transfer"/
                                "New account" actions, per-account balance.
  Banking/Transfer.tsx           Phase 2 T6: transfer-between-accounts form.
  Banking/OtherReceipt.tsx       Phase 2 T7: record-a-receipt form (bank, category, amount, payer).
  Setup/OpeningBalances.tsx     Phase 2 T8-T9: ⭐ the opening-balances wizard — books start date,
                                bank balances, customer invoices, vendor bills (each section supports
                                add-row, CSV template download, CSV upload), live OBE-plug summary,
                                re-run ("Re-enter opening balances") after a successful run.
  Auth/*, Profile/*            Breeze auth + profile (restyled to tokens).
types/                         index.d.ts (shared Inertia props incl. currentCompany), global.d.ts.
app.tsx                        Inertia bootstrap; fonts; progress-bar color.
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
```

### Config / routes

```
config/permissions.php   the §7.14 catalog + per-role permission lists (the authorization source of truth).
routes/web.php           all in-app routes, each gated (see the gate on each group).
routes/auth.php          Breeze auth routes. routes/console.php  the ledger:verify schedule.
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

**Cleared in Phase 2:** the `journal_lines.contact_id` real FK (was carried from Phase 1 — done in
Task 1, composite tenant FK). Integrity-checker source coverage extended to all Phase 2 source
types (Task 11).

**Still open (pre-existing, unchanged):**
- Collapse the double `CompanyContext::runFor` between `VerifyLedgerIntegrity` and `IntegrityChecker`
  (left intentionally in Task 11 — protects `HealthController::run`'s self-containment).
- Document the Owner/Accountant-vs-Admin ability split (Admin lacks ledger.post_manual/periods.lock/audit.view).
- CoA drill-down loads an account's whole line history in memory — fine at current volume, revisit before bulk import.
- Bulk query-builder ops bypass model immutability guards — Phase 6 DB-trigger hardening candidate.
- Minor polish list (amber stock-shade purge, focus-glow token, store/update spoofed-field asymmetry,
  a few missing UI regression tests) tracked in `.superpowers/sdd/progress.md`.

**New from Phase 2 (carry to Phase 3+):**
- `Money::taxMinorUnits` needs a loud guard for negative/5-decimal inputs (currently silently
  truncates; safe today only because upstream validation prevents bad input — invoicing (Phase 3)
  must not rely on that same upstream guard holding).
- Decide the `opening_documents` → `invoices`/`bills` migration path before Phase 3 (invoicing) needs
  to reconcile an opening invoice/bill with a real one, or link payments against it.
- Customers/vendors CSV import has a parser + tests + template (Phase 2 T9 built the type), but no
  UI upload path yet — only the three opening-balance CSV kinds (bank/customer-invoice/vendor-bill)
  are wired into a screen. Bulk contact import is a separate, not-yet-built flow.
- Opening-balance re-run reverses the prior entry dated at the **prior** entry_date — could hit
  `LockedPeriod` if the period lock moved between the original run and the re-run (low risk, untested).
- The opening-balances "locked" messaging and `books_start_date` share one field in the UI — revisit
  if they ever need to diverge.
- `NAV_ABILITIES` (HandleInertiaRequests) is a hand-maintained subset of `config/permissions.php` —
  remember to extend it whenever a future task gates a new nav link.
- 2 archived-reject tests in Items don't assert `count === 0` (harmless, not tightened); redundant
  `company_id` index on `items`; 2 archived-reject tests aside, no other test-hygiene gaps flagged.
- Contact type (customer/vendor/both) is enforced against `customer_lines`/`vendor_lines` at the
  opening-balance layer (tenant + archived + type all checked) — noted here only because it was a
  carried gap mid-Task-8 that got closed same-task; no outstanding gap.
