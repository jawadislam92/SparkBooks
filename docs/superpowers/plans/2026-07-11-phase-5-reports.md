# Phase 5 — Reports (P&L, Balance Sheet, agings, General Ledger, tax summary) + dashboard

Spec: §7.11 Reports, §7.12 Dashboard, §12.9 report tie-out tests, §13 Phase-5 exit row.
Binding law: every money report is computed **from `journal_lines`** (never document totals) — amounts tie to the ledger by construction. The ONE defined exception is aging **bucket assignment**, which joins the source document (via source_type/source_id) for its due date; the *amounts* still come from ledger lines.

THE MIRROR for every report: `app/Services/Reports/TrialBalanceReport.php` (report service — pure, testable, ledger-derived), `app/Http/Controllers/Reports/TrialBalanceController.php` (show + exportCsv, date handling, scoped), `resources/js/Pages/Reports/TrialBalance.tsx` (page + CSV button + print view). Reuse the design tokens, MoneyText, and the Reports nav placement.

Iron rules (docs/PROJECT_MAP.md §9): money BIGINT minor units, no float; reports read `journal_lines` only; company_id never fillable/request; every route gated + scoped findOrFail; design tokens only, NO debit/credit words on non-accountant screens (TB/GL are accountant screens and MAY use them); 120ms motion. Reports are READ-ONLY — no ledger writes anywhere in Phase 5.

Env: export PATH="/c/laragon/bin/php/php-8.3.30-Win32-vs16-x64:/c/laragon/bin/composer:/c/laragon/bin/nodejs/node-v22:/c/laragon/bin/mysql/mysql-8.4.3-winx64/bin:$PATH"; branch phase-5-reports. Tests filter per task then FULL suite + npm run build + test:js + pint. Commit trailer: Co-Authored-By: Claude Fable 5 <noreply@anthropic.com>

---

### Task 1: Reporting foundation — shared ledger-aggregation core + Reports nav

Extract the common ledger math so P&L/BS/GL/agings/tax do NOT each re-derive queries (the divergence risk). Build `app/Services/Reports/LedgerBalances.php` (or similar): given a company + optional date range (as-of date for balance-sheet-style, from/to for P&L-style), return per-account net movement AND cumulative balance from `journal_lines` (sum debit − credit per account_id, respecting posted entries only, excluding nothing that TB includes — mirror TrialBalanceReport's exact inclusion rules). Add: a `ReportPeriod` value object (from/to/as_of + fiscal-year helpers reading `companies.fiscal_year_start_month` — currentFiscalYearStart(asOf), completedFiscalYears(asOf)); a shared CSV streaming helper (mirror TrialBalanceController::exportCsv) if not already extractable; a Reports nav group (Trial Balance already there — add placeholders/links as reports land, gate by an appropriate view ability — check if a reports.view ability exists in §7.14/config, else reports are visible to all authenticated members like TB is). **Tests:** LedgerBalances matches TrialBalanceReport's numbers on a seeded company (regression: the shared core must reproduce the existing TB exactly); ReportPeriod fiscal-year math (fiscal_year_start_month=1 and a non-Jan start, e.g. 7 → completed years + current-year boundary correct); date-range filtering (movement in-range only). NO behavior change to the existing TB. Mirror: TrialBalanceReport.

---

### Task 2: Profit & Loss

`app/Services/Reports/ProfitAndLossReport.php`: income & expense accounts over a from/to period (movement, not cumulative), grouped by account with type subtotals (Total Income, Total Expenses, Net Profit = income − expenses). **Monthly-comparison columns**: same report broken into month columns across the range (e.g. Jan/Feb/Mar + Total). Controller `Reports/ProfitAndLossController` (show + exportCsv), page `Reports/ProfitAndLoss.tsx` (period picker, month columns, print view, CSV). Signs: income accounts are credit-normal (a sale increases income) — present income as positive, expenses as positive, net = income − expenses; get the sign convention right so a profitable period shows positive net. **Tests (§12.9):** P&L net == Σ(income journal lines) − Σ(expense journal lines) for the period (tie to ledger); a period with an invoice (P1 income) + an expense (P4) computes the right net; monthly split sums to the range total; voided invoice/expense correctly excluded (reversal nets to zero in-period); empty period → zeros not error. Mirror: TrialBalance report+controller+page.

---

### Task 3: Balance Sheet

`app/Services/Reports/BalanceSheetReport.php`: assets / liabilities / equity at an as-of date (cumulative balances). **Retained Earnings line = posted ledger balance of 3200 + net income of ALL COMPLETED fiscal years** (income−expense movement across each completed FY, using `fiscal_year_start_month` via ReportPeriod); **current-year earnings** = net income of the current (incomplete) fiscal year up to the as-of date, shown as its OWN line. Balance Sheet MUST balance: Assets == Liabilities + Equity(incl. RE lines + current-year earnings + OBE). Controller + `Reports/BalanceSheet.tsx` (as-of date picker, the RE/current-year split clearly labelled, print, CSV). Document (in the page + a tooltip) the expected TB-vs-BS Retained Earnings presentational difference (TB shows 3200 raw + open income/expense; BS folds completed-year net into RE). **Tests (§12.9, the hard ones):** BS balances on a clean company; balances AFTER a manual JE posting to 3200 (prior-period adjustment); balances ACROSS a fiscal-year boundary (income earned in a completed FY moves into RE, current-year earnings line reflects only current FY) — test with a non-Jan fiscal_year_start_month; OBE still shows if opening balances used; income/expense accounts NOT double-counted (they live in current-year-earnings, not as their own BS lines). Mirror: TrialBalance + §7.11.2.

---

### Task 4: A/R Aging + A/P Aging

`app/Services/Reports/AgingReport.php` (parameterized AR vs AP — shared bucketing engine): outstanding per contact bucketed BY DUE DATE — current (not yet due), 1–30, 31–60, 61–90, 90+ days past due, as of a report date. **Amounts from `journal_lines`** grouped by contact_id + source document (the control account is 1100 for AR / 2000 for AP; outstanding = the contact's net control-account balance attributable per source document). **Bucket date = the SOURCE DOCUMENT's due date** (join via source_type/source_id): invoices.due_date for AR, bills.due_date for AP, opening_documents.due_date for opening docs (captured at setup). **invoices.due_date is NULLABLE** — define + document a fallback bucket (recommend: treat null due_date as "current"/not-yet-due, OR bucket by issue_date — DECIDE, implement, test, and note in the report UI). Totals per aging MUST tie to the control account (AR total == 1100 balance; AP total == 2000 balance) — tested. Two controllers + `Reports/ArAging.tsx` + `Reports/ApAging.tsx` (report-date picker, contact rows × buckets, totals row, drill to contact/GL, CSV, print). **Tests (§12.9):** AR aging total == 1100 control balance (incl. opening receivables by captured due date); AP aging total == 2000 (incl. opening payables + the multi-opening-bill contact from Phase 4); a partially-paid invoice/bill ages only its OUTSTANDING balance; buckets assign correctly around the boundaries (due today = current; 1 day past = 1–30; 31 = 31–60; etc.); null invoice due_date → the chosen fallback bucket; a fully-paid doc drops off; a voided doc drops off. Mirror: the Phase-4 contact-level AR/AP reconciliation reasoning in IntegrityChecker (sum over a contact's source docs, don't collapse).

---

### Task 5: General Ledger report

`app/Services/Reports/GeneralLedgerReport.php`: per-account line detail over a from/to period with an opening balance (as-of the from date) + each journal line (date, entry, source, debit, credit, contact) + a RUNNING balance + a closing balance; all accounts or a single selected account. This is the Gate-B GL artifact and the drill-down TARGET from every other report (P&L account → its GL lines; BS line → GL; aging → contact's GL). Controller + `Reports/GeneralLedger.tsx` (period + account filter, running balance column, links from other reports pass ?account=&from=&to=, CSV, print). GL is an accountant screen — debit/credit columns ARE allowed here. **Tests:** GL running balance is internally consistent (opening + Σ movements == closing == the account's balance from LedgerBalances/TB at the to-date); per-account filter returns only that account's lines; a period with reversals shows both the original and the reversal line; drill params (account/from/to) resolve; empty account → opening==closing, no lines. Mirror: TrialBalance + the existing Accounts/Show ledger drilldown.

---

### Task 6: Tax Summary

`app/Services/Reports/TaxSummaryReport.php`: net balance of 2100 over a from/to period, split **collected vs input BY SOURCE DOCUMENT TYPE (signed)** — NEVER by which column (debit/credit) an amount sits in (reversals would misclassify). Classification: invoice + invoice-reversal source entries → collected (output tax); expense/bill + their reversals → input tax; tax remittances (P12, an expense whose category IS 2100) + manual adjustments → their own lines. Net tax payable = collected − input ± adjustments, tied to the 2100 movement for the period. Controller + `Reports/TaxSummary.tsx` (period picker, collected/input/adjustment sections, net line, CSV, print). **Tests (the classification trap, §7.11.6):** collected increases on invoice send, input increases on expense/bill with tax; **voiding an invoice DECREASES collected and leaves input UNCHANGED** (the reversal is classified by source_type=invoice, not by its debit/credit direction); voiding an expense decreases input, leaves collected unchanged; a P12 tax remittance shows as its own line, not as negative input; net == 2100 period movement. Mirror: TrialBalance; classify strictly by `journal_entries.source_type` (+ entry_role for reversals sharing the source), the same source-type discipline IntegrityChecker uses.

---

### Task 7: Dashboard enrichment

Extend the existing dashboard (Phase 2 cash-position + counts) to spec §7.12: cash position (sum bank/cash — exists, keep), outstanding & overdue invoices (count + amount; overdue via due date), unpaid bills (count + amount; overdue highlight), P&L snapshot (current-period net from ProfitAndLossReport), **income vs expense chart (Chart.js — already installed)**, recent activity (last N audit-log rows the viewer may see). All widgets read the report services built above (no new ledger math). Design: small, non-distracting, the signature look; the chart uses the palette (spark/lagoon/amber/clay), 120ms motion, responsive, dark-mode aware, no gray-/indigo-/hex. **Tests:** dashboard controller payload shape (each widget's numbers match the underlying report service on a seeded company); overdue counts correct; chart data series correct; gate-conditional (recent-activity only if audit.view); empty company → zeros/empty states not errors. JS: a small vitest for any pure chart-data transform. Mirror: existing Dashboard controller/page + the Phase-4 overdue.ts helper.

---

### Task 8: Exit verification + PROJECT_MAP

Full suites (expect ~1000+ PHP) + build + test:js + pint + no-float grep. Confirm §12.9 report tie-out tests all green: P&L net = income−expense; BS balances incl. after manual 3200 posting AND across a fiscal-year boundary; AR/AP aging totals == 1100/2000 control balances incl. opening documents bucketed by captured due dates; TB debits==credits (regression); tax reversal classification (void invoice decreases collected only). Curl-render each new report page (login via X-XSRF-TOKEN) confirming component + a tie-out number; ledger:verify clean on dev. Update PROJECT_MAP to end of Phase 5 (§2 status rows for the 6 reports + dashboard, §5 graph: report services → LedgerBalances → journal_lines, §8 tree: every new report service/controller/page, §10 Phase-6 carry-forwards). Whole-branch review, then merge to main. Spec §13 Phase-5 exit criteria: report tie-out tests green incl. aging-with-opening-documents and BS-across-fiscal-year-boundary.

---

## Self-Review Notes
- Coverage vs spec §7.11: P&L(T2), BS w/ RE §7.11.2(T3), AR+AP aging(T4), General Ledger(T5), Tax summary(T6); Trial Balance already shipped Phase 1 (T1 regression-guards it via the shared core). Dashboard §7.12(T7).
- Foundation-first (T1) so every report shares ONE ledger-aggregation path — prevents the divergent-query bug class. All reports read journal_lines only; aging's source-doc join is the single defined exception (spec §7.11).
- Reports are READ-ONLY — zero ledger writes, zero posting-rule changes. Lower risk than Phases 3–4, but the tie-out tests (§12.9) are the correctness gate: a report that doesn't tie to the ledger is the whole failure mode to prevent.
- Carry-ins consumed: aging ties to control accounts 1100/2000 (Phase-3/4 carry); contact-level sum-don't-collapse pattern (fbbe763) reused for aging; invoices nullable due_date fallback (Phase-4 T11 carry) resolved in T4.
- Order: T1→(T2,T3,T5,T6 depend only on T1; T4 depends on T1; T7 depends on T2/T3)→T8. Strictly serial for review simplicity, but T2/T3/T5/T6 are independent siblings if parallelism is ever needed.
- Accountant Gate B still deferred (no accountant hired) — build proceeds; these reports ARE the Gate-B artifacts when an accountant is engaged.
