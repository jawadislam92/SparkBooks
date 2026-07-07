# Accounting Software — v1 Design

**Working name:** TBD (folder is currently `not sure`; must be renamed before scaffolding — paths with spaces break some frontend tooling)
**Date:** 2026-07-08
**Status:** Design approved in conversation; pending written-spec review by Jawad
**First real user:** SparkingAsia (services company — agency work, no physical inventory)
**Long-term goal:** If the product proves itself internally, sell it as a multi-tenant SaaS (QuickBooks-class, built up in phases)

---

## 1. Purpose & Goals

Build a web-based accounting application that does what QuickBooks does — eventually. Version 1 is deliberately small but **architecturally and accountingly correct**: a real double-entry ledger underneath approachable screens (invoices, expenses, reports). Correctness is explicitly prioritized over speed of delivery.

**Success criteria for v1:**

1. SparkingAsia runs its real books on it for a full month.
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
| Multi-currency | Schema-native from day one, single-currency behavior in v1 | SparkingAsia will need USD invoicing; enabling it later must be a UI change, not a migration |
| Corrections | Immutable posted entries + reversals | Real accounting practice; structurally prevents silent-corruption bugs |

---

## 3. Architecture Overview

A fresh Laravel 12 application:

- **Backend:** Laravel 12, PHP 8.2+, MySQL. Domain logic in service classes (`LedgerService`, `InvoiceService`, …), thin controllers, form requests for validation, policies for authorization.
- **Frontend:** Inertia + React + TypeScript + Tailwind. Chart.js for dashboard/report charts (already familiar).
- **Multi-tenancy:** every signup creates a **Company**. Every tenant-owned table has `company_id` (indexed, FK). A `BelongsToCompany` trait applies a global scope from the authenticated user's current company. Users may belong to multiple companies via a `company_user` pivot (role per company) — this also future-proofs for accountants who serve many clients.
- **Environments:** local Laragon for development. Deployment target decided later (Hostinger pattern is known from SA Track). Nothing in v1 assumes a specific host.

### Money representation

- All monetary values stored as **BIGINT minor units** (e.g., PKR 1,500.50 → `150050`).
- No floats anywhere in the money path — not in PHP, not in JS, not in SQL. Percentages (tax rates, FX rates) are stored as `DECIMAL` and applied with integer-safe rounding (§7 Tax; §12 test 10).
- Each company has a `base_currency` (ISO 4217 code) chosen at setup. **The ledger always balances in base currency.**

### Multi-currency readiness (schema now, feature later)

- Source documents (invoices, bills, expenses, payments) carry: `currency_code`, `fx_rate` (rate to base at transaction time, DECIMAL(16,8)), amounts in document currency, and derived base-currency amounts.
- Journal lines store **base-currency amounts only** in v1. When multi-currency is enabled (launch track), per-line original-currency columns and an FX Gain/Loss account are added — an additive migration, not a restructuring.
- V1 UI fixes every document's currency to the company base currency with `fx_rate = 1.0`.

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
| source_type, source_id | Polymorphic link to the document that created it (invoice, payment, expense, bill, bill_payment, opening_balance, manual, reversal) |
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
| contact_id | optional; set on AR/AP lines so subledgers (aging) can be derived |

### The iron rules

1. **Balance invariant:** within every journal entry, `SUM(debit) == SUM(credit)`. Enforced in `LedgerService` before commit; violating entries are rejected with an exception. A scheduled integrity command re-verifies the whole ledger (§9).
2. **One gate in:** `LedgerService::post(EntryDraft $draft): JournalEntry` is the **only** code path that writes `journal_entries` / `journal_lines`. All modules build an `EntryDraft` (date, memo, source, lines) and call it. Runs inside a DB transaction with the source-document write, so a document and its ledger entry commit or roll back together.
3. **Immutability:** posted entries are never updated or deleted. Eloquent model guards throw on `update()`/`delete()`; there are no HTTP endpoints that touch them. Corrections are **reversal entries** (mirror-image lines, `reverses_entry_id` set) plus, where relevant, a fresh corrected entry.
4. **Period locks:** each company has `books_locked_through` (nullable date). `LedgerService` rejects any posting (including reversals) with `entry_date <= books_locked_through`. Only Owner and Accountant roles may move the lock date, and moving it is audit-logged. Voiding a document dated inside a locked period requires a reversal dated in the open period (the UI defaults to today and explains why).
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

\* 1000/1010 are created through the Bank & Cash Accounts module (§7); every bank/cash account the user creates gets its own ledger account with subtype `bank`/`cash`. The seeded pair is the starting point.

Launch track adds: FX Gain/Loss (4950/5950), Customer Credits, Undeposited Funds.

---

## 6. Posting Rules Catalog

The complete list of business events and the journal lines they generate. **This table (plus §5) is the artifact the accountant validates at Gate A.** Every row becomes at least one TDD test asserting these exact lines. Example amounts in PKR for readability; stored as minor units.

| # | Event | Debit | Credit |
|---|---|---|---|
| P1 | Invoice sent — 100,000 services + 18% tax | 1100 AR 118,000 | 4000 Income 100,000; 2100 Tax Payable 18,000 |
| P2 | Customer payment received into bank — 118,000 | 1010 Bank 118,000 | 1100 AR 118,000 |
| P3 | Partial payment — 50,000 | 1010 Bank 50,000 | 1100 AR 50,000 |
| P4 | Expense paid immediately — rent 50,000 from bank | 5100 Rent 50,000 | 1010 Bank 50,000 |
| P5 | Expense with input tax — supplies 10,000 + 1,800 tax | 5600 Supplies 10,000; 2100 Tax Payable 1,800 | 1010 Bank 11,800 |
| P6 | Bill received (pay later) — 30,000 hosting | 5400 Software 30,000 | 2000 AP 30,000 |
| P7 | Bill payment — 30,000 from bank | 2000 AP 30,000 | 1010 Bank 30,000 |
| P8 | Opening balances (setup) — bank 500,000; customers owe 200,000; owed to vendors 80,000 | 1010 Bank 500,000; 1100 AR 200,000 | 2000 AP 80,000; 3100 Opening Balance Equity 620,000 |
| P9 | Void sent invoice (P1) | 4000 Income 100,000; 2100 Tax Payable 18,000 | 1100 AR 118,000 (reversal, linked) |
| P10 | Manual journal entry | user-specified lines | user-specified lines (must balance; validated live in UI and in service) |

Notes for Gate A review:

- **P5 (input tax):** v1 nets input tax against 2100 Tax Payable (debit side), so 2100's balance = net tax owed. If the accountant prefers a separate Input Tax (asset) account, that's a one-row change to the seed + posting rule — decide at Gate A.
- **AR/AP subledger:** P1–P3, P6–P7 lines on AR/AP carry `contact_id`, so aging reports derive from ledger lines and always tie to the control account by construction.
- **Overpayments are rejected in v1** — a payment cannot exceed the invoice's outstanding balance (customer credits are launch track).
- **Voiding after partial payment:** payments must be voided (reversed) before the invoice can be voided. The UI walks the user through it; nothing is deleted.
- **Invoice numbering:** `INV-0001`-style, per-company counter table read with `SELECT … FOR UPDATE` inside the send transaction — no duplicates, no gaps under concurrency. Draft invoices have no number until sent.

---

## 7. Modules (what users see)

### 7.1 Onboarding & company setup
Signup → create company (name, base currency, fiscal year start month) → seed chart of accounts → guided setup: add bank/cash accounts with opening balances, customers who owe you, vendors you owe → system posts the opening-balance entry (P8), dated the day before "books start date." Skippable and re-runnable until first real transaction.

### 7.2 Chart of Accounts (user-facing)
List grouped by type with current balances. Add/edit/archive accounts. System accounts protected (no delete/retype). Click an account → its ledger (all journal lines, running balance) — this doubles as the General Ledger drill-down.

### 7.3 Bank & Cash Accounts
First-class objects (name, type bank/cash, opening balance, linked ledger account auto-created). Balance shown = ledger balance of the linked account. This module is also where reconciliation lives (§7.9).

### 7.4 Contacts
Customers and vendors in one table with a `type` flag (customer / vendor / both). Name, email, phone, billing address, tax number, notes. Contact page shows their invoices/bills and outstanding balance.

### 7.5 Items
Optional catalog of services/products sold: name, description, default unit price, default income account, default tax rate. Invoice lines can pull from items or be free-typed.

### 7.6 Tax Rates
Simple table: name + percentage ("GST 18%"). Applied per invoice/expense line. **Rounding rule: tax is computed and rounded per line (half-up to minor unit), then summed.** Editing a rate never touches historical documents (rate value is copied onto the line at creation).

### 7.7 Invoicing (money in)
- Lifecycle: **Draft → Sent → Partially Paid → Paid**; **Void** possible from Sent/Partially Paid (with payment reversal first); **Overdue** is a computed display state past due date.
- Drafts freely editable, no ledger effect, no invoice number. **Send** assigns the number, freezes the document, posts P1. Sent invoices are never edited — void and reissue.
- Line items: description, qty × unit rate, optional tax rate, per-line income account (defaults from item or 4000). No invoice-level discount in v1 (launch track — allocation rounding is a known trap).
- Record payment (full/partial) → pick deposit account + date → posts P2/P3.
- PDF (clean default template, company logo/colors) + send-by-email with the PDF attached.

### 7.8 Expenses & Bills (money out)
- **Expense** = already paid: date, payee (optional vendor), category account, paid-from account, optional tax → posts P4/P5.
- **Bill** = pay later: vendor, due date, lines with category accounts → posts P6; **Record payment** posts P7. Same void-by-reversal rules as invoices.
- Optional file attachment (receipt/bill scan) on both.

### 7.9 Bank Reconciliation (basic, manual)
Pick bank account → enter statement end date + statement ending balance → screen lists unreconciled ledger lines for that account through that date → tick lines that appear on the statement → live difference = statement balance − (opening reconciled balance + ticked amount). **Finish only enabled at difference 0.** Completing stores a reconciliation record and marks lines reconciled (locked from being un-ticked outside a reopened reconciliation). No bank feeds/CSV import in v1 (launch track).

### 7.10 Manual Journal Entries
For Owner/Accountant roles: date, memo, arbitrary lines (account, debit/credit, optional contact). Live balance indicator; unbalanced entries cannot be submitted (client-side UX + server-side `LedgerService` enforcement). Reversal button on any manual entry.

### 7.11 Reports
All money reports are computed **from journal lines** (never from documents), so they tie to the ledger by construction. Every report: date-range picker, CSV export, print-friendly view.

1. **Profit & Loss** — income/expense accounts over a period, with monthly-comparison columns.
2. **Balance Sheet** — assets/liabilities/equity at a date. Retained Earnings line = accumulated prior-fiscal-year net profit; current-year earnings shown as its own line. Must always balance (tested).
3. **A/R Aging** and **A/P Aging** — outstanding per contact, bucketed current / 1–30 / 31–60 / 61–90 / 90+ days; totals tie to AR/AP control accounts (tested).
4. **Trial Balance** — every account's debit/credit balance at a date; totals equal (tested). Gate B artifact.
5. **General Ledger** — per-account line detail with running balances; drill-down from every other report. Gate B artifact.
6. **Tax summary** — net balance of 2100 over a period (what you owe the government), collected vs. input.

### 7.12 Dashboard
Cash position (sum of bank/cash accounts), outstanding & overdue invoices, unpaid bills, P&L snapshot, income vs. expense chart (Chart.js), recent activity.

### 7.13 Audit Log
Append-only `activity_log`: company_id, user_id, action, subject type/id, before/after JSON diff, IP, timestamp. Logged: every create/update/post/void/reconcile/lock/permission-change across all modules; log rows are never updated or deleted. Viewer UI (filter by user/module/date) for Owner and Accountant.

### 7.14 Users, Roles & Permissions
Granular permission gates (e.g. `invoices.create`, `invoices.void`, `expenses.create`, `reports.view`, `ledger.post_manual`, `accounts.manage`, `periods.lock`, `settings.manage`, `users.manage`, `audit.view`). Roles are named bundles checked via Laravel policies:

| Role | Summary |
|---|---|
| Owner | Everything, incl. company settings, user management, period locks |
| Admin | Everything except company deletion/billing and user role escalation |
| Accountant | Full books: manual JEs, period locks, all reports, audit log, reconciliation; no company settings/users |
| Staff | Create/edit drafts of invoices & expenses; no ledger, reports, settings, or void powers |

Role is per company (pivot), so one login can be Staff in one company and Accountant in another.

---

## 8. Data Model Summary

Tenant tables (all with `company_id`): `accounts`, `journal_entries`, `journal_lines`, `contacts`, `items`, `tax_rates`, `bank_accounts`, `invoices`, `invoice_lines`, `invoice_payments`, `bills`, `bill_lines`, `bill_payments`, `expenses`, `expense_lines`, `reconciliations`, `reconciliation_lines`, `attachments`, `activity_log`, `document_counters`.
Global tables: `users`, `companies`, `company_user` (role per membership).

Document tables store their own denormalized totals (subtotal, tax_total, total, amount_paid, balance — all minor units) for list-screen performance; the ledger remains the source of truth and the integrity checker (§9) cross-verifies document balances against it.

---

## 9. Correctness & Error Handling

- **Transactions:** document write + ledger post = one DB transaction, always. Partial states cannot exist.
- **Validation layers:** client-side UX validation → form request validation → domain service invariants (balance, period lock, overpayment, status transitions). The service layer is authoritative; the UI is convenience.
- **Integrity checker:** artisan command, scheduled daily and runnable on demand: (a) every journal entry balances; (b) trial balance sums to zero; (c) every invoice/bill `balance` equals its ledger-derived balance; (d) AR/AP control accounts equal their subledger sums; (e) every posted document has exactly the expected ledger entries. Any failure → logged, surfaced on an admin health screen, and emailed to the owner. Expected result forever: zero findings.
- **Status transitions** are a defined state machine per document; illegal transitions (e.g. edit a sent invoice, pay a void bill) are rejected in the service layer regardless of what the UI sends.
- **Concurrency:** counters via `SELECT … FOR UPDATE`; payment recording re-checks outstanding balance inside the transaction (two simultaneous payments cannot overpay).
- **User-facing errors** are specific and honest ("This invoice is in a locked period; the reversal will be dated today — proceed?"), never silent fallbacks. No catch-and-continue around money paths.

---

## 10. Security & Tenancy Isolation

- Global scope via `BelongsToCompany` trait + policies on every model; controllers never accept a raw `company_id` from the client.
- Route-model binding is scoped: fetching another company's invoice by ID yields 404 (tested per endpoint, §12 test 4).
- Standard Laravel auth (Breeze), password policies, signed URLs for attachments, CSRF, rate limiting on auth endpoints.
- File uploads (receipts, logos) stored per-company-prefixed, served through authenticated, policy-checked routes — never publicly guessable paths.
- No secrets in the repo; `.env` only.

---

## 11. Backup & Export Strategy (required before production use)

- **Backups:** scheduled daily database dump (artisan + host cron), retained 14 daily + 8 weekly, copied off-host (S3-compatible bucket — flysystem already familiar from SA Track). **A documented restore procedure, actually rehearsed once before Gate B.** Uploaded attachments included in backup scope.
- **User-facing export:** CSV export on every list screen and report; plus a one-click **"Accountant package"** — General Ledger, Trial Balance, P&L, Balance Sheet, A/R & A/P aging for a chosen period as CSVs + PDFs in a zip. This is also the escape hatch: a customer can always leave with their data.
- **Import:** out of scope for v1 (opening balances cover migration-lite); CSV import of contacts/invoices is launch track.

---

## 12. Testing Strategy

**Strict TDD** (test first, red-green-refactor) for all domain logic. The following are **required, non-negotiable test categories** (from Jawad, expanded):

1. **Every posted entry balances** — asserted in every posting-rule unit test *and* as a global invariant re-checked after every feature test run (test hook queries the whole test-DB ledger).
2. **No direct editing of posted ledger entries** — model guards throw on update/delete; no HTTP route can modify them (route audit test).
3. **Reversal/adjustment flow works** — void invoice (P9), void after partial payment (payments reversed first), manual JE reversal; reversals link to their originals and the net effect is zero.
4. **Tenant isolation** — for every resource endpoint: user of company B gets 404/403 on company A's data, and writes cannot attach to another company (automated across routes, not hand-picked).
5. **Invoices create correct receivables** — P1 lines exact, incl. multi-line, taxed/untaxed mix, item-based and free-typed lines.
6. **Payments clear receivables** — full (P2), partial (P3), multiple partials sum correctly, overpayment rejected, concurrent double-payment cannot overpay.
7. **Expenses/bills create correct payables/cash movements** — P4–P7 exact lines; bill payment cannot exceed bill balance.
8. **Tax calculations are consistent** — per-line round-half-up then sum; documented awkward cases (e.g. 3 lines of 33.33 at 18%) asserted; editing a tax rate never changes historical documents.
9. **Reports tie back to the ledger** — Balance Sheet balances; P&L net = income − expense lines; aging totals == AR/AP control balances; Trial Balance debits == credits; report figures derived from `journal_lines` only.
10. **Rounding/money storage is safe** — integer minor units end-to-end; property-style tests with large and awkward values; no float appears in any money computation path (static grep check in CI for `float` casts on money columns).

Plus: period-lock enforcement tests, permission-matrix tests (role × action), audit-log coverage tests (every mutating action writes a log row), and integrity-checker tests (seeded corruption is detected).

**Browser verification:** per Jawad's standing rule, every feature is driven in the real browser (Laravel dev server + preview tools) before being called ready — forms submitted, PDFs opened, reports eyeballed. Tests + curl alone don't count.

**Accountant validation gates:** see §13.

---

## 13. Build Order & Accountant Gates

| Phase | Ships | Exit criteria |
|---|---|---|
| **Gate A** | Accountant reviews §5 (chart of accounts) + §6 (posting rules) + reconciliation/period-lock design | Written sign-off or corrections folded back into this spec. Can run in parallel with Phase 0–1 build, **must pass before Phase 3 begins** |
| 0 | Project scaffold, auth, company setup + seeding, roles/permissions, audit-log plumbing, CI with test suite | Tests green; two companies fully isolated |
| 1 | **Ledger core:** accounts + CoA screen, `LedgerService`, manual JEs, period locks, Trial Balance report, integrity checker | All §12 ledger tests green; TB balances in browser |
| 2 | Contacts, items, tax rates, bank/cash accounts, opening-balance setup flow | Opening balances post correctly (P8); balances visible on CoA |
| 3 | **Invoicing:** lifecycle, numbering, payments, void/reversal, PDF, email | P1–P3, P9 tests green; full invoice life driven in browser |
| 4 | Expenses & bills + payments, attachments | P4–P7 green |
| 5 | Reports (P&L, BS, agings, GL, tax summary) + dashboard | Report tie-out tests green (§12.9) |
| 6 | Bank reconciliation, audit-log viewer, CSV/accountant-package export, backups + rehearsed restore, polish | Production-readiness checklist (§14) fully checked |
| **Gate B** | SparkingAsia runs a real month; accountant reviews TB, GL, P&L, BS, agings from real data | Accountant sign-off → production use |
| Launch track | Multi-currency UI + FX gain/loss, customer statements & credits, recurring invoices, bank CSV import, invoice-level discounts, contact/invoice CSV import, tenant self-serve signup & billing | Turns internal tool into sellable product |
| Later phases | Localization packs (Pakistan FBR, …), inventory, online payments, payroll (last — hardest) | Driven by real customer demand |

Ledger core, chart of accounts, journal entries, opening balances, and reports are all built and tested **before** any launch-track feature — per Jawad's explicit requirement.

---

## 14. Production-Readiness Checklist (before SparkingAsia's real books)

- [ ] All §12 test categories green in CI
- [ ] Gate A accountant sign-off recorded
- [ ] Integrity checker scheduled and alerting
- [ ] Daily backups running; restore rehearsed successfully once
- [ ] Period locking verified in browser
- [ ] Audit log capturing all mutating actions
- [ ] Permission matrix verified per role in browser
- [ ] Accountant-package export opens correctly in Excel
- [ ] Gate B: real month reviewed and signed off

---

## 15. Risks & Mitigations

| Risk | Mitigation |
|---|---|
| Jawad's limited accounting background → design errors ripple | Posting-rules catalog (§6) is small, explicit, and human-reviewable; two accountant gates; reports derived from ledger so errors surface as visible imbalance, not silent corruption |
| Scope creep toward "everything QuickBooks does" | Phases are contractual; launch-track items are listed so they don't sneak into v1 |
| Solo/part-time capacity | Each phase ends in something usable; SparkingAsia value arrives at Phase 6, not at "done" |
| Multi-currency retrofit pain | Schema multi-currency-native from day one (§3) |
| Tax handling wrong for eventual market | v1 tax is deliberately simple + Gate A review; country packs are additive later |

---

## 16. Open Questions (not blockers for v1 build)

1. **Product name** — needed before scaffolding (folder rename; no spaces). Working candidates welcome.
2. **Hosting** — Hostinger like SA Track, or a VPS for cron/queue/backup control? Decide by Phase 6.
3. **Accountant sourcing** — who performs Gates A and B (former company accountant? hired freelancer?). Needed by end of Phase 2.
4. **Fiscal year** — company setting exists (§7.1); confirm SparkingAsia's fiscal year start for Retained Earnings behavior.
