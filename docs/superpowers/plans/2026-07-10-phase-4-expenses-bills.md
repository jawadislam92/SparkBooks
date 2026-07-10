# SparkBooks Phase 4 — Expenses & Bills (money out)

> **For agentic workers:** REQUIRED SUB-SKILL: superpowers:subagent-driven-development. Update `docs/PROJECT_MAP.md` in the final task. This phase MIRRORS Phase 3's invoicing on the payable side — every brief should point implementers at the invoicing analogue to copy the proven pattern.

**Goal:** The full "money out" side — record expenses (already paid, P4/P5), enter bills (pay later, P6/P6t), pay bills (P7), void via reversals, hardened receipt attachments (§17.8), opening vendor payables made payable as is_opening bills, checker coverage. Base currency only (spec §17.1 scopes FX to invoices).

**Architecture:** identical spine (PROJECT_MAP §4/§6): services build EntryDrafts → LedgerService::post inside IdempotencyService::execute + DB::transaction → AuditLogger. AP lines (2000) carry contact_id. The category-account picker (spec §7.8) allows expense + asset + liability + equity accounts (enabling P12 tax remittance, P13 owner drawing, P15 fixed asset) but NEVER AR/AP control or bank/cash accounts.

## Global Constraints

- Shell env + repo + conventions: identical to Phase 3 (PROJECT_MAP §3, §9 iron rules — all still binding). Branch `phase-4-expenses-bills`.
- Spec: §6 rules **P4** (expense: Dr category / Cr bank), **P5** (expense w/ input tax: Dr category net + Dr 2100 Tax Payable / Cr bank gross), **P6** (bill: Dr category / Cr 2000 AP w/ contact), **P6t** (bill w/ input tax: Dr category net + Dr 2100 / Cr AP gross), **P7** (bill payment: Dr AP w/ contact / Cr bank), P12/P13/P15 (via category picker); §7.8 (lifecycles: Expense Draft→Recorded, Bill Draft→Received→PartiallyPaid→Paid, void via reversal payments-first); §17.8 (attachment hardening: 10MB, MIME-sniff allowlist pdf/jpg/png/webp, random names, private disk, policy-checked routes); §12.7 (required tests).
- **Input tax** posts as a DEBIT to 2100 Tax Payable (nets against output tax — spec §6 P5 note). Rounding: per-line `Money::taxMinorUnits`.
- Gates (§7.14): expenses.draft + bills.draft (ALL four roles incl. Staff), expenses.record / bills.receive / expenses.void / bills.void / payments.record (Owner/Admin/Accountant, NOT Staff). Confirm each in config/permissions.php (they exist); PermissionMatrixTest already asserts them.
- Bills/expenses are BASE currency only in v1 (no fx fields needed beyond the standard columns defaulted).
- Every task: strict TDD, commit + trailer, task report to .superpowers/sdd/, AssertsLedgerBalanced on posting tests.

### The mirror map (what to copy from Phase 3)

| Phase 4 piece | Copy the pattern from |
|---|---|
| Expense/Bill schema + status enums + models | Invoice/InvoiceLine/InvoicePayment + InvoiceStatus (T1) |
| Draft CRUD + line validation | InvoiceController draft actions + StoreInvoiceRequest (T2) |
| ExpenseService::record / BillService::receive | InvoiceService::send (guards→doc finalize→LedgerService::post→audit) |
| BillPaymentService | InvoicePaymentService (row-locked overpayment guard, statuses) |
| Void (payments-first, reversals, second-void guard) | InvoiceService::void + voidPayment |
| Opening vendor payables → is_opening bills | OpeningBalanceService is_opening invoices + ReconcileOpeningInvoices (P3-T6) |
| Checker: bill document_balance + contact-level AP reconciliation | IntegrityChecker invoice checks + the contact-level opening AR fix (fbbe763) |
| UI (Index/Show/PaymentModal/VoidDialog/StatusChip) | Invoices/* + Components/invoices/* |

---

### Task 1: Schema + models — expenses, bills, payments, attachments

Migrations: `create_expenses_table` (id, company_id FK, contact_id nullable COMPOSITE FK→contacts [optional payee], status ['draft','recorded','void'], expense_date date nullable, bank_account_id nullable FK→bank_accounts [paid-from; required at record], subtotal/tax_total/total BIGINT, notes, created_by, recorded_at/voided_at nullable, timestamps); `create_expense_lines_table` (expense_id cascade, company_id, description(255), category_account_id FK→accounts, tax_rate_id nullable FK, tax_rate_snapshot decimal(7,4) nullable, amount BIGINT [net], line_tax BIGINT); `create_bills_table` (like expenses + reference string(120) nullable [vendor's bill number], bill_date/due_date, status ['draft','received','partially_paid','paid','void'], amount_paid/balance BIGINT, is_opening bool default false, contact_id COMPOSITE FK NOT NULL [bills always have a vendor]); `create_bill_lines_table` (mirror expense_lines); `create_bill_payments_table` (bill_id FK, payment_date, amount BIGINT, bank_account_id FK, status ['posted','void'], memo, created_by); `create_attachments_table` (id, company_id FK, attachable morphs [expense/bill], disk_path string, original_name, mime string(80), size unsignedBigInteger, uploaded_by, timestamps). Enums: ExpenseStatus (Draft/Recorded/Void + canEdit/canRecord/canVoid), BillStatus (Draft/Received/PartiallyPaid/Paid/Void + isPosted/canEdit/canReceive/canReceivePayment/canVoid). Models w/ BelongsToCompany, fillable sans company_id, line-freeze guards on ExpenseLine/BillLine (mirror InvoiceLine's fresh-query guard — lines frozen once parent posted). Factories. **Tests:** enums, freeze guards, composite FKs reject foreign contacts, unique nothing special (bills use vendor reference, no internal numbering). Mirror: P3-T1.

### Task 2: Draft CRUD + line calc (both documents)

`DocumentLineCalculator` (or reuse pattern): each line = {amount (net minor), tax_rate} → line_tax = taxMinorUnits; doc subtotal=Σamount, tax_total=Σtax, total=sum. (No qty×price here — spec §7.8 lines are amount-based.) **Category eligibility (THE guard, spec §7.8):** category_account must be tenant, non-archived, type in [expense, asset, liability, equity], NOT isControlAccount(), NOT subtype cash/bank. Validate in FormRequests AND service-level (mirror OtherReceiptService's IneligibleCategoryAccount — caller-independent). Draft CRUD for expenses (gate expenses.draft) + bills (bills.draft): create/edit/delete drafts only; expense contact optional (payee), bill contact required vendor/both (isVendor); expense bank_account optional at draft (required at record); bill due_date required at receive. Audit-logged. UI: Expenses/Index+Edit, Bills/Index+Edit (mirror Invoices — reuse a shared DocumentLineEditor or a variant of InvoiceLineEditor with amount-based lines + category picker [eligible accounts provided by controller]). No debit/credit words. **Tests:** calc exactness (odd paisa, mixed taxed/untaxed), category eligibility (control→422, bank/cash→422, archived→422, asset/liability/equity ALLOWED — the P12/P13/P15 enabler), draft-only edits, gates (all four roles draft), scoped. Mirror: P3-T2.

### Task 3: Record expense (P4/P5)

`ExpenseService::record(key, Expense)`: idempotency → txn → guards (draft, ≥1 line, bank_account tenant+non-archived required, category re-validated service-level) → freeze tax snapshots + recompute (BEFORE status flip) → post **P4/P5**: per line Dr category_account (net amount); if tax_total>0 Dr 2100 Tax Payable (tax_total, INPUT tax as debit); Cr bank_account.ledger_account (gross total). Balances by construction. Status Recorded, recorded_at. Audit. Controller action + route (gate expenses.record — Staff 403). **Tests:** P4 exact (untaxed: Dr category / Cr bank); P5 exact (spec numbers: 10,000 + 1,800 tax → Dr category 10,000 + Dr 2100 1,800 / Cr bank 11,800 — assert by account); multi-line mixed; P12 (category = 2100 Tax Payable itself → tax remittance posting works: Dr 2100 / Cr bank), P13 (category = 3900 Owner Drawings equity), P15 (category = 1500 Office Equipment asset) — the liability/equity/asset category cases post correctly; $0-line filtered + all-$0 rejected (mirror InvoiceHasNoValue); idempotent; locked period; gate; scoped; AssertsLedgerBalanced. Mirror: P3-T3 (send).

### Task 4: Receive bill (P6/P6t)

`BillService::receive(key, Bill)`: mirror record but posts **P6/P6t**: per line Dr category (net); if tax Dr 2100 (input); **Cr 2000 AP (gross total, contactId = bill.contact_id)**. Status Received, amount_paid=0, balance=total. Guards: vendor contact (isVendor), due_date required. **Tests:** P6 exact (30,000 → Dr category 30,000 / Cr AP 30,000 w/ contact); P6t exact (10,000+1,800 → Dr cat 10,000 + Dr 2100 1,800 / Cr AP 11,800 w/ contact); AP line carries contact_id; multi-line; idempotent; locked; gate bills.receive; scoped. Mirror: P3-T3.

### Task 5: Pay bill (P7)

`BillPaymentService::recordPayment(key, Bill, data)`: row-lock the bill, overpayment guard on locked read (amount ≤ balance), create bill_payment, post **P7**: Dr 2000 AP (amount, contactId) / Cr bank ledger account (amount). Update amount_paid/balance/status (Paid when 0 else PartiallyPaid). voidPayment: reverse P7, recompute back. **Tests:** full/partial/multi-partial; overpayment rejected on locked read; AP cleared for the vendor after full; concurrent-style + lockForUpdate assertion (per codebase precedent — the genuine cross-process pattern already exists in InvoicePaymentConcurrencyTest; add a bill variant if cheap, else document); idempotent; gate payments.record; scoped; AssertsLedgerBalanced. Mirror: P3-T4.

### Task 6: Void expense/bill

Expense void: reverse the P4/P5 entry, status Void. Bill void: REFUSE with live payments (list them), voidPayment first, then reverse P6/P6t (status Void). Second-void guarded (status + narrow unique-catch). Reversal dated today (locked-period safe). **is_opening bill void: friendly domain error** ("managed via opening balances") instead of a 404 — AND fold the same friendly error into InvoiceService::void for is_opening invoices (carry-in from Phase 3). **Tests:** mirror InvoiceVoidTest (unpaid void, refuse-with-payments, pay-void-then-void, second-void 422, locked-period reversal, is_opening friendly error for BOTH bill and invoice). Mirror: P3-T5.

### Task 7: Opening vendor payables → is_opening bills

Mirror P3-T6 exactly on the AP side: OpeningBalanceService's vendor_lines now ALSO create is_opening Bills (status Received, balance=amount, contact, due_date, NO ledger posting — AP already posted by P8c); re-run voids them; `sparkbooks:reconcile-opening-bills` idempotent backfill; paying an is_opening bill goes through the UNMODIFIED P7 path and clears the opening AP. **Tests:** no double-post (AP = opening amount not double), payable via P7, re-run voids, backfill idempotent, due_date carried, OpeningBalancesTest stays green. Mirror: P3-T6.

### Task 8: Attachments (§17.8 hardened)

Upload receipts/bills scans onto expenses + bills: POST /expenses/{id}/attachments (+ bills), DELETE, GET download. **Hardening (spec §17.8):** max 10MB; allowlist pdf/jpg/png/webp enforced by server-side MIME SNIFF (finfo on content — never trust extension/client mime); randomized storage names (uuid) on the PRIVATE local disk (not public); download only through an authenticated, policy-checked route (streamed, correct content-type, original filename); tenant-scoped (attachment of another company 404). Audit-log upload/delete AND download-of-attachment? (spec §17.8 logs exports — log downloads too, cheap). Gate: same as the parent document's draft gate (uploaders) — deletes only while parent is draft? Decision: attachments addable anytime (receipts arrive late), deletable only by Owner/Admin/Accountant. **Tests:** oversize→422, spoofed extension (exe renamed .pdf — content sniff)→422, valid pdf/jpg upload→stored w/ random name on private disk, download streams with auth (guest/foreign→403/404), delete, audit rows. UI: attachment list + upload on Expense/Bill Show/Edit.

### Task 9: Expenses & bills UI

Mirror Invoices UI: Expenses/{Index,Show,Edit} + Bills/{Index,Show,Edit}, PaymentModal (bills), VoidDialog, StatusChips, overdue highlight on bills (past due_date), attachments section, nav under a "Purchases" group (Expenses, Bills) — move Contacts+Items under Sales? Keep: Sales (Contacts, Items, Invoices), Purchases (Expenses, Bills), Books, Reports, Settings. Gate-conditional rendering via the can map (add expenses.record/bills.receive/bills.void etc. to NAV_ABILITIES if nav needs them). Browser-drive the full lifecycles (expense record; bill receive→partial→final pay→paid; void flows; attachment upload/download). No debit/credit words. Mirror: P3-T11.

### Task 10: Checker extension + carried cleanups

Add sources: 'expense', 'bill', 'bill_payment' to SOURCE_TABLES. document_balance for bills (balance == total − Σ non-void payments). Contact-level AP reconciliation for is_opening bills + per-bill for normal bills (MIRROR the contact-level opening AR fix — same pattern, AP side: expected AP per contact aggregate for opening, per-bill for normal via source attribution). **Cleanups (carried):** derive POSTED_INVOICE_STATUSES (and the new bill equivalent) from the enums' isPosted(); remove the unused-param smell if still present. **Tests:** clean multi-source company; orphan each new type; tampered bill balance → finding; opening-AP contact-level clean w/ multiple opening bills per vendor (the exact multi-doc case that bit AR); genuine tamper fires; unknown source fails loud; vacuous intact. Mirror: P3-T12 + fbbe763.

### Task 11: Exit verification + PROJECT_MAP

Suites (expect ~700+ PHP) + lint + no-float grep. Run reconcile-opening-bills backfill on dev. Browser walkthrough on a FRESH company: expense with tax (P5 on Trial Balance), tax remittance via category 2100 (P12), bill receive→pay lifecycle, void flows, attachment upload w/ spoofed-extension rejection, opening vendor bill payable, Health CLEAN. Update PROJECT_MAP to end of Phase 4 (§2 status, §5 graph, §8 tree, Phase 5 carry-forwards). Spec §13 Phase 4 exit criteria: P4–P7, P6t, P12/P13/P15 green; upload-hardening verified. Commit.

---

## Self-Review Notes

- Coverage vs spec §13 Phase 4 row: expenses multi-line+input tax (T1-3), bills+payments (T4-5), void (T6), asset/liability/equity categories = P12/P13/P15 (T2-3), attachments §17.8 (T8), UI (T9), checker (T10), exit (T11). Opening payables carry-in closed (T7). Phase-3 carried cleanups folded into T6/T10.
- Bills/expenses base-currency only (spec) — no FX columns/logic needed.
- Order: T1→T2→T3→T4→T5→T6→T7→T8→T9→T10→T11 (strict dependency chain; T8 parallel-safe after T1 but keep serial for simplicity).
