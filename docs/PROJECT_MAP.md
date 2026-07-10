# SparkBooks — Project Map

> **This is the living map of the whole codebase.** It exists so that no matter how much
> conversation context is lost between sessions, anyone (human or AI) can open this one file
> and understand what exists, how it connects, and what is not built yet.
>
> **Maintenance rule:** update this file at the end of every phase (and whenever a new
> service/controller/screen/table is added). It is committed to git. If it ever disagrees with
> the code, the code wins — fix this file.
>
> Last updated: **end of Phase 4** (expenses & bills, money out: draft CRUD + amount-based line
> calc (no qty×price), CategoryAccountGuard (enables tax remittance/owner-drawings/fixed-asset
> purchases — P12/P13/P15), record expense (P4/P5), receive bill (P6/P6t), pay bill (P7), void
> reversals (P9, payments-first, is_opening guard), opening vendor payables → is_opening bills,
> attachment upload hardening (spec §17.8: content-sniff allowlist, private disk), full Purchases
> UI, checker extension to AP — built on branch `phase-4-expenses-bills`, Task 11 exit).
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
| Multi-currency — **FULL (Phase 3)**: FX Gain/Loss accounts (4950/5950), plus the complete FX-lite posting rules — foreign-currency invoice send (P1fx) and foreign-currency payment with realized FX gain/loss (P2fx), settlement rounding, checker tie-out under FX. Was foundation-only at Phase 2 exit | ✅ built | 2→3 |
| Nav (Overview/Sales/Books/Reports/Settings) + dashboard (cash position + counts) | ✅ built | 2 |
| Integrity checker: source-type-aware (reversals never false-flagged; unrecognized source_type fails loud); Phase 3 extended it to invoice/invoice_payment source coverage + the FX-tolerant base-AR tie-out (check (c) split) + opening-invoice AR attribution; Phase 4 extended it again to expense/bill/bill_payment source coverage + bill document_balance/amount_paid/status tie-out + contact-level AP reconciliation for opening bills (the AR-side fbbe763 lesson applied proactively) + `postedInvoiceStatuses()`/`postedBillStatuses()` derived from each status enum's own `isPosted()` (was a hand-duplicated const list) | ✅ extended | 2→3→4 |
| **Invoicing (money in)** — lifecycle (draft→sent→partially_paid→paid, void), gap-free numbering, draft CRUD + per-line tax, send (P1) posting AR/income/tax, payments (P2/P3, row-locked, real cross-process concurrency proven), void with payments-first refusal (P9), opening-invoice reconciliation (payable via the unmodified payment path), FX-lite send+payment (P1fx/P2fx), branded PDF, decoupled email + resend, full UI. Phase 4 retro-fix: void() now also refuses (friendly 422, not a confusing 404) on an is_opening invoice — correcting one must go through Opening Balances instead | ✅ built | 3→4 |
| **Expenses & bills (money out) — posting lifecycle** — draft CRUD + amount-based line calc (`DocumentLineCalculator`, no qty×price, spec §7.8) + `CategoryAccountGuard` (dual-layer, mirrors `OtherReceiptService`'s guard shape: allows expense/asset/liability/equity categories — enables tax remittance via 2100 Tax Payable (P12), owner drawings via an equity account like 3900 (P13), fixed-asset purchases via an asset account like 1500 (P15); blocks control (AR/AP)/cash-bank/income/archived accounts); record expense (P4/P5 — Dr category per line, ONE combined Dr 2100 for input tax [a DEBIT, opposite invoicing's output-tax credit], Cr the paid-from bank/cash account); receive bill (P6/P6t — Cr 2000 AP with contact_id); pay bill (P7, row-locked overpayment guard, the exact AP mirror of invoice payments' proven concurrency-safe pattern); void (P9 reversals: void expense/bill-payment/bill, payments-first refusal, is_opening guard); opening vendor payables → is_opening Bills (payable via the completely unmodified P7 path) + `reconcile-opening-bills` backfill for pre-Task-7 opening payables | ✅ built | 4 |
| **Expenses & bills — attachments, UI, checker** — receipt/bill-scan attachments (spec §17.8, hardened: content-sniffed allowlist [pdf/jpg/png/webp via `finfo`, never the client-declared mime/extension], private `local` disk, uuid filenames, scoped download, full audit trail on upload/delete/download, draft-delete cascade); full Purchases UI (Expenses/Bills Index with status filters + Bills' overdue highlight, Show detail pages for every status, Edit draft editors, Record/Receive/Pay/Void dialogs, shared `Components/documents/*`); integrity checker extended to expense/bill/bill_payment source coverage + contact-level AP reconciliation for opening bills (the AR-side fbbe763 lesson applied proactively) | ✅ built | 4 |
| P&L, Balance Sheet, agings, GL, tax summary, dashboard | ⛔ not built | 5 |
| Reconciliation, month-end close, exports, backups | ⛔ not built | 6 |

Tests: **894 PHP + 48 JS**, all green on real MySQL. No float on any money path (CI-enforced).

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
  InvoiceController.php          Phase 3: index/new/store/show/edit/update/destroy (draft only) +
                                send/void/recordPayment/voidPayment/pdf/email/resendEmail. Every
                                mutating action gated per its own `invoices.*` permission (draft vs.
                                send vs. void are independently gated — §7.14).
  ExpenseController.php          Phase 4 T2/T3/T6/T8/T9: index/new/store/show/edit/update/destroy
                                (draft only) + record/void + storeAttachment/downloadAttachment/
                                destroyAttachment (spec §17.8). Show (T9) is the canonical landing
                                page for every status; Edit stays the draft-only editor. No type
                                restriction on the payee contact (spec §7.8, unlike Bill).
  BillController.php             Phase 4 T2/T4/T5/T6/T8/T9: index/new/store/show/edit/update/destroy
                                (draft only) + receive/recordPayment/void/voidPayment +
                                storeAttachment/downloadAttachment/destroyAttachment. contact_id is
                                REQUIRED and must be a vendor/both (spec §7.8); `reference` carries
                                the vendor's own bill number (no internal numbering). Show (T9) also
                                renders the payments list + the opening-bill banner (relocated here
                                from Edit).
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
                                action-bar gating).
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
                                decision, Phase 4 brief) — this service is invoice-only.
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
Support/
  CompanyContext.php           request-scoped current company; get() throws if unset.
  IdempotencyService.php       exactly-once execute() wrapper (baseline txn guard).
  AuditLogger.php              writes ActivityLog rows.
  Currencies.php               allowed ISO currency list (PKR, USD, …).
  Money.php                     Phase 2 T3: parseToMinor (string→minor units, no float, mirrors
                                money.ts) + taxMinorUnits (bcmath half-up tax rounding; Phase 3 T2
                                hardened to throw loud on negative/5-decimal input).
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
Jobs/
  SendInvoiceEmail.php            Phase 3 T10: queued job — sets CompanyContext via runFor, sends
                                InvoiceMail, records an EmailAttempt, updates Invoice.email_status.
                                Dispatched AFTER the posting transaction commits (never inside it) —
                                a mail failure can never roll back or touch the ledger.
Mail/
  InvoiceMail.php                  Phase 3 T10: Mailable — renders InvoicePdf as an attachment.
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
Components/ui/                  Button, Sheet, Field, MoneyText, StatTile — design-system primitives.
                                StatTile added Phase 2 T10 for the dashboard's 3 stat cards.
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
  Invoices/Index.tsx             Phase 3 T11: invoice list, status filter (Draft/Sent/Partially
                                paid/Paid/Void), "New invoice".
  Invoices/Edit.tsx               draft editor — customer/currency/dates + InvoiceLineEditor +
                                Save draft/Send invoice (FX rate prompt on send if foreign currency).
  Invoices/Show.tsx               ⭐ the invoice detail screen — status, dual-currency total/balance
                                (≈ PKR + rate when foreign), line items, payments table (PKR
                                received/implied rate columns on FX invoices), Record payment/Void/
                                Download PDF/Send email(+Resend)/email status, all idempotency-keyed.
  Expenses/Index.tsx              Phase 4 T9: expense list, status filter (Draft/Recorded/Void),
                                search (payee/notes), "categories" summary column (summarizeList).
  Expenses/Edit.tsx                the draft editor — payee/date/bank account + DocumentLineEditor +
                                AttachmentSection + Save draft; Record moved to Show this task.
  Expenses/Show.tsx                ⭐ the canonical detail page for every status — line items,
                                attachments, Record (PostConfirmDialog)/Void (VoidDialog) actions,
                                all idempotency-keyed. Mirrors Invoices/Show's shape.
  Bills/Index.tsx                  Phase 4 T9: bill list, status filter incl. a synthetic "Overdue"
                                chip (isBillOverdue), row highlight on an overdue bill, "categories"
                                summary column, search (vendor/reference/notes).
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
```

### Data — `database/factories/`

```
Existing factories (Company/User/Account/ManualJournal/Contact/TaxRate/Item/BankAccount/Transfer/
OpeningBalanceRun/OpeningDocument/Invoice/InvoiceLine/InvoicePayment) unchanged. Phase 4 T1/T8 added
their AP-side siblings, same shape as InvoiceFactory/InvoiceLineFactory/InvoicePaymentFactory:
ExpenseFactory, ExpenseLineFactory, BillFactory, BillLineFactory, BillPaymentFactory,
AttachmentFactory.
```

### Config / routes

```
config/permissions.php   the §7.14 catalog + per-role permission lists (the authorization source of truth).
                          invoices.draft/send/void and payments.record were already in the Phase 0
                          catalog (built ahead of need, per §7.14); Phase 3 is the first phase to
                          actually wire routes/gates to them — no config change needed. Same story
                          for expenses.draft/record/void and bills.draft/receive/void: already in the
                          Phase 0 catalog, Phase 4 is the first phase to wire routes/gates to them —
                          no config change needed here either.
routes/web.php           all in-app routes, each gated (see the gate on each group). Phase 3 added the
                          /invoices/* group, split into invoices.draft / invoices.send / payments.record /
                          invoices.void gate groups (each narrower than invoices.draft). Phase 4 added
                          /expenses/* (expenses.draft / expenses.record / expenses.void gate groups)
                          and /bills/* (bills.draft / bills.receive / payments.record [shared with
                          invoices] / bills.void gate groups) the same way; attachment upload/download
                          share their document's own draft gate, delete reuses the void gate (spec
                          §17.8's Owner/Admin/Accountant decision — the tightest existing ability that
                          already maps to that exact role set, not a new permission).
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
- Bulk query-builder ops bypass model immutability guards — Phase 6 DB-trigger hardening candidate.
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
- The contact-level opening reconciliation pattern (fbbe763, originally an AR-only fix) is now
  **proven on both AR and AP** — `IntegrityChecker::checkOpeningArReconciliation()` and this phase's
  `checkOpeningApReconciliation()` are near-identical siblings. Phase 5's aging report will likely
  want the same "sum over a contact's opening documents, not a per-document split of a shared
  ledger line" reasoning — reuse the pattern rather than re-deriving it.
- Phase 5 aging will need to tie AR/AP agings to their control accounts (1100/2000) via each source
  document's own due date: `opening_documents.due_date` (captured at opening-balance setup, Phase 2
  T8) for opening receivables/payables, `invoices.due_date` (nullable — captured/edited in the
  invoice form and exposed in payloads since Phase 3, but NOT required) for AR, and `bills.due_date`
  (REQUIRED at receive time, Phase 4 T4's `BillDueDateRequired` guard) for AP. The asymmetry —
  Invoice's due_date is optional (an invoice can have none), Bill's is mandatory — means AR aging
  must define a fallback bucket for invoices with a null due_date before it is built.
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
