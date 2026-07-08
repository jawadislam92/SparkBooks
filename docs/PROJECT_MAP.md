# SparkBooks — Project Map

> **This is the living map of the whole codebase.** It exists so that no matter how much
> conversation context is lost between sessions, anyone (human or AI) can open this one file
> and understand what exists, how it connects, and what is not built yet.
>
> **Maintenance rule:** update this file at the end of every phase (and whenever a new
> service/controller/screen/table is added). It is committed to git. If it ever disagrees with
> the code, the code wins — fix this file.
>
> Last updated: **end of Phase 1** (ledger core + design system merged to main).
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
| Contacts (customers/vendors) | ⛔ not built | 2 |
| Items catalog | ⛔ not built | 2 |
| Tax rates | ⛔ not built | 2 |
| Bank/cash accounts + transfers + other receipts | ⛔ not built | 2 |
| Opening balances (P8a–c) + CSV import templates | ⛔ not built | 2 |
| Multi-currency (USD/PKR FX-lite) | ⛔ not built | 2 |
| Invoicing (money in) | ⛔ not built | 3 |
| Expenses & bills (money out) | ⛔ not built | 4 |
| P&L, Balance Sheet, agings, GL, tax summary, dashboard | ⛔ not built | 5 |
| Reconciliation, month-end close, exports, backups | ⛔ not built | 6 |

Tests: **201 PHP + 23 JS**, all green on real MySQL. No float on any money path (CI-enforced).

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

  WRITE side (money in/out — Phase 1 has only manual journals):

    ManualJournalController ─▶ ManualJournalService ─┬─▶ IdempotencyService ─▶ IdempotencyKey (model)
                                                     ├─▶ LedgerService ──────▶ JournalEntry, JournalLine (models)
                                                     │        ▲                 (immutable; CHECK chk_one_sided;
                                                     │        │                  unique journal_entries_source_unique)
                                                     │   reads Account (control-account + archived rules,
                                                     │                  reads Company.books_locked_through for lock)
                                                     └─▶ AuditLogger ─────────▶ ActivityLog (model, append-only)

    LedgerService::reverse ── posts a mirror entry (entry_role=reversal) back through post()

  READ side (reports — no writes):

    AccountController ─────────▶ AccountBalances ────────┐
    Reports/TrialBalanceController ─▶ TrialBalanceReport ┤──▶ JournalLine (+ journalEntry for date)   [SUM(debit)-SUM(credit)]
    VerifyLedgerIntegrity (cmd) ─▶ IntegrityChecker ─────┘         + Account (subtype), ManualJournal (orphan check)
    HealthController ─────────────▶ IntegrityChecker ─▶ IntegrityRun (model, persisted result)

  SETUP / SUPPORT:

    OnboardingController ─▶ CompanySetupService ─▶ ChartOfAccountsSeeder ─▶ Account (seeds §5 chart)
                                     └─▶ AuditLogger
    PeriodLockController ─▶ Company.books_locked_through (+ AuditLogger)   [enforcement lives in LedgerService]
    CompanySwitchController ─▶ User.current_company_id (+ AuditLogger)
    AppServiceProvider ─▶ registers permission Gates from config/permissions.php; binds CompanyContext + IdempotencyService (scoped)
    HandleInertiaRequests ─▶ shares auth.user, companies, currentCompany, can (as closures) to every React page
    DashboardController ─▶ AccountBalances (cash position) + BankAccount/Contact counts (read-only)
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
- **Display always forces 2 decimals** (`money.ts::formatMoney` pins `min/maxFractionDigits: 2`), so
  a value renders identically in every browser regardless of locale/ICU. Money is always shown via
  `Components/ui/MoneyText.tsx` (Spline Sans Mono, tabular-nums, right-aligned).
- **Currency (Phase 2 work, decided now):** base currency per company (PKR for SparkingAsia), plus a
  USD option. Schema is already multi-currency-native (spec §3). Phase 2 builds the FX-lite slice
  (spec §17.1 Path 2): invoices may carry a foreign currency with an FX rate captured at send;
  payments capture the actual PKR received; the difference posts to FX Gain/Loss (4950/5950). The
  ledger always balances in base currency. Journal lines store base-currency amounts only.

---

## 8. Annotated file tree (hand-written source only — vendor/framework omitted)

### Backend — `app/`

```
Console/Commands/
  VerifyLedgerIntegrity.php   cron `ledger:verify` (daily 3am, routes/console.php). Iterates ALL
                              companies, runs IntegrityChecker, persists IntegrityRun, FAILS LOUD
                              (non-zero exit) on findings OR a vacuous/empty check.
Domain/Ledger/
  EntryDraft.php              readonly DTO: date, memo, source, lines[], role, reversesEntryId.
  LineDraft.php               readonly DTO: accountId, debit, credit, memo, contactId.
Enums/
  Role.php                    Owner|Admin|Accountant|Staff (+ permissions() from config).
  AccountType.php             Asset|Liability|Equity|Income|Expense (+ isDebitNormal()).
  AccountSubtype.php          cash/bank/receivable/payable/tax/… (+ type() → AccountType).
  EntryRole.php               primary|reversal.
Exceptions/
  MissingCompanyContext.php   thrown when a tenant query runs with no company set (fail-closed).
  Ledger/LedgerException.php  base for all ledger errors; + UnbalancedEntry, EmptyEntry,
                              LockedPeriod, ControlAccountViolation, ArchivedAccount, ImmutableLedger.
Http/Controllers/
  DashboardController.php       /dashboard — cash position (sum of non-archived
                                bank/cash accounts' linked ledger balances via
                                AccountBalances) + customer/vendor counts.
                                Phase 2 "light" pass; real widgets are Phase 5.
  OnboardingController.php     create company → CompanySetupService.
  CompanySwitchController.php  switch current company (audit-logged, tenant-checked → 404).
  AccountController.php         CoA index/show(drill-down)/store/update/archive/unarchive. Reads AccountBalances.
  ManualJournalController.php   journal list / new / store / reverse. → ManualJournalService.
  PeriodLockController.php      /settings/books view + set lock date.
  Reports/TrialBalanceController.php  trial balance view + CSV export (integer→decimal string).
  HealthController.php          /settings/health — latest IntegrityRun + "Run now".
  ProfileController.php, Auth/* Breeze auth + profile (Phase 0, restyled Phase 1).
Http/Middleware/
  SetCompanyContext.php        resolves current company → CompanyContext; repairs stale/foreign id.
  RequireCompany.php           redirects users with no company to onboarding.
  HandleInertiaRequests.php    shares auth.user, companies, currentCompany, can (CLOSURES) to React.
                                `can` is a minimal ability map (config/permissions.php
                                subset) the sidebar nav uses to decide what to render —
                                every linked route still has its own server-side gate.
Http/Requests/
  StoreCompanyRequest, StoreAccountRequest, UpdateAccountRequest, StoreManualJournalRequest,
  ProfileUpdateRequest, Auth/LoginRequest   — form validation.
Models/
  Company.php                  tenant root: name, base_currency, fiscal_year_start_month, books_locked_through.
  User.php                     + companies() pivot (role), current_company_id, roleIn() (cached).
  Concerns/BelongsToCompany.php  trait: global company scope + auto-fill company_id. NEVER put company_id in $fillable.
  Account.php                  chart-of-accounts row; system-account guards; isControlAccount().
  JournalEntry.php, JournalLine.php   the ledger. Immutable. Written ONLY by LedgerService.
  ManualJournal.php            source document for a manual JE.
  ActivityLog.php              append-only audit rows.
  IdempotencyKey.php           (company_id,key) unique; stores replay snapshot.
  IntegrityRun.php             persisted integrity-check result (clean? findings json).
Providers/AppServiceProvider.php  registers Gates from config/permissions.php; scoped bindings.
Services/
  CompanySetupService.php      create company, attach Owner, seed defaults.
  ChartOfAccountsSeeder.php    seeds the §5 23-account chart into a new company.
  LedgerService.php            ⭐ THE GATE (see §6).
  ManualJournalService.php     manual JE post/reverse (LedgerService + Idempotency + Audit).
  AccountBalances.php          signed per-account balances from journal_lines (read).
  IntegrityChecker.php         the 4 §9 checks per company (read).
  Reports/TrialBalanceReport.php  trial balance from journal_lines (read).
Support/
  CompanyContext.php           request-scoped current company; get() throws if unset.
  IdempotencyService.php       exactly-once execute() wrapper (baseline txn guard).
  AuditLogger.php              writes ActivityLog rows.
  Currencies.php               allowed ISO currency list (PKR, USD, …).
```

### Frontend — `resources/js/`

```
lib/
  money.ts (+ money.test.ts)   formatMoney (forced 2dp) + parseMoneyInput (string→minor units). No float.
Components/ui/                  Button, Sheet, Field, MoneyText, StatTile — design-system primitives.
Components/ledger/
  BalanceBeam.tsx              ⭐ signature element: tilts to the heavier side, settles level + glows when balanced.
  LineEditor.tsx               the debit/credit line grid for the JE editor.
Components/shell/               Wordmark, SidebarLink, CompanyMenu, UserMenu — the app-shell chrome.
Components/ (Breeze originals)  Modal, Dropdown, TextInput, InputError, … (still used by auth/profile forms).
Layouts/
  AppShell.tsx                 ⭐ the ink-sidebar shell wrapping every in-app page.
  GuestLayout.tsx              centered card for auth pages. AuthenticatedLayout.tsx = Breeze leftover (unused).
Pages/
  Dashboard.tsx                cash position + customer/vendor stat tiles (Phase 2 "light"
                                pass via DashboardController); friendly empty-state hint
                                when there's nothing yet. Real widgets are Phase 5.
  Onboarding/CreateCompany.tsx company setup form.
  Accounts/Index.tsx, Show.tsx chart of accounts + account ledger drill-down.
  Journal/New.tsx, Index.tsx   ⭐ manual JE editor (BalanceBeam home) + journal list.
  Reports/TrialBalance.tsx     trial balance report + CSV export button.
  Settings/Books.tsx, Health.tsx  period lock + integrity health.
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

---

## 10. Known carry-forward items (from reviews; not blocking)

- Add a real FK on `journal_lines.contact_id` once `contacts` exists (Phase 2) — checker currently
  verifies non-null only, not referential validity.
- Collapse the double `CompanyContext::runFor` between `VerifyLedgerIntegrity` and `IntegrityChecker`.
- Document the Owner/Accountant-vs-Admin ability split (Admin lacks ledger.post_manual/periods.lock/audit.view).
- CoA drill-down loads an account's whole line history in memory — fine at Phase 1 volume, revisit before bulk import.
- Bulk query-builder ops bypass model immutability guards — Phase 6 DB-trigger hardening candidate.
- Minor polish list (amber stock-shade purge, focus-glow token, store/update spoofed-field asymmetry,
  a few missing UI regression tests) tracked in `.superpowers/sdd/progress.md`.
```
