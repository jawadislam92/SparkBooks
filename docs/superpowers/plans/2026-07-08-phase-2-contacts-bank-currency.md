# SparkBooks Phase 2 — Contacts, Items, Tax, Bank & Multi-Currency Foundation

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax. Update `docs/PROJECT_MAP.md` when done.

**Goal:** Give SparkBooks the reference data and money-movement primitives the invoicing/expense phases need — contacts (customers & vendors), an items catalog, tax rates, first-class bank/cash accounts with transfers and non-invoice receipts, opening balances so SparkingAsia's existing books can be brought in, and the multi-currency (PKR base + USD option) foundation.

**Architecture:** Same spine as Phase 1 (spec §4, PROJECT_MAP §4). New money movements (transfers, other receipts, opening balances) post through `LedgerService::post` inside `IdempotencyService::execute` + `DB::transaction`, exactly like manual journals. Reference data (contacts/items/tax rates) are plain tenant CRUD. Multi-currency: documents and bank accounts gain currency columns now; the FX *posting* mechanics (P1fx/P2fx) land with invoicing in Phase 3 — Phase 2 seeds the FX Gain/Loss accounts and pins the column semantics so nothing has to be restructured later.

**Tech Stack:** as Phase 1 — Laravel 13, Inertia/React/TS, MySQL 8.4, Tailwind v3.

## Global Constraints

- **Shell env (every Bash session):** `export PATH="/c/laragon/bin/php/php-8.3.30-Win32-vs16-x64:/c/laragon/bin/composer:/c/laragon/bin/nodejs/node-v22:/c/laragon/bin/mysql/mysql-8.4.3-winx64/bin:$PATH"`; work in `/c/laragon/www/SparkBooks`, branch `phase-2-contacts-bank`.
- Spec authority: `docs/superpowers/specs/2026-07-08-accounting-software-v1-design.md` §5 (accounts), §6 (posting rules P8/P11/P14), §7.3–7.6 (modules), §7.1 (opening setup), §17.1 (FX-lite — Path 2 CHOSEN), §17.3 (DB constraints), §17.7 (opening CSV templates).
- **All Phase-1 iron rules still hold** (PROJECT_MAP §9). Especially: money = BIGINT minor units, no float (CI-enforced); `company_id` never in `$fillable`/request; every ledger write through `LedgerService::post`; posted entries immutable; every mutating route gated + audit-logged; controllers resolve `{model}` via scoped `findOrFail` (not implicit binding); design tokens only; debit/credit words only on accountant screens.
- **New posting rules go in `LedgerService` via `EntryDraft`** — do not add new ledger writers.
- **contact_id FK:** once `contacts` exists (Task 1), add the real FK to `journal_lines.contact_id` (was deferred in Phase 1). Update `IntegrityChecker` to note it.
- Commit after every task with trailer `Co-Authored-By: Claude Fable 5 <noreply@anthropic.com>`. Tests on real MySQL, strict TDD for all posting/money logic. Update `docs/PROJECT_MAP.md` §2 (status) and §8 (tree) as part of Task 12.

### Currency model for Phase 2 (pins the semantics; spec §3 + §17.1)

- Company already has `base_currency`. Add `Currencies::COMMON` already exists (PKR, USD, …).
- **Documents** (invoices/bills/etc., built later) will carry `currency_code`, `fx_rate` (DECIMAL(16,8)), document-currency amounts + `base_*` mirrors. Phase 2 introduces this pattern on the FIRST document type it builds: none of Phase 2's postings are foreign-currency yet (transfers/other-receipts/opening balances are base-currency in v1), so Phase 2's job is: (a) seed FX Gain/Loss accounts, (b) give `bank_accounts` a `currency_code` (constrained to base in v1), (c) confirm the money helpers already handle any 2-decimal currency (they do — `formatMoney` forces 2dp, currency-aware). Foreign-currency bank accounts remain excluded (spec §17.1); a USD invoice will deposit into a base-currency account at the payment rate — that logic lands in Phase 3.
- Seed **4950 FX Gain** (income/other_income) and **5950 FX Loss** (expense/operating_expense) as system accounts in the chart (extend `ChartOfAccountsSeeder`; add a migration-safe backfill for existing companies via the seeder being re-runnable or a one-off command — note: only dev/SparkingAsia companies exist, so a seeder update + a short artisan backfill command is fine).

---

### Task 1: Contacts (customers & vendors) + contact_id FK backfill

**Files:** migration `create_contacts_table`; `app/Models/Contact.php`, `app/Enums/ContactType.php`; migration `add_fk_to_journal_lines_contact_id`; `app/Http/Controllers/ContactController.php`, `StoreContactRequest`, `UpdateContactRequest`; `resources/js/Pages/Contacts/{Index,Show}.tsx`; routes; `tests/Feature/ContactsTest.php`.

**Contacts table:** id, company_id (FK restrict), type ('customer'|'vendor'|'both'), name (120), email nullable, phone nullable, billing_address text nullable, tax_number nullable, notes text nullable, is_archived bool, timestamps. Index (company_id, type).

**contact_id FK:** now that `contacts` exists, add FK `journal_lines.contact_id → contacts.id` (nullOnDelete — but contacts are archived, not deleted, so restrict is safer; use restrictOnDelete). Migration must run after contacts table.

**ContactType enum:** Customer|Vendor|Both, with `isCustomer()`/`isVendor()` helpers (Both is both).

**Controller/routes** (gate `contacts.manage`): index (list, filter by type + archived), store, update, archive/unarchive, show (contact detail — Phase 2 shows basic info + outstanding balance placeholder = 0 until invoices exist; wire the outstanding balance to derive from `journal_lines` where contact_id = this contact on AR/AP accounts, which is 0 now but correct-by-construction later). Scoped `findOrFail`. Audit-log all mutations.

**UI:** Contacts/Index — sheet, table (name, type chip customer=spark-50/vendor=lagoon-tinted/both, email, phone, archived toggle), New contact modal. Contacts/Show — header + details + (empty) activity. Design tokens; sentence case; no debit/credit words (general screen).

**Tests (TDD):** create customer/vendor/both; validation; archive hides from active filter; scoped 404 cross-company; the journal_lines.contact_id FK rejects a contact_id from another company (raw insert) / accepts a valid one; `contacts.manage` gate (Staff 403). Update `IntegrityChecker` control_contact check comment (FK now enforces referential validity; the check still verifies non-null). Full suite green → commit.

---

### Task 2: Items catalog

**Files:** migration `create_items_table`; `app/Models/Item.php`; `ItemController`, `StoreItemRequest`, `UpdateItemRequest`; `resources/js/Pages/Items/Index.tsx`; routes; `tests/Feature/ItemsTest.php`.

**Items table:** id, company_id, name (120), description text nullable, unit_price BIGINT minor units (nullable — default 0), default_income_account_id nullable FK→accounts (restrict), default_tax_rate_id nullable FK→tax_rates (added Task 3; make the column nullable now, add FK in Task 3's migration or make it a plain nullable bigint now + FK later — prefer: create items after tax_rates OR add the FK in a Task-3 migration; simplest: build Task 3 (tax rates) BEFORE items — reorder if cleaner. DECISION: build tax_rates first (Task 3 becomes Task 2). See ordering note.) is_archived, timestamps. Unique (company_id, name) — optional; skip if names can repeat.

> **Ordering note:** build **Tax rates (Task 3) before Items** so the `default_tax_rate_id` FK is clean. Implementer: do Task 3 first, then this task. (Kept numbered by domain for readability.)

**Controller/routes** (gate `items.manage`): CRUD + archive. Item's `default_income_account_id` picker excludes archived + must be an income account (validate subtype/type). `unit_price` via `parseMoneyInput` on the client → integer minor units.

**UI:** Items/Index — sheet, table (name, default price MoneyText, default income account, default tax), New/edit modal. Tokens; no debit/credit.

**Tests:** CRUD; unit_price stored as minor units exactly (odd paisa); income-account validation; tax-rate link; archive; gate; scoped. Commit.

---

### Task 3: Tax rates

**Files:** migration `create_tax_rates_table`; `app/Models/TaxRate.php`; `TaxRateController`, requests; `resources/js/Pages/Settings/TaxRates.tsx` (or a section); routes; `tests/Feature/TaxRatesTest.php`.

**Tax rates table:** id, company_id, name (60, e.g. "GST 18%"), rate DECIMAL(7,4) (percent, e.g. 18.0000), is_archived, timestamps. **The rate value is copied onto document lines at creation** (spec §7.6) — editing a rate never changes historical documents. No posting here; tax rates are reference data consumed by invoices/expenses later.

**Rounding rule (documented, tested when consumed):** tax is computed per line, rounded half-up to the minor unit, then summed. Phase 2 only stores the rate; the calculation lands with invoicing. Add a pure helper `app/Support/Money.php::taxMinorUnits(int $lineNetMinor, string $ratePercent): int` = round half-up of `lineNetMinor * rate / 100` using integer/BC math (no float) — with tests for awkward values (e.g. 3333 @ 18% = 600 (rounded from 599.94), and the classic 3×33.33 case). This helper is built now and unit-tested so invoicing can rely on it.

**Controller/routes** (gate `tax_rates.manage`): CRUD + archive. **rate validated** 0–100, up to 4 decimals.

**UI:** simple list + modal under Settings. Tokens.

**Tests:** CRUD; rate validation; `taxMinorUnits` helper half-up rounding on awkward values (no float — assert exact integers); archive; gate; scoped. Commit.

---

### Task 4: Seed FX Gain/Loss accounts + currency foundation

**Files:** modify `app/Services/ChartOfAccountsSeeder.php` (add 4950 FX Gain, 5950 FX Loss as system accounts); migration `add_currency_to_bank_accounts` is deferred to Task 5 (bank accounts don't exist yet) — instead this task: extend the seeder + add `app/Console/Commands/BackfillFxAccounts.php` to add the two accounts to already-existing companies; `tests/Feature/FxAccountsSeedTest.php`.

**Seeder additions:** 4950 "FX Gain" (income/other_income, is_system), 5950 "FX Loss" (expense/operating_expense, is_system). New companies get 25 accounts now.

**Backfill command:** `sparkbooks:backfill-fx-accounts` — for each existing company (context via runFor), if 4950/5950 absent, create them (system). Idempotent (safe to re-run). Audit-log. Run it once against the dev DB in the exit-verification task.

**Tests:** a newly-onboarded company has 25 accounts incl. 4950/5950 as system; backfill command adds them to a company missing them and is idempotent (second run = no dupes); system flags correct. Commit. (Note: this changes the "23 accounts" assertions from Phase 1 — update `ChartOfAccountsTest`/`AccountsTest` counts to 25.)

---

### Task 5: Bank & cash accounts + linked ledger accounts

**Files:** migration `create_bank_accounts_table`; `app/Models/BankAccount.php`; `app/Services/BankAccountService.php`; `BankAccountController`, requests; `resources/js/Pages/Banking/Index.tsx`; routes; `tests/Feature/BankAccountsTest.php`.

**Bank accounts table:** id, company_id, name (120), type ('bank'|'cash'), currency_code (char 3, **constrained to the company base_currency in v1** — validate on create), ledger_account_id FK→accounts (the auto-created linked CoA account), opening_balance BIGINT minor units (default 0; the actual opening posting is Task 8 via P8a — here just store the intended figure or leave to Task 8; prefer: bank account creation does NOT post; opening balances are set in the guided setup Task 8), is_archived, timestamps.

**BankAccountService::create:** in a transaction — create the `bank_accounts` row AND auto-create its linked CoA `Account` (next free code in the 1000–1099 band, subtype cash/bank, is_system=false but not user-deletable while the bank account exists), link them. No ledger posting at creation (opening balances are Task 8). Balance shown for a bank account = the ledger balance of its linked account (reuse `AccountBalances`).

**Controller/routes** (gate `bank_accounts.manage`): index (list with balances), store, update (rename), archive. Currency must equal base currency (v1). Audit-log.

**UI:** Banking/Index — cards or sheet per account (name, type, balance MoneyText, currency). New account modal (name, type, currency defaulted+locked to base). Tokens; no debit/credit words.

**Tests:** create bank account auto-creates a linked CoA account (right subtype, band); balance reflects ledger; currency must be base (USD rejected in v1 with clear message); archive; gate; scoped. Commit.

---

### Task 6: Transfers between money accounts (posting rule P11)

**Files:** migration `create_transfers_table`; `app/Models/Transfer.php`; `app/Services/TransferService.php`; `TransferController`, `StoreTransferRequest`; `resources/js/Pages/Banking/Transfer.tsx` (or modal); routes; `tests/Feature/TransfersTest.php`.

**Transfers table:** id, company_id, from_bank_account_id, to_bank_account_id, transfer_date, amount BIGINT minor units, memo nullable, created_by, timestamps.

**TransferService::transfer(idempotencyKey, data):** `IdempotencyService::execute` → `DB::transaction` → create Transfer doc → `LedgerService::post(EntryDraft(sourceType:'transfer', lines:[Dr to-account's ledger account, Cr from-account's ledger account]))` → audit 'transfer.created'. **Posting rule P11:** Dr destination, Cr source (spec §6). from ≠ to (validate). Amount > 0.

**Controller/routes** (gate `transfers.create`): create form + store. Both accounts must belong to the company (scoped). Locked-period check inherited from LedgerService.

**UI:** Banking transfer — from/to selects, date, amount (parseMoneyInput), memo. Tokens.

**Tests:** transfer posts P11 exactly (Dr to, Cr from) via LedgerService; from==to rejected; both sides appear in each account's ledger/reconciliation; amount>0; idempotent double-submit; locked period → 422; gate; scoped; AssertsLedgerBalanced. Commit.

---

### Task 7: Other receipts — money in that isn't an invoice (posting rule P14)

**Files:** migration `create_other_receipts_table`; `app/Models/OtherReceipt.php`; `app/Services/OtherReceiptService.php`; `OtherReceiptController`, request; `resources/js/Pages/Banking/OtherReceipt.tsx`; routes; `tests/Feature/OtherReceiptsTest.php`.

**Other receipts table:** id, company_id, bank_account_id (deposit account), receipt_date, amount minor units, category_account_id (the credit side — equity/income/other non-control account), payer nullable, memo nullable, created_by, timestamps.

**OtherReceiptService::record:** idempotency → transaction → doc → `LedgerService::post(sourceType:'other_receipt', lines:[Dr deposit bank account, Cr category account])` → audit. **Posting rule P14:** Dr bank, Cr selected non-control account (owner contribution → 3000, misc income → 4900, etc.). Category picker EXCLUDES AR/AP control accounts and bank/cash accounts (those are transfers). Amount>0.

**Controller/routes** (gate `other_receipts.create`). Scoped.

**UI:** deposit account, date, amount, category picker (filtered), payer, memo. Tokens.

**Tests:** P14 posts exactly (Dr bank, Cr category); control/bank accounts excluded from category picker (server-validated → 422 if spoofed); amount>0; idempotent; locked period; gate; scoped; balanced. Commit.

---

### Task 8: Opening balances — guided setup (posting rules P8a/P8b/P8c) + per-contact opening documents

**Files:** migration `create_opening_balance_runs_table`; migrations for opening invoice/bill flags OR reuse (invoices/bills don't exist yet — so opening customer/vendor balances need a lightweight opening-document representation). **DECISION for Phase 2:** since invoices/bills aren't built until Phase 3/4, opening AR/AP is represented as **opening-balance documents** in a dedicated `opening_documents` table (id, company_id, opening_balance_run_id, contact_id, kind 'receivable'|'payable', reference, original_date, due_date, amount minor units) so aging (Phase 5) can bucket by due_date and payments (Phase 3+) can settle them. `app/Models/{OpeningBalanceRun,OpeningDocument}.php`; `app/Services/OpeningBalanceService.php`; `OpeningBalanceController`, request; `resources/js/Pages/Setup/OpeningBalances.tsx`; routes; `tests/Feature/OpeningBalancesTest.php`.

**Opening balance flow (spec §7.1, §6 P8a–c):**
- Setup wizard captures: bank/cash opening balances (per bank account); open customer invoices (per contact: reference, original date, due date, amount); open vendor bills (same). Company `books_start_date` is set here (add to company if not present — it exists per spec §7.1; confirm/add column).
- `OpeningBalanceService::apply(run data)`: idempotency → transaction → create an `opening_balance_runs` row (the source) → create `opening_documents` per open AR/AP item → post ONE ledger entry (sourceType 'opening_balance_run', sourceId = run id) with lines: **P8a** Dr each bank/cash ledger account (opening balance); **P8b** Dr 1100 AR per customer opening doc (with contact_id!); **P8c** Cr 2000 AP per vendor opening doc (with contact_id!); balancing line: 3100 Opening Balance Equity (the plug). **Every AR/AP line carries contact_id** (spec §6 note — this is what makes aging tie out).
- **Re-run:** allowed until the first real (non-opening) transaction. Re-running VOIDS the prior run (reversal entry for its P8 entry; opening_documents of the prior run marked void) and creates a NEW run + new opening documents. Never edits/duplicates on the same source (respects §17.2 unique index). "First real transaction" = first posted entry whose source is neither an opening_balance_run nor an opening document.

**Controller/routes** (gate `settings.manage` — this is company setup; Owner/Admin). Scoped.

**UI:** Setup/OpeningBalances — a guided form: books start date; a repeatable list for bank balances; a repeatable list for customer opening invoices (contact, reference, date, due date, amount); same for vendor bills. Preview the resulting Opening Balance Equity. Submit posts P8. Show a "re-run resets and re-enters" note. Tokens.

**Tests (TDD — this is high-value):** applying opening balances posts P8a/b/c with EXACT lines and the OBE plug balancing; **every AR/AP opening line carries contact_id**; the entry balances; re-run voids prior + re-posts (no duplicate on same source → unique index respected, prior reversed); books_start_date stored; opening documents carry due dates; a company with a real transaction cannot re-run (guard); gate; scoped; AssertsLedgerBalanced. Commit.

---

### Task 9: Opening data CSV templates (spec §17.7)

**Files:** `app/Http/Controllers/OpeningCsvController.php` (download templates + validate-upload into the wizard); `app/Services/OpeningCsvParser.php`; sample template generation; wire into Setup/OpeningBalances.tsx; `tests/Feature/OpeningCsvTest.php`.

**Scope (NOT a general import):** downloadable CSV templates accepted ONLY by the opening-setup wizard: (a) customers, (b) vendors, (c) open customer invoices (contact ref, reference, original date, due date, amount), (d) open vendor bills, (e) bank/cash accounts with starting balances. Per-row validation with a preview; each file imports all-or-nothing (a bad row rejects the whole file with line-numbered errors). Feeds the existing P8a–c flow from Task 8.

**Controller/routes** (gate `settings.manage`): GET template downloads (CSV with headers + one example row); POST validate-and-stage (returns parsed rows + per-row errors for the wizard to confirm, does NOT post directly — the wizard's submit posts via Task 8's service). Money columns parsed via integer string-math (reuse the money helper server-side — add a PHP `Money::parseToMinor(string): ?int` mirroring the TS parser, no float).

**Tests:** template download has correct headers + Content-Type; valid CSV parses to expected rows; a bad row (bad date, non-numeric amount, >2 decimals, unknown contact) rejects the whole file with the line number; amounts parse to exact minor units (odd paisa, no float); gate; scoped. Commit.

---

### Task 10: Sidebar nav + dashboard wiring for the new modules

**Files:** modify `resources/js/Layouts/AppShell.tsx` (add nav: Sales→Contacts/Items?, Banking→Bank accounts/Transfers, Setup→Opening balances, Settings→Tax rates); modify `resources/js/Pages/Dashboard.tsx` (light: show cash position = sum of bank/cash balances, # customers/vendors — real widgets are Phase 5, keep it minimal but wire the counts). `tests/Feature/` as needed for any new controller data.

**Nav groups (final Phase-2 shape):** Overview→Dashboard; Contacts→Contacts, Items; Books→Chart of accounts, Journal entries, Banking (bank accounts/transfers/other receipts); Reports→Trial balance; Settings→Tax rates, Books (period lock), Health, Opening balances (or under a Setup group). Keep active-state handling; prefetch-on-hover. Design tokens.

**Dashboard:** cash position (sum of bank/cash account balances via AccountBalances) + counts, as simple stat tiles using the design system. No debit/credit words. Empty-state friendly.

**Tests:** dashboard loads with cash position computed from bank accounts; nav renders new groups; gates respected (a Staff user sees only what they can access). Commit.

---

### Task 11: Extend integrity checker for Phase-2 data

**Files:** modify `app/Services/IntegrityChecker.php`; `tests/Feature/IntegrityCheckerTest.php` additions.

**New checks (per spec §9, now that more source types exist):**
- (e-lite extended) every ledger entry's source document exists: extend the orphan check to cover source_types 'transfer', 'other_receipt', 'opening_balance_run' (each must reference an existing row in its table). Keep it correct for future types (unknown source_type → flag as unrecognized rather than silently pass).
- (f still) control-account lines carry contact_id — now backed by the FK too.
- Confirm the vacuous guard still holds with the new tables.

**Tests:** raw-orphan a transfer/other_receipt/opening entry → detected; a valid multi-source company → clean; unknown source_type flagged; vacuous guard intact. Commit.

---

### Task 12: Phase 2 exit verification + PROJECT_MAP update

**Files:** none (verification) + `docs/PROJECT_MAP.md` update + run the FX backfill on dev DB.

- [ ] Full `composer test` + `npm run test:js` + `composer lint:check` + the no-float CI grep — all green.
- [ ] Run `php artisan sparkbooks:backfill-fx-accounts` against the dev DB so existing dev companies get 4950/5950.
- [ ] Browser walkthrough on a FRESH company (dev DB has cruft on old ones): create a customer + a vendor; create a bank account and a cash account; do a transfer between them and confirm both sides in the account ledgers; record an other-receipt (owner contribution); run the opening-balances wizard with one customer opening invoice + one vendor bill + a bank opening balance, confirm P8 posts and OBE balances and the AR/AP lines carry the contact; open Trial Balance and confirm it still balances with all the new activity; run the integrity checker → clean.
- [ ] Update `docs/PROJECT_MAP.md`: §2 status table (mark Phase 2 items ✅), §5 dependency graph (add the new services), §8 file tree (add new files), §7 currency (note FX accounts seeded). Commit "Phase 2 exit verification + project map update".
- [ ] Confirm spec §13 Phase 2 exit criteria met (opening balances post per-contact; balances visible on CoA; the §17.1 currency decision recorded — already done).

---

## Self-Review Notes

- **Spec coverage (Phase 2 row §13):** contacts (T1), items (T2), tax rates (T3), bank/cash accounts (T5), transfers + other receipts (T6, T7), opening-balance setup P8a–c per-contact (T8) + CSV templates (T9). FX foundation (T4). Nav/dashboard (T10). Checker extension (T11). Exit (T12).
- **Ordering:** build **T3 (tax rates) before T2 (items)** so the item→tax FK is clean (noted in T2). T1 contacts first (unblocks the contact_id FK + opening docs). T4 before T5 (FX accounts exist before banking). T8 depends on T1 (contacts) + T5 (bank accounts).
- **Multi-currency:** Phase 2 pins the schema/semantics and seeds FX accounts; the actual FX posting (P1fx/P2fx) is exercised by invoicing in Phase 3 (spec §17.1). No foreign-currency postings occur in Phase 2 (transfers/receipts/opening are base-currency), so no FX gain/loss arises yet — correct.
- **Carry-ins addressed:** contact_id FK (T1); integrity-checker source coverage (T11); PROJECT_MAP kept current (T12). Deferred still: collapse double runFor in checker (small, do in T11 if convenient); Owner-vs-Admin ability doc (add a note to PROJECT_MAP in T12).
- **No placeholders:** posting rules (P8/P11/P14) have exact debit/credit specs from spec §6; the tax rounding helper is fully specified with test cases; opening-balance re-run semantics match §7.1.
