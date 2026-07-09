# SparkBooks Phase 3 — Invoicing (money in) incl. USD/FX-lite

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development. Steps use checkbox (`- [ ]`) syntax. Update `docs/PROJECT_MAP.md` in the final task.

**Goal:** The full "money in" side — create/send invoices, take full & partial payments, void the accounting-correct way, bill in **PKR or USD** (FX-lite with correct gain/loss), email a clean PDF with decoupled delivery status, and make Phase 2's opening receivables payable. Every posting goes through the `LedgerService` gate.

**Architecture:** Same spine (PROJECT_MAP §4/§6). New services (`InvoiceService`, `InvoicePaymentService`) build `EntryDraft`s and post through `LedgerService` inside `IdempotencyService::execute` + `DB::transaction`. Reports still read-only from `journal_lines`. FX: documents carry `currency_code` + `fx_rate` + document-currency amounts + `base_*` mirrors (spec §3); the ledger always balances in base currency; FX gain/loss on payment posts to the seeded 4950/5950 accounts.

**Tech Stack:** as Phase 2 + `barryvdh/laravel-dompdf` (PDF), Laravel queue (email) — sync driver in dev, documented for a real queue at deploy.

## Global Constraints

- **Shell env (every Bash session):** `export PATH="/c/laragon/bin/php/php-8.3.30-Win32-vs16-x64:/c/laragon/bin/composer:/c/laragon/bin/nodejs/node-v22:/c/laragon/bin/mysql/mysql-8.4.3-winx64/bin:$PATH"`; work in `/c/laragon/www/SparkBooks`, branch `phase-3-invoicing`.
- Spec: `docs/superpowers/specs/2026-07-08-accounting-software-v1-design.md` §6 (P1/P2/P3/P9), §7.7 (invoicing lifecycle), §17.1 (FX-lite P1fx/P2fx — the exact posting shapes), §17.2 (idempotency + entry-role unique index), §17.6 (email/PDF decoupling), §3 (currency column semantics), §9 (integrity check c split).
- **All prior iron rules hold** (PROJECT_MAP §9). Money = BIGINT minor units, no float (CI-enforced); `company_id` never fillable/from-request; every ledger write via `LedgerService::post`; posted entries immutable → corrections are reversals; every mutating route gated + audit-logged; controllers resolve `{model}` via scoped `findOrFail`; design tokens only; **debit/credit words never on user-facing invoice screens** (invoices are a customer/owner screen — use plain language; the ledger entries they create are the accountant's view).
- **AR lines always carry contact_id** (LedgerService enforces). Invoice AR relief on payment carries the invoice's contact.
- **Invoice numbering:** per-company counter read `FOR UPDATE` inside the send transaction — no gaps, no duplicates. Drafts have no number.
- **Overpayment rejected** (row-locked balance re-read, spec §6 note). **Void after partial payment:** payments reversed first.
- Commit after every task with trailer `Co-Authored-By: Claude Fable 5 <noreply@anthropic.com>`. Strict TDD for all posting/money logic on real MySQL. Update `PROJECT_MAP.md` in Task 13.

### Currency model (spec §3 + §17.1) — pins for this phase

- `invoices` carry: `currency_code` (char 3, = base for domestic), `fx_rate` DECIMAL(16,8) (rate to base at send; 1.0 for domestic), document-currency amounts (`subtotal`, `tax_total`, `total`, `amount_paid`, `balance`) **and** base mirrors (`base_subtotal`, `base_tax_total`, `base_total`). Journal lines store **base-currency** amounts only.
- **Conversion rounding (P1fx):** each component (per line net, per line tax) converts to base and rounds half-up (reuse `Money`); `base_total` = SUM of converted components; the AR debit equals that sum by construction so the entry balances. **Tax is a base-currency liability from send — never re-measured** (payment FX affects only the bank/AR/FX-gain-loss, not Tax Payable).
- **Settlement rounding (P2fx):** a partial payment relieves base AR by `round(invoice_fx_rate × amount_paid_doc)`; the **final** payment (that zeroes the document-currency balance) relieves the **entire remaining base AR balance** for that invoice — the FX gain/loss plug absorbs the difference, so no phantom base-AR residue survives a fully paid FX invoice.
- v1 keeps foreign-currency **bank accounts** out of scope — a USD invoice is paid into a base-currency bank account; the user enters the **actual base amount the bank credited** (spec §17.1 P2fx), and the difference vs. the invoice-rate AR relief is the realized FX gain/loss.

---

### Task 1: Invoice schema, numbering counter, models, status enum

**Files:** migrations `create_document_counters_table`, `create_invoices_table`, `create_invoice_lines_table`, `create_invoice_payments_table`; `app/Enums/InvoiceStatus.php`; `app/Models/{Invoice,InvoiceLine,InvoicePayment}.php`; `app/Services/DocumentNumberService.php`; factories; `tests/Feature/DocumentNumberTest.php`, `tests/Feature/InvoiceModelTest.php`.

**document_counters:** id, company_id (FK restrict), series string(10) ('INV', later 'BILL'/'OB'), next_number unsignedBigInteger default 1, unique(company_id, series).

**DocumentNumberService::next(string $series, string $prefix): string** — inside the CALLER's transaction, `SELECT ... FOR UPDATE` the counter row (create at 1 if absent — use `lockForUpdate` + `firstOrCreate` carefully, or an upsert then locked read), format `<prefix>-<zero-padded 4+>` (e.g. INV-0001), increment, return. **Must be called inside a transaction** (throw if `transactionLevel()===0`, like LedgerService). Gap-free + duplicate-free under concurrency.

**invoices:** id, company_id (FK restrict), contact_id (composite tenant FK → contacts, like journal_lines), number string(20) nullable (null until sent), status string(16) ('draft'|'sent'|'partially_paid'|'paid'|'void'), issue_date date nullable, due_date date nullable, currency_code char(3), fx_rate DECIMAL(16,8) default 1, subtotal/tax_total/total/amount_paid/balance BIGINT (document currency), base_subtotal/base_tax_total/base_total BIGINT (base mirrors), is_opening bool default false, notes text nullable, created_by nullable FK, sent_at/voided_at timestamps nullable, email_status string(12) default 'not_sent' (Task 10 uses it), timestamps. Index (company_id, status), (company_id, contact_id). Unique(company_id, number) where number not null (partial unique — MySQL: a generated/nullable unique index; nulls allowed multiple).

**invoice_lines:** id, invoice_id (FK cascade — lines belong to draft, deletable while draft), company_id, description string(255), quantity DECIMAL(12,4) default 1, unit_price BIGINT (doc currency minor units), income_account_id FK→accounts (restrict), tax_rate_id nullable FK→tax_rates (restrict), tax_rate_snapshot DECIMAL(7,4) nullable (the rate COPIED at send, spec §7.6), line_subtotal BIGINT, line_tax BIGINT (doc currency), timestamps.

**invoice_payments:** id, company_id, invoice_id (FK restrict), payment_date date, amount BIGINT (doc currency), base_amount BIGINT (actual base received), bank_account_id FK→bank_accounts (restrict), fx_rate DECIMAL(16,8) nullable (payment-date rate, for FX), status string(10) default 'posted' ('posted'|'void'), memo nullable, created_by nullable FK, timestamps.

**InvoiceStatus enum:** Draft/Sent/PartiallyPaid/Paid/Void + helpers `isPosted()` (sent|partially_paid|paid), `canEdit()` (draft only), `canReceivePayment()` (sent|partially_paid), `canVoid()` (sent|partially_paid|paid).

Models: `BelongsToCompany`; `$fillable` excludes company_id; casts. Invoice: hasMany lines, payments; belongsTo contact. Draft-mutability guard: an Invoice that `isPosted()` throws on line edits (a model/service guard — sent invoices are frozen; correction = void+reissue).

**Tests (TDD):** DocumentNumberService gives INV-0001, INV-0002… gap-free; unique per (company, series); two series independent; foreign-company counter isolated; throws outside a transaction. Invoice model status helpers; is_opening default false; posted invoice rejects line edit. Commit.

---

### Task 2: Invoice draft CRUD + line editor logic + tax calc hardening

**Files:** `app/Http/Controllers/InvoiceController.php` (index/create/store/edit/update/destroy for DRAFTS), `StoreInvoiceRequest`, `UpdateInvoiceRequest`; `app/Services/InvoiceCalculator.php` (pure: given lines + currency, compute per-line subtotal/tax and document totals — no DB, no float); harden `app/Support/Money::taxMinorUnits` (add the carried negative/5-decimal guard); `resources/js/Pages/Invoices/{Index,Edit}.tsx` + `resources/js/Components/invoices/InvoiceLineEditor.tsx`; routes; `tests/Feature/InvoiceDraftTest.php`, `MoneyTest` additions.

**Money::taxMinorUnits hardening (carried from Phase 2):** throw `InvalidArgumentException` if `lineNetMinor < 0` or the rate string has >4 decimal places or is negative — so invoicing (the first real caller) can't silently mis-round. Add tests.

**InvoiceCalculator::compute(array $lines, ...): array** — per line: `line_subtotal = round(quantity × unit_price)` (quantity is DECIMAL up to 4 dp — do this with integer/bc math: unit_price is minor units, quantity has ≤4 dp → `Money`-style scaled multiply, half-up); `line_tax = Money::taxMinorUnits(line_subtotal, rate)`. Document `subtotal = Σ line_subtotal`, `tax_total = Σ line_tax`, `total = subtotal + tax_total`. All integer minor units, no float. (Base mirrors computed at send in Task 3/7 using fx_rate.) Add `Money::multiplyByQuantity(int $minor, string $qty): int` (half-up, integer/bc) with awkward-value tests.

**Draft CRUD** (gate `invoices.draft` — Owner/Admin/Accountant/Staff per §7.14; Staff CAN draft): create/edit/delete only when status=draft. Line items: description, quantity, unit_price (client sends minor units via parseMoneyInput), income_account_id (must be income-type non-archived account — validate like items), tax_rate_id (optional, tenant active rate), or pull defaults from an item_id. contact_id required (a customer/both contact). currency_code (base or a foreign currency — validate against Currencies; fx handled at send). Store denormalized doc totals via InvoiceCalculator. NO number, NO ledger effect on draft. Audit-log draft.created/updated/deleted.

**UI:** Invoices/Index (list: number-or-"Draft", contact, date, total MoneyText, status chip, filters by status); Invoices/Edit (draft editor: contact picker, currency, issue/due dates, InvoiceLineEditor [rows: item/description, qty, unit price, income account, tax rate, line total live], live subtotal/tax/total via a client mirror of InvoiceCalculator or a debounced server recompute; "Save draft" + "Send" [Send → Task 3]). Design tokens; sentence case; **no debit/credit words** (customer screen). MoneyText everywhere.

**Tests (TDD):** draft create/edit/delete; totals computed exactly (multi-line, taxed/untaxed mix, odd paisa, quantity like 2.5); income-account + tax-rate validation; only drafts editable (posted → 422/guard); tax hardening (negative/5-decimal → exception); gate (all four roles can draft); scoped. Commit.

---

### Task 3: Send invoice (P1, domestic PKR) — assign number, post AR

**Files:** `app/Services/InvoiceService.php` (`send`), controller `send` action + route; `tests/Feature/InvoiceSendTest.php`.

**InvoiceService::send(string $idempotencyKey, Invoice $draft): Invoice** — `IdempotencyService::execute(key, 'invoice.sent', fn)` → `DB::transaction`:
- Guard: status must be draft (else 422); must have ≥1 line; contact is a customer/both.
- Assign `number = DocumentNumberService::next('INV','INV')` (FOR UPDATE).
- Copy each line's current tax rate into `tax_rate_snapshot` (rate frozen at send, spec §7.6).
- Compute base mirrors: for domestic (fx_rate 1.0) base_* = doc amounts. (FX in Task 7.)
- **Post P1** via LedgerService (sourceType 'invoice', sourceId invoice->id): **Dr 1100 AR (base_total, contactId = invoice.contact_id)**; per line **Cr line.income_account_id (base line_subtotal)**; **Cr 2100 Tax Payable (base_tax_total)** if tax_total>0. (Entry balances: AR = Σincome + tax.)
- Set status 'sent', sent_at, issue_date (default today if unset), freeze. Recompute amount_paid=0, balance=total.
- AuditLogger 'invoice.sent'. Return invoice.

**Tests (TDD):** send posts P1 EXACTLY — assert AR debit = total with contact_id, each income line credit, tax payable credit; entry balances (AssertsLedgerBalanced); number assigned INV-0001, gap-free across two sends; draft→sent freeze (can't edit after); tax_rate_snapshot copied; contact appears on the AR line; overpayment N/A here; idempotent double-send → one entry, one number (assert no number burned on replay); a draft with no lines → 422; sending a non-draft → 422; gate invoices.send (Staff 403; Owner/Admin/Accountant ok); locked period → 422; scoped. Commit.

---

### Task 4: Record payment (P2/P3, domestic) — clear AR, statuses

**Files:** `InvoiceService::recordPayment` (or `app/Services/InvoicePaymentService.php`), controller action + route, `StorePaymentRequest`; `tests/Feature/InvoicePaymentTest.php`.

**recordPayment(string $key, Invoice $invoice, array $data): InvoicePayment** — idempotency → transaction:
- **Row-lock the invoice** (`Invoice::where(id)->lockForUpdate()->first()`) and re-read amount_paid/balance/status (the overpayment guard must be on a locked read, spec §9).
- Guard: status sent|partially_paid; amount (doc currency) integer ≥1; **amount ≤ outstanding balance** (overpayment rejected with a clear 422); bank_account tenant non-archived; payment_date valid (locked-period enforced by LedgerService).
- Create InvoicePayment doc (base_amount for domestic = amount).
- **Post P2/P3** (sourceType 'invoice_payment', sourceId payment->id): **Dr bank_account.ledger_account_id (amount)**; **Cr 1100 AR (amount, contactId = invoice.contact_id)**.
- Update invoice: amount_paid += amount, balance = total − amount_paid; status → paid if balance==0 else partially_paid.
- AuditLogger 'invoice.payment_recorded'. Return payment.

**Tests (TDD):** full payment (P2) → Dr bank/Cr AR exact, status paid, balance 0; partial (P3) → status partially_paid, balance correct; multiple partials sum to paid; overpayment rejected (422, nothing posted); concurrent double-payment cannot overpay (real-MySQL parallel test like the idempotency one, or a locked-read assertion — do the honest version); payment clears AR (AR balance for the contact returns toward 0); AR line carries contact_id; **payment against an OPENING invoice works** (needs Task 6 — cross-reference; at minimum assert a plain sent invoice pays); idempotent; gate payments.record (Staff 403); locked period; scoped. AssertsLedgerBalanced. Commit.

---

### Task 5: Void invoice (P9) — reverse, payments-first

**Files:** `InvoiceService::void` + `voidPayment`, controller actions + routes; `tests/Feature/InvoiceVoidTest.php`.

**void(string $key, Invoice $invoice, string $date, string $memo)** — idempotency → transaction:
- Guard: status sent|partially_paid|paid; **if any non-void payments exist, REFUSE with a clear message listing them** ("Reverse the N payment(s) first") — OR auto-reverse them (decide: spec §6 says "payments must be voided before the invoice"; implement REFUSE-until-payments-voided, with a separate `voidPayment` action, so the user consciously reverses each — matches spec). 
- `voidPayment(key, InvoicePayment)`: reverse the payment's P2/P3 entry (LedgerService::reverse), mark payment 'void', recompute invoice amount_paid/balance/status back toward sent. Audit 'invoice.payment_voided'.
- Once no live payments: reverse the invoice's P1 entry (LedgerService::reverse → P9), status 'void', voided_at. Audit 'invoice.voided'.
- Reversal date defaults to today (locked-period safe); if the original is in a locked period the reversal still posts in the open period (spec §4 rule 4).

**Tests (TDD):** void a sent (unpaid) invoice → P9 reversal (mirror of P1), status void, AR back to 0; void with a live payment → REFUSED until payment voided; voidPayment reverses P2/P3, invoice returns to sent/partially_paid; then void succeeds; second void → 422 (unique index / status guard); reversal posts in open period when original is locked; gate invoices.void; idempotent; scoped. AssertsLedgerBalanced. Commit.

---

### Task 6: Opening-invoice reconciliation — make Phase 2 opening receivables payable

**Files:** `app/Services/OpeningInvoiceReconciler.php` + artisan `sparkbooks:reconcile-opening-invoices` (one-off, idempotent) OR fold into OpeningBalanceService going forward; migration if opening_documents need an `invoice_id` link; `tests/Feature/OpeningInvoiceTest.php`.

**The reconciliation (whole-branch carry-in):** Phase 2 stored opening customer receivables as `opening_documents` (kind=receivable) with the AR already posted (P8b). Phase 3 must let payments settle them. Approach (spec §6 P8b intends opening docs to BE invoices): for each open `opening_documents` of kind receivable, create a corresponding `Invoice` with `is_opening=true`, status 'sent', number from an `OB` series (or its reference), contact_id, issue_date=original_date, due_date, total=amount, currency=base, **but NO new ledger posting** (the AR was already posted by P8b — this invoice just represents the existing receivable so payments can apply to it). Link opening_document.invoice_id. Going forward, OpeningBalanceService creates these is_opening invoices directly instead of bare opening_documents (refactor P8b to create is_opening invoices; keep opening_documents or replace — DECIDE: cleanest is OpeningBalanceService creates is_opening invoices for receivables and keeps opening_documents only for payables until Phase 4 bills). 
- A payment on an is_opening invoice posts P2/P3 (Dr bank / Cr AR with contact) exactly like a normal invoice — no special-casing needed in Task 4.

**Tests (TDD):** an is_opening invoice created from an opening receivable is payable → recording a payment posts Dr bank/Cr AR (clears the opening AR for that contact), status → paid; the is_opening invoice creates NO duplicate income/AR posting (the opening entry already did); opening balance re-run (Phase 2) still works and now produces is_opening invoices; the aging-relevant due_date carries onto the invoice; scoped. AssertsLedgerBalanced. Commit. **Update OpeningBalancesTest** if the opening flow now creates invoices.

---

### Task 7: FX-lite invoice send (P1fx) — foreign-currency invoices

**Files:** `InvoiceService::send` extended for fx; `app/Services/FxConverter.php` (pure: convert doc minor units → base via fx_rate, component-wise half-up, no float — bc math); `tests/Feature/InvoiceFxSendTest.php`.

**When currency_code != base:** at send, capture `fx_rate` (from request — user-entered/editable). Compute base mirrors via **FxConverter**: `base_line_subtotal[i] = round_half_up(line_subtotal[i] × fx_rate)`, `base_line_tax[i] = round_half_up(line_tax[i] × fx_rate)`; `base_subtotal = Σ base_line_subtotal`, `base_tax_total = Σ base_line_tax`, `base_total = base_subtotal + base_tax_total`. **P1fx** posts base amounts: **Dr 1100 AR base_total (contact)**; per line **Cr income base_line_subtotal**; **Cr 2100 Tax Payable base_tax_total**. Tax Payable is now a base-currency liability, fixed (never re-measured). Document-currency amounts stored as-entered; base mirrors as computed. **Gate A note:** the official-rate-for-tax question (FBR) is deferred with the accountant — for now the single send fx_rate applies to all components.

**Tests (TDD):** a USD 1,000 + 18% invoice @ 280 posts Dr AR 330,400 / Cr Income 280,000 / Cr Tax Payable 50,400 (exact, from spec §17.1); entry balances in base; conversion rounding component-wise (a multi-line USD invoice where naive round(total×rate) ≠ Σ round(component×rate) — assert the base_total = Σ components and the entry still balances, the awkward case); doc-currency amounts preserved; fx_rate stored; fx_rate required when currency != base (422 if missing); a domestic invoice still fx_rate=1 base=doc; scoped. AssertsLedgerBalanced. Commit.

---

### Task 8: FX-lite payment (P2fx) — realized gain/loss + settlement rounding

**Files:** `InvoiceService::recordPayment` extended for fx; `tests/Feature/InvoiceFxPaymentTest.php`.

**When the invoice is foreign-currency:** the payment captures the **actual base amount the bank credited** (user enters it; the implied rate is shown). 
- Doc-currency `amount` (in invoice currency) reduces the invoice balance as usual.
- **AR relief:** for a partial payment, `base_ar_relief = round_half_up(invoice.fx_rate × amount)`; for the **final** payment (that brings doc balance to 0), `base_ar_relief = remaining base AR for this invoice` (= base_total − Σ prior base_ar_relief) — so base AR zeroes exactly.
- **Bank debit = actual base received** (from request).
- **FX plug:** `diff = base_received − base_ar_relief`; if diff>0 → **Cr 4950 FX Gain** (diff); if diff<0 → **Dr 5950 FX Loss** (−diff). 
- **P2fx** posts: **Dr bank (base_received)**; **Cr 1100 AR (base_ar_relief, contact)**; **FX gain/loss plug line**. Entry balances.
- Overpayment still checked in document currency (amount ≤ doc balance).

**Tests (TDD):** USD 1,000 invoice @ 280 (AR base 280,000), paid in full, bank credits 285,000 → Dr Bank 285,000 / Cr AR 280,000 / Cr FX Gain 5,000 (spec §17.1 example); a loss case (bank credits less) → Dr FX Loss; partial then final payment → base AR ends exactly 0 (no phantom paisa — the settlement-rounding property, assert base AR balance for the contact is exactly 0 after final); FX gain/loss lands on 4950/5950; tax payable untouched by payment FX; entry balances; overpayment (doc currency) rejected; idempotent; scoped. AssertsLedgerBalanced. Commit.

---

### Task 9: Invoice PDF

**Files:** add `barryvdh/laravel-dompdf` (composer); `app/Services/InvoicePdf.php`; `resources/views/pdf/invoice.blade.php`; controller `pdf` action (stream/download) + route; `tests/Feature/InvoicePdfTest.php`.

**PDF:** clean branded invoice — company name/logo placeholder, invoice number, dates, contact, line table (description, qty, unit price, line total), subtotal/tax/total, **dual-currency display for FX** (document currency amounts + a "≈ base" line). Uses the design palette (ink/spark) in print CSS. Money rendered via a PHP formatter matching `formatMoney` (2 decimals, currency). No float in amounts (they're already minor units → decimal string).

**Tests:** pdf route returns application/pdf, 200, for a sent invoice; contains the invoice number + total (assert on the PDF text or the view render); a draft has no number → the PDF shows "DRAFT"; gate (reports.view or invoices.draft — a viewer of the invoice); scoped (foreign invoice 404). Commit.

---

### Task 10: Email with decoupled delivery status (§17.6)

**Files:** migration `create_email_attempts_table` (or reuse); `app/Models/EmailAttempt.php`; `app/Mail/InvoiceMail.php`; `app/Jobs/SendInvoiceEmail.php`; `InvoiceService::sendEmail` + `resendEmail`; controller actions + routes; `tests/Feature/InvoiceEmailTest.php`.

**Decoupling (spec §17.6):** sending the invoice (Task 3) already posts + numbers synchronously and is **never** affected by email. Email is a queued job dispatched after commit. `invoices.email_status`: `not_sent` → `queued` → `sent` / `failed`. `email_attempts`: id, company_id, invoice_id, recipient, status (sent/failed), error nullable, attempted_at. A failure sets email_status 'failed' + logs an attempt; **the ledger/invoice stay posted**. A **Resend** re-queues (each attempt logged; idempotency prevents double-dispatch of the same attempt). PDF rendered on demand for the attachment; a render failure = a failed attempt, invoice still valid.

**Tests (TDD):** sending an invoice queues the email, email_status queued→sent on success (use Mail::fake / Queue::fake); **mail transport failure → invoice stays 'sent'/posted, email_status 'failed', an attempt logged, resend works** (the §17.6 test — the ledger must not roll back); resend re-queues + logs a new attempt; gate; scoped. Commit.

---

### Task 11: Invoice UI — list, detail, payment, void, send, FX

**Files:** `resources/js/Pages/Invoices/{Index,Show}.tsx` (Edit from Task 2), `resources/js/Components/invoices/{PaymentModal,VoidDialog,StatusChip}.tsx`; wire currency selector + fx-rate field into Edit; nav.

**Show.tsx:** header (number/DRAFT, contact, status chip [draft=neutral, sent=lagoon, partially_paid=amber, paid=spark, void=clay], dates, total + balance), line table, payments list, actions gated by status + ability: **Send** (draft), **Record payment** (sent/partially_paid → PaymentModal: amount, bank account, date; for FX invoices also the actual-base-received field), **Void** (VoidDialog: reverse-payments-first flow), **Download PDF**, **Send/Resend email** (with email_status shown). Currency + FX rate shown for foreign invoices (both amounts). Design tokens; **no debit/credit words**; MoneyText.

**Index.tsx:** enhance from Task 2 (status filter, contact, overdue highlight [computed: sent/partially_paid past due_date → amber], totals). 

**Steps:** build → `npm run build` → **browser-drive the full lifecycle**: create draft → add lines with tax → send (see number + AR posted via Trial Balance) → record partial payment → record final → paid; separately, void flow; separately, a USD invoice (set currency USD, rate, send, pay with actual PKR, see FX gain on Trial Balance). Screenshot the invoice detail + a USD invoice. Fix issues. Commit.

---

### Task 12: Integrity checker extension for invoices/payments + check (c) split

**Files:** `app/Services/IntegrityChecker.php` additions; `tests/Feature/IntegrityCheckerTest.php`.

- Orphan/source coverage: add 'invoice', 'invoice_payment' to the source-type map (each entry's source row must exist; unknown still fails loud).
- **Check (c) (spec §9, split for FX):** for each posted invoice, the **document-currency** balance must equal total − Σ(non-void payments' doc amount); AND (base tie-out) the invoice's base AR contribution must equal the ledger AR for that invoice's source **tolerating FX gain/loss** — i.e. AR posted (base_total) − Σ base_ar_relief == current ledger AR for the invoice, and any difference is explained by posted FX gain/loss lines. Implement as: document-currency balance check (exact) + a base-AR-vs-payments reconciliation that accounts for FX plug lines. A fully-paid invoice → 0 base AR (the settlement-rounding property).
- Keep vacuous guard + all prior checks intact.

**Tests:** a company with sent + partially-paid + paid + void invoices (domestic and FX) → checker clean; tamper an invoice's amount_paid (raw) so it disagrees with payments → 'document_balance' finding; a fully-paid FX invoice with correct FX plug → clean (no false positive from the base≠doc difference); orphan an invoice/payment entry → detected; unknown source still fails loud; vacuous guard intact. Commit.

---

### Task 13: Phase 3 exit verification + PROJECT_MAP update

- [ ] Full `composer test` + `npm run test:js` + `composer lint:check` + no-float grep — all green.
- [ ] Browser walkthrough on a FRESH company: draft → send (PKR) invoice, record partial + final payment (paid), Trial Balance still balances; void flow (reverse payment → void); a **USD invoice**: create, send with a rate, pay with actual PKR received, confirm FX gain/loss on the Trial Balance and base AR zeroed; PDF downloads; email decoupling (kill mail → invoice still sent, status failed, resend); an **opening invoice** (from a fresh opening-balance run) is payable; Health check clean.
- [ ] Update `docs/PROJECT_MAP.md` to end of Phase 3: §2 status (invoicing ✅, FX-lite ✅ full), §5 graph (InvoiceService/InvoicePaymentService → LedgerService; FxConverter; email job), §8 tree (all new files), §7 currency (FX posting now live), test counts. Add Phase 4 carry-forward notes.
- [ ] Confirm spec §13 Phase 3 exit criteria: P1–P3, P9 green incl. concurrency; payment against an opening invoice verified; email-failure test green; FX-lite tests green; full invoice life driven in browser.
- [ ] Commit "Phase 3 exit verification + project map update".

---

## Self-Review Notes

- **Spec coverage (§13 Phase 3 row):** lifecycle+numbering (T1), draft+tax (T2), send/P1 (T3), payment/P2/P3 (T4), void/P9 (T5), opening-invoice payable (T6), FX send/P1fx (T7), FX payment/P2fx (T8), PDF (T9), email decoupled (T10), UI (T11), checker+check(c) split (T12), exit (T13).
- **Ordering:** T1→T5 domestic core first; T6 reconciles opening (needs T4 payment); T7/T8 FX (need T3/T4); T9/T10 delivery; T11 UI (needs all services); T12 checker; T13 exit. Build in this order.
- **Carry-ins closed:** Money::taxMinorUnits negative guard (T2); opening_documents→invoices reconciliation (T6); check (c) FX split (T12).
- **New iron rules to preserve:** invoice numbering FOR UPDATE gap-free; payment overpayment on row-locked read; posted invoices frozen (correction = void+reissue); tax rate snapshotted at send; FX tax is a fixed base liability; settlement rounding zeroes base AR on final payment; email never affects ledger.
- **No placeholders:** P1/P2/P3/P9 and P1fx/P2fx posting lines are exact (spec §6/§17.1); the FX examples have concrete numbers to assert.
