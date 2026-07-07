# Accounting Software — v1 Design

**Working name:** TBD (folder is currently `not sure`; must be renamed before scaffolding — paths with spaces break some frontend tooling)
**Date:** 2026-07-08 (rev 2 — 27 confirmed findings from adversarial review folded in)
**Status:** Pending written-spec review by Jawad
**First real user:** SparkingAsia (services company — agency work, no physical inventory)
**Long-term goal:** If the product proves itself internally, sell it as a multi-tenant SaaS (QuickBooks-class, built up in phases)

---

## 1. Purpose & Goals

Build a web-based accounting application that does what QuickBooks does — eventually. Version 1 is deliberately small but **architecturally and accountingly correct**: a real double-entry ledger underneath approachable screens (invoices, expenses, reports). Correctness is explicitly prioritized over speed of delivery.

**Success criteria for v1:**

1. SparkingAsia runs its real books on it for a full month. **Precondition (checked before Phase 3):** all billing in the trial month can be done in the base currency (PKR). If SparkingAsia must issue USD invoices in that month, a minimal FX slice is pulled forward (see §16.5) — this assumption is recorded here so it is a checked precondition, not a surprise at Gate B.
2. A hired accountant reviews the Trial Balance, General Ledger, and reports from that month and signs off that the books are correct (Gate B, §13).
3. Every test in the required test suite (§12) passes.
4. The books cannot be corrupted through the UI — by construction, not by discipline.

**Non-goals for v1:** payroll, bank feeds, inventory, country-specific tax filing, online payment collection, mobile/desktop apps.

---

## 2. Key Decisions (from design discussion)

| Decision | Choice | Why |
|---|---|---|
| Build vs. fork | Own build | Full ownership, sellable, no GPL/AGPL constraints; open-source apps (Akaunting, BigCapital) studied for schema ideas only |
| Market | Country-agnostic core | Double-entry is universal; country compliance (FBR, US sales tax, payroll) added later as localization packs |
| Stack | Laravel 12 + Inertia + React + TypeScript + Tailwind + MySQL | Same stack as SA Track / Tracksuite — zero new-framework risk |
| Tenancy | Single database, `company_id` on every tenant table, global scopes | Proven pattern in Tracksuite; simplest correct multi-tenancy |
| Money storage | Integers in minor units (paisa/cents) | Floats are how books stop balancing. Non-negotiable |
| Ledger visibility | Users never see debits/credits | QuickBooks' key product decision; ledger writes itself under invoice/expense screens. Accountant role and reports expose it for those who want it |
| Multi-currency | Schema and column semantics multi-currency-native from day one; single-currency behavior in v1 | SparkingAsia will need USD invoicing. The **schema** change later is additive; the **service/report/integrity logic** changes are enumerated now (§3) so they're planned, not discovered |
| Corrections | Immutable posted entries + reversals | Real accounting practice; structurally prevents silent-corruption bugs |

---

## 3. Architecture Overview

A fresh Laravel 12 application:

- **Backend:** Laravel 12, PHP 8.2+, MySQL. Domain logic in service classes (`LedgerService`, `InvoiceService`, …), thin controllers, form requests for validation, policies for authorization.
- **Frontend:** Inertia + React + TypeScript + Tailwind. Chart.js for dashboard/report charts (already familiar).
- **Environments:** local Laragon for development. Deployment target decided later (Hostinger pattern is known from SA Track). Nothing in v1 assumes a specific host.

### Multi-tenancy & company context

- Every signup creates a **Company**. Every tenant-owned table has `company_id` (indexed, FK). Users may belong to multiple companies via a `company_user` pivot (role per company) — one login can be Staff in one company and Accountant in another.
- **Company context is resolved through a dedicated `CompanyContext` service, never `auth()` directly.** For HTTP requests it reads `users.current_company_id` (a real column, §8), settable via a company-switcher endpoint that verifies membership. A `BelongsToCompany` trait applies a global scope from `CompanyContext`.
- **Non-HTTP contexts set context explicitly:** queued jobs (PDF emails, backups) carry `company_id` in their payload and set `CompanyContext` in `handle()`. Artisan commands (integrity checker, seeding) iterate companies explicitly with the global scope bypassed (`withoutGlobalScope`) or by setting context per company. The integrity checker **fails loudly** (non-zero exit + alert) if it ever evaluates zero companies, or zero journal entries for a company that has posted documents — a vacuously-green correctness tool is the worst failure mode.

### Money representation

- All monetary values stored as **BIGINT minor units** (e.g., PKR 1,500.50 → `150050`).
- No floats anywhere in the money path — not in PHP, not in JS, not in SQL. Percentages (tax rates, FX rates) are stored as `DECIMAL` and applied with integer-safe rounding (§7.6; §12 test 10).
- Each company has a `base_currency` (ISO 4217 code) chosen at setup. **The ledger always balances in base currency.**

### Multi-currency readiness — what is ready now vs. what changes later

Pinned column semantics (from day one, even while everything is single-currency):

- Source documents (invoices, bills, expenses, payments) carry `currency_code`, `fx_rate` (rate to base at transaction time, DECIMAL(16,8)), **document-currency** amounts (`subtotal`, `tax_total`, `total`, `amount_paid`, `balance`) **and** base-currency mirrors (`base_subtotal`, `base_total`, …). In v1, `fx_rate = 1.0` and the pairs are equal — but every consumer is written against the pinned semantics from the start.
- `bank_accounts` carry `currency_code` (constrained to base currency in v1).
- Journal lines store **base-currency amounts only**.

Honestly stated: enabling multi-currency later is an **additive schema migration plus enumerated logic changes** — payment application in document currency with base posting, a settlement rounding rule that relieves AR to exactly zero in base, an FX Gain/Loss account, and splitting integrity check (c) into a document-currency check and a base-currency tie-out that tolerates FX postings (§9). These are planned changes, not a restructuring — but they are logic, not just columns.

---

## 4. The Ledger Core

Three tables everything else sits on:

### `accounts` (Chart of Accounts)

| Column | Notes |
|---|---|
| id, company_id | |
| code | e.g. `1100`; unique per company |
| name | e.g. "Accounts Receivable" |
| type | `asset` \| `liability` \| `equity` \| `income` \| `expense` |
| subtype | e.g. `bank`, `cash`, `receivable`, `payable`, `tax`, `fixed_asset`, `operating_expense`, … (drives report grouping and UI filtering) |
| is_system | System accounts (AR, AP, Tax Payable, Opening Balance Equity, …) cannot be deleted or retyped; name edits allowed |
| is_archived | Archived accounts are hidden from pickers; history remains |
| description | optional |

### `journal_entries`

| Column | Notes |
|---|---|
| id, company_id | |
| entry_date | The accounting date (not the created-at timestamp) |
| memo | Human description |
| source_type, source_id | Polymorphic link to the document that created it (invoice, payment, expense, bill, bill_payment, transfer, other_receipt, opening_balance, manual, reversal) |
| status | `posted` only in v1 (drafts live on documents, not in the ledger) |
| reverses_entry_id | Set on reversal entries; links to the entry being reversed |
| created_by, posted_at | |

### `journal_lines`

| Column | Notes |
|---|---|
| id, journal_entry_id, company_id | company_id denormalized for tenancy scoping & report queries |
| account_id | |
| debit, credit | BIGINT minor units, base currency; exactly one of the two is > 0, the other is 0 |
| memo | optional per-line |
| contact_id | **Required on every line touching an AR/AP control account** (enforced by `LedgerService`), so subledgers (aging, contact balances) derive from ledger lines and always tie to the control accounts |

### The iron rules

1. **Balance invariant:** within every journal entry, `SUM(debit) == SUM(credit)`. Enforced in `LedgerService` before commit; violating entries are rejected with an exception. A scheduled integrity command re-verifies the whole ledger (§9).
2. **One gate in:** `LedgerService::post(EntryDraft $draft): JournalEntry` is the **only** code path that writes `journal_entries` / `journal_lines`. All modules build an `EntryDraft` (date, memo, source, lines) and call it. Runs inside a DB transaction with the source-document write, so a document and its ledger entry commit or roll back together. `LedgerService` also enforces: contact_id present on control-account lines; manual-source entries may not touch control accounts (§7.10).
3. **Immutability:** posted entries are never updated or deleted. Eloquent model guards throw on `update()`/`delete()`; there are no HTTP endpoints that touch them. Corrections are **reversal entries** (mirror-image lines, `reverses_entry_id` set) plus, where relevant, a fresh corrected entry.
4. **Period locks:** each company has `books_locked_through` (nullable date). `LedgerService` rejects any posting (including reversals) with `entry_date <= books_locked_through`. Only roles holding `periods.lock` (§7.14: Owner, Accountant) may move the lock date, and moving it is audit-logged. Voiding a document dated inside a locked period requires a reversal dated in the open period (the UI defaults to today and explains why).
5. **No soft deletes in the ledger.** Documents that have posted are voidable, never deletable. Documents that never posted (drafts) may be deleted.

---

## 5. Default Chart of Accounts (seeded per company)

Services-business template; the accountant reviews this list at Gate A. Users can add/edit/archive their own accounts from the Chart of Accounts screen.

| Code | Name | Type / subtype | System? |
|---|---|---|---|
| 1000 | Cash on Hand | asset / cash | yes* |
| 1010 | Bank Account | asset / bank | yes* |
| 1100 | Accounts Receivable | asset / receivable | yes |
| 1500 | Office Equipment | asset / fixed_asset | no |
| 2000 | Accounts Payable | liability / payable | yes |
| 2100 | Tax Payable | liability / tax | yes |
| 3000 | Owner's Equity | equity | no |
| 3100 | Opening Balance Equity | equity | yes |
| 3200 | Retained Earnings | equity | yes |
| 3900 | Owner Drawings | equity | no |
| 4000 | Service Income | income | no |
| 4900 | Other Income | income | no |
| 5000 | Salaries & Wages | expense | no |
| 5100 | Rent | expense | no |
| 5200 | Utilities | expense | no |
| 5300 | Internet & Phone | expense | no |
| 5400 | Software & Subscriptions | expense | no |
| 5500 | Marketing & Advertising | expense | no |
| 5600 | Office Supplies | expense | no |
| 5700 | Bank Fees & Charges | expense | no |
| 5800 | Travel | expense | no |
| 5900 | Professional Fees | expense | no |
| 5990 | Miscellaneous Expense | expense | no |

\* 1000/1010 are created through the Bank & Cash Accounts module (§7.3); every bank/cash account the user creates gets its own ledger account with subtype `bank`/`cash`. The seeded pair is the starting point.

Launch track adds: FX Gain/Loss (4950/5950), Customer Credits, Undeposited Funds. (If the §1 precondition fails and USD invoices are unavoidable in the Gate B month, FX Gain/Loss is seeded early — §16.5.)

---

## 6. Posting Rules Catalog

The complete list of v1 business events and the journal lines they generate. **This table (plus §5) is the artifact the accountant validates at Gate A.** Every row becomes at least one TDD test asserting these exact lines. Example amounts in PKR for readability; stored as minor units.

| # | Event | Debit | Credit |
|---|---|---|---|
| P1 | Invoice sent — 100,000 services + 18% tax | 1100 AR 118,000 | 4000 Income 100,000; 2100 Tax Payable 18,000 |
| P2 | Customer payment received into bank — 118,000 | 1010 Bank 118,000 | 1100 AR 118,000 |
| P3 | Partial payment — 50,000 | 1010 Bank 50,000 | 1100 AR 50,000 |
| P4 | Expense paid immediately — rent 50,000 from bank | 5100 Rent 50,000 | 1010 Bank 50,000 |
| P5 | Expense with input tax — supplies 10,000 + 1,800 tax | 5600 Supplies 10,000; 2100 Tax Payable 1,800 | 1010 Bank 11,800 |
| P6 | Bill received (pay later) — 30,000 hosting | 5400 Software 30,000 | 2000 AP 30,000 |
| P6t | Bill with input tax — 10,000 + 1,800 tax | expense account 10,000; 2100 Tax Payable 1,800 | 2000 AP 11,800 |
| P7 | Bill payment — 30,000 from bank | 2000 AP 30,000 | 1010 Bank 30,000 |
| P8a | Opening bank/cash balances (setup) — bank 500,000 | 1010 Bank 500,000 | 3100 Opening Balance Equity 500,000 |
| P8b | Opening customer invoice (setup; one per open invoice, with contact, original date & due date) — 200,000 | 1100 AR 200,000 | 3100 Opening Balance Equity 200,000 |
| P8c | Opening vendor bill (setup; one per open bill, with contact, original date & due date) — 80,000 | 3100 Opening Balance Equity 80,000 | 2000 AP 80,000 |
| P9 | Void sent invoice (P1) | 4000 Income 100,000; 2100 Tax Payable 18,000 | 1100 AR 118,000 (reversal, linked) |
| P10 | Manual journal entry | user-specified lines | user-specified lines (must balance; may not touch AR/AP control accounts — §7.10) |
| P11 | Transfer between money accounts — deposit 40,000 cash into bank | 1010 Bank 40,000 | 1000 Cash 40,000 |
| P12 | Tax remittance to authority — 16,200 from bank | 2100 Tax Payable 16,200 | 1010 Bank 16,200 |
| P13 | Owner drawing — 100,000 from bank | 3900 Owner Drawings 100,000 | 1010 Bank 100,000 |
| P14 | Owner contribution / other receipt — 300,000 into bank | 1010 Bank 300,000 | 3000 Owner's Equity 300,000 (or other selected non-control account) |
| P15 | Fixed-asset purchase — laptop 250,000 | 1500 Office Equipment 250,000 | 1010 Bank 250,000 (or 2000 AP via bill) |

P12/P13/P15 are recorded through the Expense/Bill screens (the category picker allows asset, liability, and equity accounts — §7.8); P14 through the "Other receipt" screen (§7.3). They are listed separately so the accountant validates each posting shape at Gate A.

Notes for Gate A review:

- **Opening balances (P8a–c):** the guided setup captures opening receivables/payables **per open document** — contact, amount, original date, due date. P8b/P8c create real (system-flagged `is_opening`) invoice/bill documents with **no income or expense effect** — their offset is Opening Balance Equity. Payments against them then flow through P2/P3/P7 unchanged, and aging buckets use their real due dates. Opening bank balances (P8a) post per bank/cash account.
- **Input tax (P5/P6t):** v1 nets input tax against 2100 Tax Payable (debit side), so 2100's balance = net tax owed. If the accountant prefers a separate Input Tax (asset) account, that's a one-row change to the seed + posting rules — decide at Gate A. (A second reason to consider it: it makes the Tax summary's collected-vs-input split trivial — §7.11.6.)
- **AR/AP subledger:** every line on an AR/AP control account (P1–P3, P6–P8, and their reversals) carries `contact_id` — enforced by `LedgerService` — so aging and contact balances derive from ledger lines and tie to the control accounts by construction.
- **Overpayments are rejected in v1** — a payment cannot exceed the document's outstanding balance (customer credits are launch track). Enforced on a row-locked read (§9).
- **Voiding after partial payment:** payments must be voided (reversed) before the invoice can be voided. The UI walks the user through it; nothing is deleted.
- **Invoice numbering:** `INV-0001`-style, per-company counter table read with `SELECT … FOR UPDATE` inside the send transaction — no duplicates, no gaps under concurrency. Draft invoices have no number until sent. Opening invoices/bills use a distinct series (`OB-0001`) so real numbering is unaffected.

---

## 7. Modules (what users see)

### 7.1 Onboarding & company setup
Signup → create company (name, base currency, fiscal year start month, **books start date**) → seed chart of accounts → guided setup: add bank/cash accounts with opening balances (P8a), open customer invoices and vendor bills one-by-one with contact, amount, original date and due date (P8b/P8c) → system posts the opening entries, dated per captured dates (bank balances as of the day before books start date).

- **Re-running setup** (allowed until the first real transaction): reverses every prior opening entry (`reverses_entry_id` set) and posts fresh ones — never a second copy on top. **"First real transaction"** = first posted entry whose source_type is not `opening_balance` (or a reversal of one).
- **Opening Balance Equity lifecycle:** after setup is confirmed correct (at latest, Gate B), the app offers a one-click assisted journal entry transferring the OBE balance to 3000 Owner's Equity, with the expectation that OBE reads zero on the go-forward Balance Sheet. This guidance appears on the OBE account page.

### 7.2 Chart of Accounts (user-facing)
List grouped by type with current balances. Add/edit/archive accounts. System accounts protected (no delete/retype). Click an account → its ledger (all journal lines, running balance) — this doubles as the General Ledger drill-down.

### 7.3 Bank & Cash Accounts
First-class objects (name, type bank/cash, currency — base-only in v1, opening balance, linked ledger account auto-created). Balance shown = ledger balance of the linked account. Also home to:
- **Transfer** (P11): source account, destination account, date, amount, memo. Both sides appear in each account's reconciliation.
- **Other receipt** (P14): money in that isn't an invoice payment — owner contribution, misc income, refunds. Deposit account + a category picker (equity/income/other non-control accounts).
- **Reconciliation** (§7.9).

### 7.4 Contacts
Customers and vendors in one table with a `type` flag (customer / vendor / both). Name, email, phone, billing address, tax number, notes. Contact page shows their invoices/bills (including opening documents) and outstanding balance — derived from ledger lines, so it always ties to AR/AP.

### 7.5 Items
Optional catalog of services/products sold: name, description, default unit price, default income account, default tax rate. Invoice lines can pull from items or be free-typed.

### 7.6 Tax Rates
Simple table: name + percentage ("GST 18%"). Applied per line on invoices, expenses, **and bills**. **Rounding rule: tax is computed and rounded per line (half-up to minor unit), then summed.** Editing a rate never touches historical documents (rate value is copied onto the line at creation).

### 7.7 Invoicing (money in)
- Lifecycle: **Draft → Sent → Partially Paid → Paid**; **Void** possible from Sent/Partially Paid (with payment reversal first); **Overdue** is a computed display state past due date.
- Drafts freely editable, no ledger effect, no invoice number. **Send** assigns the number, freezes the document, posts P1. Sent invoices are never edited — void and reissue.
- Line items: description, qty × unit rate, optional tax rate, per-line income account (defaults from item or 4000). No invoice-level discount in v1 (launch track — allocation rounding is a known trap).
- Record payment (full/partial) → pick deposit account + date → posts P2/P3.
- PDF (clean default template, company logo/colors) + send-by-email with the PDF attached.

### 7.8 Expenses & Bills (money out)
- **Expense** = already paid. Lifecycle: **Draft → Recorded** (recording posts P4/P5); **Void** via reversal. Multi-line like bills: each line has a category account, amount, optional tax rate. Paid-from account + date on the document.
- **Bill** = pay later. Lifecycle: **Draft → Received → Partially Paid → Paid** (receiving posts P6/P6t); **Void** via reversal (payments reversed first). **Record payment** posts P7.
- **Category account picker** allows expense, asset, liability, and equity accounts — filtered to exclude AR/AP control accounts and bank/cash accounts (transfers have their own screen). This is what makes tax remittances (P12), owner drawings (P13), and fixed-asset purchases (P15) recordable without manual JEs.
- Optional file attachment (receipt/bill scan) on both.

### 7.9 Bank Reconciliation (basic, manual)
Pick bank account → enter statement end date + statement ending balance → screen lists unreconciled ledger lines for that account through that date → tick lines that appear on the statement → live difference = statement balance − (opening reconciled balance + ticked amount). **Finish only enabled at difference 0.** Completing stores a reconciliation record and marks lines reconciled.
- **Reopen:** the most recent reconciliation for an account can be reopened (roles with `reconciliation.manage`; audit-logged), unlocking its lines for correction. Reopening must proceed newest-first so the opening-reconciled-balance chain stays consistent.
- No bank feeds/CSV import in v1 (launch track).

### 7.10 Manual Journal Entries
For roles with `ledger.post_manual`: date, memo, arbitrary lines (account, debit/credit). Live balance indicator; unbalanced entries cannot be submitted (client-side UX + server-side `LedgerService` enforcement). Reversal button on any manual entry.
- **Manual JEs may not touch AR/AP control accounts (1100/2000)** — corrections to receivables/payables go through void/reissue of the underlying documents, keeping document state and subledger in lockstep. Enforced in `LedgerService`, explained in the UI.
- Manual JEs *may* post to 3200 Retained Earnings (prior-period adjustments); the Balance Sheet rule in §7.11.2 accounts for this.

### 7.11 Reports
All money reports are computed **from journal lines** (never from document totals), so amounts tie to the ledger by construction. One narrow, defined exception: **aging bucket assignment** joins to the source document (via source_type/source_id) for its due date — the *amounts* still come from ledger lines. Every report: date-range picker, CSV export, print-friendly view.

1. **Profit & Loss** — income/expense accounts over a period, with monthly-comparison columns.
2. **Balance Sheet** — assets/liabilities/equity at a date. **Retained Earnings line = posted ledger balance of 3200 + computed net income of all completed fiscal years** (using the company's fiscal-year setting); **current-year earnings** shown as its own computed line. Must always balance (tested). The Trial Balance, by contrast, shows raw account balances (income/expense accounts stay open — there are no closing entries in v1; 3200 at its posted value); the presentational difference between TB and BS Retained Earnings is expected and documented for the accountant.
3. **A/R Aging** and **A/P Aging** — outstanding per contact, bucketed **by due date** (current = not yet due; then 1–30 / 31–60 / 61–90 / 90+ days past due). Amounts derive from ledger lines grouped by contact and source document; totals tie to AR/AP control accounts (tested). Opening documents age by their captured due dates.
4. **Trial Balance** — every account's debit/credit balance at a date; totals equal (tested). Gate B artifact.
5. **General Ledger** — per-account line detail with running balances; drill-down from every other report. Gate B artifact.
6. **Tax summary** — net balance of 2100 over a period, split **collected vs. input by source document type (signed)**: invoice entries and their reversals = collected side; expense/bill entries and their reversals = input side; tax remittances (P12) and manual adjustments shown as their own lines. The split is *never* derived from which column (debit/credit) an amount sits in — reversals would misclassify (tested: voiding an invoice decreases collected, leaves input unchanged).

### 7.12 Dashboard
Cash position (sum of bank/cash accounts), outstanding & overdue invoices, unpaid bills, P&L snapshot, income vs. expense chart (Chart.js), recent activity.

### 7.13 Audit Log
Append-only `activity_log`: company_id, user_id, action, subject type/id, before/after JSON diff, IP, timestamp. Logged: every create/update/post/void/reconcile/reopen/lock/permission-change across all modules; log rows are never updated or deleted. Viewer UI (filter by user/module/date) for roles with `audit.view`.

### 7.14 Users, Roles & Permissions

**The definitive permission catalog.** This table is the artifact the §12 permission-matrix tests assert against (as §6 is for posting rules). Checked via Laravel policies.

| Permission | Owner | Admin | Accountant | Staff |
|---|---|---|---|---|
| invoices.draft (create/edit/delete drafts) | ✓ | ✓ | ✓ | ✓ |
| invoices.send | ✓ | ✓ | ✓ | — |
| invoices.void | ✓ | ✓ | ✓ | — |
| payments.record (invoice & bill payments) | ✓ | ✓ | ✓ | — |
| expenses.draft / bills.draft | ✓ | ✓ | ✓ | ✓ |
| expenses.record / bills.receive | ✓ | ✓ | ✓ | — |
| expenses.void / bills.void | ✓ | ✓ | ✓ | — |
| transfers.create / other_receipts.create | ✓ | ✓ | ✓ | — |
| contacts.manage / items.manage / tax_rates.manage | ✓ | ✓ | ✓ | — |
| accounts.manage (chart of accounts) | ✓ | ✓ | ✓ | — |
| bank_accounts.manage | ✓ | ✓ | ✓ | — |
| reconciliation.manage (incl. reopen) | ✓ | — | ✓ | — |
| ledger.post_manual | ✓ | — | ✓ | — |
| periods.lock | ✓ | — | ✓ | — |
| reports.view | ✓ | ✓ | ✓ | — |
| audit.view | ✓ | — | ✓ | — |
| exports.run | ✓ | ✓ | ✓ | — |
| settings.manage (company settings, opening setup) | ✓ | ✓ | — | — |
| users.manage (invite, assign roles) | ✓ | ✓° | — | — |
| company.billing / company.delete | ✓ | — | — | — |

° Admin may manage users but may not grant or hold a role with more permissions than their own (no escalation).

Roles are fixed bundles in v1 (custom roles are launch track). Role is per company (pivot). Summary intents: **Owner** — everything. **Admin** — day-to-day operations and settings, but not the accountant-grade powers (manual JEs, period locks, reconciliation, audit log). **Accountant** — full books, no company/user administration. **Staff** — drafts only; nothing that posts to the ledger, so their work always passes through someone with recording powers.

---

## 8. Data Model Summary

Tenant tables (all with `company_id`): `accounts`, `journal_entries`, `journal_lines`, `contacts`, `items`, `tax_rates`, `bank_accounts` (with `currency_code`), `invoices`, `invoice_lines`, `invoice_payments`, `bills`, `bill_lines`, `bill_payments`, `expenses`, `expense_lines`, `transfers`, `other_receipts`, `reconciliations`, `reconciliation_lines`, `attachments`, `activity_log`, `document_counters`.
Global tables: `users` (with `current_company_id`), `companies`, `company_user` (role per membership).

- Invoices and bills carry `is_opening` (system-generated opening documents, §6 P8b/c; excluded from income/expense reporting by construction since they never post to income/expense accounts).
- Document tables store their own denormalized totals (`subtotal`, `tax_total`, `total`, `amount_paid`, `balance` in **document currency**, plus `base_*` mirrors — all minor units) for list-screen performance; the ledger remains the source of truth and the integrity checker (§9) cross-verifies document balances against it.
- Expenses are multi-line (`expense_lines`), same shape as bills.

---

## 9. Correctness & Error Handling

- **Transactions:** document write + ledger post = one DB transaction, always. Partial states cannot exist.
- **Row locking, not just transactions:** every payment-recording, send, void, and reconciliation-completing operation begins by acquiring the parent document row with `lockForUpdate()` (voids also lock the related payment rows), then re-reads `amount_paid`/`status`/`balance` from the locked row before validating and posting. All status-transition checks in the service layer happen on lock-acquired rows. (A plain re-read inside a transaction does **not** prevent races under InnoDB's default REPEATABLE READ — two concurrent payments would both see the same stale balance and overpay.)
- **Validation layers:** client-side UX validation → form request validation → domain service invariants (balance, period lock, overpayment, status transitions, control-account rules). The service layer is authoritative; the UI is convenience.
- **Integrity checker:** artisan command, scheduled daily and runnable on demand. Iterates companies explicitly (context set per company; never vacuous — §3). Per company, all checks run **inside a single REPEATABLE READ transaction** (one consistent snapshot), and any finding is re-verified once on a fresh snapshot before alerting (no false alarms from a payment committing mid-scan). Checks: (a) every journal entry balances; (b) trial balance sums to zero; (c) every invoice/bill document-currency balance equals its payment-application-derived balance, and (in v1, where fx=1) its ledger-derived balance; (d) AR/AP control accounts equal their by-contact subledger sums; (e) every posted document has exactly the expected ledger entries; (f) every control-account line carries contact_id. Any failure → logged, surfaced on an admin health screen, and emailed to the owner. Expected result forever: zero findings.
- **Status transitions** are a defined state machine per document (§7.7 invoices; §7.8 expenses/bills); illegal transitions are rejected in the service layer regardless of what the UI sends.
- **User-facing errors** are specific and honest ("This invoice is in a locked period; the reversal will be dated today — proceed?"), never silent fallbacks. No catch-and-continue around money paths.

---

## 10. Security & Tenancy Isolation

- Global scope via `BelongsToCompany` + `CompanyContext` (§3) and policies on every model; controllers never accept a raw `company_id` from the client.
- Route-model binding is scoped: fetching another company's resource by ID yields **404** (never 403 — a 403 would leak resource existence). Tested per endpoint (§12 test 4).
- Standard Laravel auth (Breeze), password policies, signed URLs for attachments, CSRF, rate limiting on auth endpoints.
- File uploads (receipts, logos) stored per-company-prefixed, served through authenticated, policy-checked routes — never publicly guessable paths.
- No secrets in the repo; `.env` only.

---

## 11. Backup & Export Strategy (required before production use)

- **Backups:** scheduled daily database dump (artisan + host cron), retained 14 daily + 8 weekly, copied off-host (S3-compatible bucket — flysystem already familiar from SA Track). **A documented restore procedure, actually rehearsed once before the real-month trial.** Uploaded attachments included in backup scope.
- **User-facing export:** CSV export on every list screen and report; plus a one-click **"Accountant package"** — General Ledger, Trial Balance, P&L, Balance Sheet, A/R & A/P aging for a chosen period as CSVs + PDFs in a zip. This is also the escape hatch: a customer can always leave with their data.
- **Import:** out of scope for v1 (opening balances cover migration-lite); CSV import of contacts/invoices is launch track.

---

## 12. Testing Strategy

**Strict TDD** (test first, red-green-refactor) for all domain logic. The following are **required, non-negotiable test categories** (from Jawad, expanded):

1. **Every posted entry balances** — asserted in every posting-rule unit test *and* as a global invariant re-checked after every feature test run (test hook queries the whole test-DB ledger).
2. **No direct editing of posted ledger entries** — model guards throw on update/delete; no HTTP route can modify them (route audit test).
3. **Reversal/adjustment flow works** — void invoice (P9), void after partial payment (payments reversed first), manual JE reversal, setup re-run (prior opening entries reversed, never duplicated); reversals link to their originals and the net effect is zero.
4. **Tenant isolation** — for every resource endpoint: user of company B gets **404** on company A's data (no existence leak), and writes cannot attach to another company (automated across routes, not hand-picked).
5. **Invoices create correct receivables** — P1 lines exact, incl. multi-line, taxed/untaxed mix, item-based and free-typed lines; every AR line carries contact_id.
6. **Payments clear receivables** — full (P2), partial (P3), multiple partials sum correctly, overpayment rejected, **payments against opening invoices (P8b) work identically**, concurrent double-payment cannot overpay. The concurrency test runs against a **real MySQL database with two parallel processes/connections** — not inside a wrapping test transaction, where the race cannot occur and the test would pass vacuously.
7. **Expenses/bills create correct payables/cash movements** — P4–P7 (incl. P6t input tax and multi-line expenses) exact lines; bill payment cannot exceed bill balance; transfers (P11), other receipts (P14), and asset/liability/equity-category documents (P12/P13/P15) post exactly.
8. **Tax calculations are consistent** — per-line round-half-up then sum; documented awkward cases (e.g. 3 lines of 33.33 at 18%) asserted; editing a tax rate never changes historical documents; tax summary classifies voids by source (void invoice → collected decreases, input unchanged).
9. **Reports tie back to the ledger** — Balance Sheet balances (incl. after manual postings to 3200 and across a fiscal-year boundary); P&L net = income − expense lines; aging totals == AR/AP control balances (incl. opening documents; buckets by due date); Trial Balance debits == credits; report amounts derived from `journal_lines` (aging bucket dates via source documents, per §7.11).
10. **Rounding/money storage is safe** — integer minor units end-to-end; property-style tests with large and awkward values; no float appears in any money computation path (static grep check in CI for `float` casts on money columns).

Plus: period-lock enforcement tests, permission-matrix tests (asserting the §7.14 table, role × permission), audit-log coverage tests (every mutating action writes a log row), reconciliation tests (complete at difference 0 only; reopen newest-first unlocks correctly), and integrity-checker tests (seeded corruption is detected; checker fails loudly on zero companies/entries).

**Browser verification:** per Jawad's standing rule, every feature is driven in the real browser (Laravel dev server + preview tools) before being called ready — forms submitted, PDFs opened, reports eyeballed. Tests + curl alone don't count.

**Accountant validation gates:** see §13.

---

## 13. Build Order & Accountant Gates

| Phase | Ships | Exit criteria |
|---|---|---|
| **Gate A** | Accountant reviews §5 (chart of accounts) + §6 (posting rules, incl. P8/P11–P15 and the input-tax question) + reconciliation & period-lock design | Written sign-off or corrections folded back into this spec. Can run in parallel with Phase 0–1 build, **must pass before Phase 3 begins** |
| 0 | Project scaffold, auth, company setup + seeding, company switcher/context, roles/permissions (§7.14 table), audit-log plumbing, CI with test suite | Tests green; two companies fully isolated; permission matrix tests assert §7.14 |
| 1 | **Ledger core:** accounts + CoA screen, `LedgerService` (all iron rules incl. control-account guards), manual JEs, period locks, Trial Balance report, integrity checker | All §12 ledger tests green; TB balances in browser; checker fails loudly on seeded corruption |
| 2 | Contacts, items, tax rates, bank/cash accounts, transfers + other receipts, opening-balance setup flow (P8a–c, re-run semantics) | Opening balances post correctly incl. per-contact opening documents; setup re-run reverses cleanly; balances visible on CoA |
| 3 | **Invoicing:** lifecycle, numbering, payments (row-locked), void/reversal, PDF, email | P1–P3, P9 tests green incl. real-MySQL concurrency test; payment against an opening invoice verified; full invoice life driven in browser |
| 4 | Expenses & bills (multi-line, input tax, asset/liability/equity categories) + payments, attachments | P4–P7, P6t, P12/P13/P15 green |
| 5 | Reports (P&L, BS, agings, GL, tax summary) + dashboard | Report tie-out tests green (§12.9), incl. aging with opening documents and BS across fiscal-year boundary |
| 6 | Bank reconciliation (incl. reopen), audit-log viewer, CSV/accountant-package export, backups + rehearsed restore, polish | All §14 **pre-trial** items checked |
| **Real-month trial** | SparkingAsia runs a real month (precondition from §1 confirmed: PKR-only billing, or FX slice pulled forward) | A full month of real books entered |
| **Gate B** | Accountant reviews TB, GL, P&L, BS, agings from the real month | Accountant sign-off → production use (§14 sign-off items checked) |
| Launch track | Multi-currency UI + FX gain/loss (per §3's enumerated logic changes), customer statements & credits, recurring invoices, bank CSV import, invoice-level discounts, contact/invoice CSV import, custom roles, tenant self-serve signup & billing | Turns internal tool into sellable product |
| Later phases | Localization packs (Pakistan FBR, …), inventory, online payments, payroll (last — hardest) | Driven by real customer demand |

Ledger core, chart of accounts, journal entries, opening balances, and reports are all built and tested **before** any launch-track feature — per Jawad's explicit requirement.

---

## 14. Production-Readiness Checklist

**Before the real-month trial (Phase 6 exit):**

- [ ] All §12 test categories green in CI
- [ ] Gate A accountant sign-off recorded
- [ ] §1 currency precondition confirmed for the trial month
- [ ] Integrity checker scheduled and alerting (and failing loudly on empty input)
- [ ] Daily backups running; restore rehearsed successfully once
- [ ] Period locking verified in browser
- [ ] Audit log capturing all mutating actions
- [ ] Permission matrix verified per role in browser (against the §7.14 table)
- [ ] Accountant-package export opens correctly in Excel

**Before production sign-off:**

- [ ] Gate B: real month reviewed and signed off
- [ ] Opening Balance Equity cleared to Owner's Equity (§7.1)

---

## 15. Risks & Mitigations

| Risk | Mitigation |
|---|---|
| Jawad's limited accounting background → design errors ripple | Posting-rules catalog (§6) is small, explicit, and human-reviewable; two accountant gates; reports derived from ledger so errors surface as visible imbalance, not silent corruption |
| Scope creep toward "everything QuickBooks does" | Phases are contractual; launch-track items are listed so they don't sneak into v1 |
| Solo/part-time capacity | Each phase ends in something usable; SparkingAsia value arrives at Phase 6, not at "done" |
| Multi-currency retrofit pain | Schema and column semantics pinned from day one; the remaining logic changes are enumerated in §3 (payment application, settlement rounding, FX account, integrity-check split) so the retrofit is planned work, not discovery |
| USD billing needed during the trial month | Checked precondition (§1); minimal FX slice pulled forward if needed (§16.5) |
| Concurrency corruption (the SA Track double-start class of bug) | Row locks on all money-mutating operations (§9); honest multi-process concurrency tests (§12.6) |
| Tax handling wrong for eventual market | v1 tax is deliberately simple + Gate A review; country packs are additive later |

---

## 16. Open Questions (not blockers for starting the build)

1. **Product name** — needed before scaffolding (folder rename; no spaces). Working candidates welcome.
2. **Hosting** — Hostinger like SA Track, or a VPS for cron/queue/backup control? Decide by Phase 6.
3. **Accountant sourcing** — who performs Gates A and B (former company accountant? hired freelancer?). Needed by end of Phase 2.
4. **Fiscal year** — company setting exists (§7.1); confirm SparkingAsia's fiscal year start for Retained Earnings behavior.
5. **SparkingAsia's invoicing currency mix** — confirm before Phase 3 whether the Gate B trial month can be billed entirely in PKR. If not: seed FX Gain/Loss early and define the minimal supported USD workflow (rate captured at send and at payment; difference posts to FX Gain/Loss) as a v1.5 slice before the trial.
