# SparkBooks v2 — Research & Roadmap (spec seed)

> Generated 2026-07-12 from a multi-source research pass (QuickBooks feature parity,
> Cash-Flow indirect-method methodology, sub-accounts/classes design, Pakistan
> payroll + WHT + provincial sales-tax). This is the INPUT to the binding v2 spec,
> not the spec itself. **Every Pakistani statutory rate below is a config default to
> CONFIRM with SparkingAsia's accountant — never treated as fact.** Roadmap phases
> continue the v1 numbering (v1 was Phases 0–6).

---

## 1. Prioritized roadmap

**Phase 7 — Accountant essentials** (derive-only depth + two additive columns; low risk, high trust payoff):
Statement of Cash Flows (indirect, algorithmic — §2), sub-accounts (parent/child COA — §3), activate/deactivate surfaced (accounts + contacts + items), visible human-readable entry numbers, in-app backup/export screen, and an expanded standard-report library:
- Overview: P&L (Summary/Detail/comparison), Balance Sheet (Summary/Detail), **Statement of Cash Flows**, Trial Balance, Journal/Audit, Account List.
- Accountant tools: General Ledger (have), **Transaction Detail by Account**, **Account QuickReport**, Recent Transactions, Reconciliation report.
- A/R: Aging (have), **Customer Balance Summary/Detail**, Open Invoices, Collections, **Customer Statements**.
- A/P: Aging (have), **Vendor Balance Summary/Detail**, Unpaid Bills, Bill Payment List.
- Analytics (need the Items dimension, Phase 8): Sales by Customer, Sales by Product/Service, Expenses by Vendor.

**Phase 8 — Faster entry & adjustment documents:** batch invoicing, batch expenses/bills, sub-items (service items → income account), **credit/debit notes** (customer credit memos + vendor credits via the existing allocation engine), cheque register (cheque printing low priority / likely drop for a bank-transfer-first PK agency). Fold-ins: **estimates/quotes → convert-to-invoice** (high value), **purchase orders → convert-to-bill**, **recurring invoices/bills** (highest-ROI automation for a retainer agency).

**Phase 9 — Classes / segment reporting:** a line-level class dimension → segmented P&L with a mandatory "Unassigned" column (§3). Optional 2nd dimension (Location). Fold-in: **Budgets + Budget-vs-Actual**. Guardrail: dimensions segment the **P&L only** — a segmented Balance Sheet is out of scope.

**Phases 10–12 — Payroll & Employees** (the only net-new posting engine; gated entirely on the §4 accountant-confirmation checklist): employee master + effective-dated salary components + versioned statutory config; FBR salary-slab withholding engine; a pay-run state machine (draft→calculate→approve→post→pay) posting one balanced journal per run; EOBI/PESSI/PF/gratuity; payslips; disbursement + per-authority liability clearing. Fold-in: **fixed-asset depreciation** (another scheduled accrual engine; feeds the SCF add-back).

**Phase 13 — Interface customization:** custom fields (first-class the **NTN/STRN/ATL/PRA** compliance fields on contacts *now*), saved/customized reports + month-end report pack, configurable dashboard.

**Compliance fold-ins:** **WHT tracking** (capture at bill/expense entry; registers as reports) and **PRA sales-tax return support** (capture on invoice/purchase; monthly summaries) — pull EARLY (Phase 7–8), they're live monthly obligations. Data model in §5.

---

## 2. Statement of Cash Flows — indirect method (algorithm)

Master identity from double-entry (`signed = debit − credit`, movements over posted lines in range): `ΔCash = NI − ΔOtherAssets − ΔLiabilities − ΔEquity`. The SCF is algebra, not assembly — classify every non-cash account into exactly one of Operating/Investing/Financing and the sections MUST sum to ΔCash (exact to the cent in integer minor units; any residual is a classification bug).

Build **two engines that check each other**: (A) cash-leg direct classification — classify each cash-account line by the nature of the *other* legs in its entry (transfers where both legs are cash net to zero automatically); (B) indirect presentation — Operating = NI + non-cash add-backs ± working-capital deltas — then **assert CFO_indirect == CFO_direct**.

`cf_class` on accounts (overrides type default): Cash pool (cash/bank/≤3-month equivalents); Operating working-capital (AR, prepaid, other receivables, AP, accrued, deferred revenue, tax payable); Investing (PP&E, intangibles, investments); Financing (loans, leases principal, share capital, dividends). Split accounts: `retained_earnings` holds NI (Operating) + dividends (Financing) — never use its raw movement.

**Two load-bearing rules:** the depreciation add-back comes from the depreciation *expense* account, NOT accumulated-depreciation's net movement (contaminated by disposals); investing proceeds/purchases come from the *actual cash legs*, never ΔgrossCost/NBV. **Ship six exact-integer assertions:** (1) Σdr==Σcr in-period; (2) CFO+CFI+CFF+FX_on_cash == closing−opening cash; (3) CFO_indirect==CFO_direct; (4) SCF cash == Balance-Sheet cash at both dates; (5) every cash line classified (unclassified=0); (6) no add-back from a contra/mixed account. Edge cases: opening entries dated ≤ period_start−1 (or tagged is_opening) so they don't pollute the first SCF; unrealized FX on foreign cash → separate "effect of FX on cash" line; non-cash investing/financing (leases, debt-for-equity) auto-excluded but need a disclosure note.

**Policy choices to confirm with accountant:** interest paid → Financing or Operating? interest/dividends received → Investing or Operating? dividends paid → Financing? cash-pool boundary (term deposits ≤3mo? qualifying overdrafts?).

---

## 3. Sub-accounts + Classes — schema, rollup, migration

Both are **pure additive migrations** — no touch to amount/debit/credit, no backfill, posted balances unchanged.

**Sub-accounts:** on `accounts` add `parent_id` (self-FK restrictOnDelete), `is_postable` (explicit, not derived), `depth`, `path` (materialized `/1/14/152/`, indexed). **Leaf-only posting enforced 3 ways** (app validation + DB trigger + CI test) — prevents the QBO "parent has direct postings + children → double-count" bug. Promotion: when a posted leaf needs children, auto-create a `"<Name> — Other"` child, move its lines there in one txn, set parent non-postable. Rollup: leaf balances then parent = Σ descendants via `path LIKE '/1/14/%'`; sign-flip at render by root type; indent by depth, order by path. Validations: child shares parent root type; cap depth ~4–5; subtree move updates path+depth for all descendants in one txn; opening balances only on leaves. Migration: every existing account → top-level postable leaf; zero data movement.

**Classes / tracking dimensions (generic N-dimension):** three new tables, `journal_lines` untouched — `tracking_categories` (ship Class + Location), `tracking_options` (with parent_id/path for sub-classes; unique(category_id,id)), and a `journal_line_tracking` pivot with the **composite FK `(category_id, option_id) → tracking_options(category_id, id)`** (makes it impossible to tag "Class" with a "Location" value). **Tag the line, not the entry** (one invoice can span departments). Rollup: LEFT JOIN → `COALESCE(option,0)` = mandatory **Unassigned** column; restrict to income+expense subtrees; **invariant test: Σ(option columns + Unassigned) == ungrouped P&L per account**. Migration: new tables only; every existing line is "Unassigned" (correct, not a gap — never machine-guess a backfill). Dimensions scope to P&L only.

---

## 4. Pakistan payroll — skeleton + CONFIRM-WITH-ACCOUNTANT checklist

Design principle: **every statutory rate is effective-dated config (`statutory_config`, `tax_slabs`), never a code literal.** A pay run snapshots the rates for its period so a later change never recomputes a posted run. State machine: draft (snapshot) → calculate → approve (human) → post (one balanced journal) → pay (net-salary batch + separate settlement per authority so each liability clears/reconciles). Illustrative journal: Dr Salary Expense + Dr Employer EOBI/PESSI/gratuity Expense / Cr Net Salary Payable + Cr Income-Tax(WHT) Payable + Cr EOBI Payable + Cr PESSI Payable + Cr PF Payable. Employee-share deductions sit *inside* gross; employer shares are additional expense with matching liabilities. Corrections via reversing entry, never edit-in-place.

**FBR salaried slabs TY2026 (1 Jul 2025–30 Jun 2026) — structure high-confidence, re-verify each Finance Act:** 0–600k → 0%; 600k–1.2m → 1%; 1.2m–2.2m → 6,000 + 11%; 2.2m–3.2m → 116,000 + 23%; 3.2m–4.1m → 346,000 + 30%; >4.1m → 616,000 + 35%. Surcharge +9% on computed tax where taxable income > 10m.

**Confirm-with-accountant (gate go-live on written sign-off; seed as placeholder config):** (1) FBR slabs + surcharge for the exact year; (2) which components taxable vs exempt (medical-allowance rule, per-employee credits); (3) **EOBI wage base 37,000 vs 40,000** (rates employer 5%/employee 1% high-confidence; flat amount pegged to notified minimum, not actual salary; + employee-count threshold); (4) **PESSI rate 6% vs 7% + wage ceiling (18k/22k/other)** and whether above-ceiling white-collar staff are covered — most uncertain input; (5) Punjab minimum wage (unskilled ~40,000 eff. Jul 2025); (6) gratuity eligibility/definition or PF instead; (7) PF employee/employer % (8.33% is convention not statute); (8) wage-payment timing + record retention; (9) payslip legal contents (Punjab); (10) registration status (FBR WHT agent, EOBI, PESSI, Shops & Establishments).

---

## 5. Pakistan WHT + sales tax — data to capture & monthly summaries

Two separate systems. **All rates = editable config by tax year; resolve filer/ATL status at payment date, never cached.**

**A. Withholding income tax (FBR).** Capture per outbound payment: payee + CNIC/NTN; ATL/filer status + date checked; gross/nature; **section code** (153(1)(a) goods / 153(1)(b) services — specified vs IT/ITeS vs unspecified / 153(1)(c) contracts / 155 rent / 233 commission); rate + filer flag; tax withheld; net; §164 certificate ref; final-vs-adjustable. Monthly summaries: WHT-deducted register by section/filer (→ IRIS deposit); quarterly §165 statement export; per-vendor annual ledger → §164 certificates; "tax withheld FROM us" ledger (our refundable credit); deposit-liability tracker (deducted vs deposited, due ~15th). Reference rates flagged confirm (153(1)(b) services is murkiest: specified 6%→7% Jul-2026 / IT-ITeS 4% / unspecified 15%).

**B. Sales tax on services (Punjab Revenue Authority — NOT FBR).** A Rawalpindi services agency needs a **PRA STRN** (not FBR sales-tax). Invoices carry a province-of-taxation field (SRB/KPRA cross-province). Capture on sales invoices: client province + STRN, service category + PRA rate + input-credit-allowed flag + zero-rated/export flag, whether client is a PST withholding agent. Capture on service purchases: vendor PRA status, PST charged, **PST withheld by us** (20% standard / 100% advertising / full if unregistered), input-claimable?, deposit ref. Monthly: output-tax by rate/jurisdiction; input-tax split claimable vs non-claimable; net PST payable; PST-withholding-as-agent register + deposit tracker; PST-withheld-from-us; return-ready PRA export. Reference rates flagged confirm (standard 16%; IT services reduced 5% *no input adjustment*; IT/export often zero-rated). Compliance master-data once per counterparty: type, CNIC/NTN, PRA STRN, ATL status + date, default WHT section, default PST category, province — first-class these fields (pull the NTN/STRN custom-field subset forward from Phase 13).

---

## 6. Open questions for owner/accountant
Payroll (blocks §4): EOBI base 37k/40k? PESSI rate/ceiling/coverage? gratuity vs PF? taxable components? current slabs + min wage? registrations? payslip mandate? — **Reports:** the accountant's exact wishlist + month-end pack + column/comparison formats; SCF interest/dividend placement policy; cash-pool boundary. — **Classes:** what dimension does the agency manage by (service line / client / department)? second dimension now? required-on-entry? — **Documents:** cheques printed or just recorded? estimates/POs needed + approval flow? recurring invoices — which retainers/cadence? — **COA:** preferred parent/sub structure + numbering; first-class NTN/STRN/ATL/PRA on contacts now? — **Tax:** which §153(1)(b) buckets apply to this agency's vendors? PRA rate for the agency's own services? advertising PST-withholding 100%? filing/deposit cadence actually followed?

---

*Engineering bottom line: Phase 7 is derive-only depth + two additive columns (`parent_id`, `cf_class`) — low risk, high payoff, SCF fully algorithmic (§2). Phases 8–9 are additive documents + one line-level tag pivot. Payroll (10–12) is the only net-new posting engine, entirely gated on the §4 confirm-checklist — build the config-versioned skeleton, seed placeholders, go live on NO statutory number until the accountant signs off in writing.*
