<?php

namespace App\Services;

use App\Enums\BillStatus;
use App\Enums\ExpenseStatus;
use App\Enums\InvoiceStatus;
use App\Models\ActivityLog;
use App\Models\BankAccount;
use App\Models\Bill;
use App\Models\Company;
use App\Models\Expense;
use App\Models\IntegrityRun;
use App\Models\Invoice;
use App\Models\MonthEndClose;
use App\Models\Reconciliation;
use Carbon\CarbonImmutable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Computes the done/not-done status of every step in the month-end close
 * checklist (spec §17.5, Phase 6 Task 5). Pure read-side: this class never
 * writes anything and contains NO new accounting logic — it only queries
 * machinery that already exists (T1 reconciliations, draft documents,
 * IntegrityChecker's persisted runs, the period lock) and shapes the result
 * for the Close/Index screen. All queries are implicitly company-scoped via
 * each model's BelongsToCompany trait + the request's CompanyContext, so
 * this never needs to filter by company_id itself.
 */
class MonthEndCloseService
{
    /**
     * @return array{
     *     reconcile: array{done: bool, outstanding_accounts: list<array{id:int,name:string}>},
     *     ar_ap_aging: array{done: bool, reviewed_at: ?string},
     *     trial_balance: array{done: bool, reviewed_at: ?string},
     *     drafts: array{done: bool, drafts: list<array{type:string,id:int,label:string,date:string}>},
     *     integrity: array{done: bool, ran_at: ?string, findings: list<array{check:string,detail:string}>},
     *     lock: array{done: bool, locked_through: ?string},
     *     export: array{done: bool},
     * }
     */
    public function steps(Company $company, CarbonImmutable $monthEnd, ?MonthEndClose $progress): array
    {
        return [
            'reconcile' => $this->reconcileStep($monthEnd),
            'ar_ap_aging' => $this->acknowledgeStep($progress?->ar_ap_reviewed_at),
            'trial_balance' => $this->acknowledgeStep($progress?->trial_balance_reviewed_at),
            'drafts' => $this->draftsStep($monthEnd),
            'integrity' => $this->integrityStep(),
            'lock' => $this->lockStep($company, $monthEnd),
            'export' => $this->exportStep($monthEnd),
        ];
    }

    /**
     * Step 1 (spec §17.5 point 1): done only when EVERY active (non-archived)
     * bank/cash account has a completed T1 reconciliation whose statement_date
     * is on or after the month-end date. Zero active accounts is vacuously
     * done (nothing outstanding to reconcile) — mirrors the same "nothing to
     * check" shape IntegrityChecker's own helpers use when a scope is empty.
     *
     * @return array{done: bool, outstanding_accounts: list<array{id:int,name:string}>}
     */
    private function reconcileStep(CarbonImmutable $monthEnd): array
    {
        $accounts = BankAccount::query()->where('is_archived', false)->orderBy('name')->get();

        $outstanding = $accounts
            ->reject(fn (BankAccount $account) => Reconciliation::query()
                ->where('bank_account_id', $account->id)
                ->where('status', 'completed')
                ->where('statement_date', '>=', $monthEnd->toDateString())
                ->exists())
            ->map(fn (BankAccount $account) => ['id' => $account->id, 'name' => $account->name])
            ->values();

        return [
            'done' => $outstanding->isEmpty(),
            'outstanding_accounts' => $outstanding->all(),
        ];
    }

    /**
     * Steps 2/3 (spec §17.5 points 2-3): a human "I reviewed this" acknowledge
     * — no system signal exists for "someone actually read the report", so
     * this is the ONLY state stored rather than derived. done = acknowledged
     * (the timestamp is non-null); revisitable — MonthEndCloseController's
     * acknowledge action can clear it back to null just as easily.
     *
     * @return array{done: bool, reviewed_at: ?string}
     */
    private function acknowledgeStep(?Carbon $reviewedAt): array
    {
        return [
            'done' => $reviewedAt !== null,
            'reviewed_at' => $reviewedAt?->toIso8601String(),
        ];
    }

    /**
     * Step 4 (spec §17.5 point 4) — the key correctness step: LISTS every
     * unposted draft invoice/bill/expense dated on or before the month end,
     * across the three document types that have a real draft workflow in
     * this codebase. done = zero such drafts (each must be sent/recorded/
     * voided or re-dated before the lock, or it would be stranded — a
     * back-dated draft could only ever post re-dated into the next period,
     * a cutoff error).
     *
     * Manual journals are deliberately NOT queried here: this system has no
     * draft manual-journal workflow at all — `manual_journals.status`
     * defaults to 'posted' and ManualJournalService::post() creates the
     * document and posts its ledger entry in the SAME transaction (there is
     * no separate "save as draft" step), and ManualJournalController's own
     * status validation only ever accepts 'posted'/'reversed'. A draft
     * manual journal is therefore structurally impossible, not merely rare.
     *
     * A null date (issue_date/bill_date/expense_date not yet set) is
     * excluded rather than swept — there's no date to compare against the
     * month end, so it can't be judged "dated on or before" it either way.
     *
     * @return array{done: bool, drafts: list<array{type:string,id:int,label:string,date:string}>}
     */
    private function draftsStep(CarbonImmutable $monthEnd): array
    {
        $date = $monthEnd->toDateString();

        $invoices = Invoice::query()
            ->where('status', InvoiceStatus::Draft->value)
            ->whereNotNull('issue_date')
            ->whereDate('issue_date', '<=', $date)
            ->orderBy('issue_date')
            ->get(['id', 'number', 'issue_date'])
            ->map(fn (Invoice $invoice) => [
                'type' => 'invoice',
                'id' => $invoice->id,
                'label' => $invoice->number ?? "Invoice #{$invoice->id}",
                'date' => $invoice->issue_date->toDateString(),
            ]);

        $bills = Bill::query()
            ->where('status', BillStatus::Draft->value)
            ->whereNotNull('bill_date')
            ->whereDate('bill_date', '<=', $date)
            ->orderBy('bill_date')
            ->get(['id', 'reference', 'bill_date'])
            ->map(fn (Bill $bill) => [
                'type' => 'bill',
                'id' => $bill->id,
                'label' => $bill->reference ?? "Bill #{$bill->id}",
                'date' => $bill->bill_date->toDateString(),
            ]);

        $expenses = Expense::query()
            ->where('status', ExpenseStatus::Draft->value)
            ->whereNotNull('expense_date')
            ->whereDate('expense_date', '<=', $date)
            ->orderBy('expense_date')
            ->get(['id', 'expense_date'])
            ->map(fn (Expense $expense) => [
                'type' => 'expense',
                'id' => $expense->id,
                'label' => "Expense #{$expense->id}",
                'date' => $expense->expense_date->toDateString(),
            ]);

        /** @var Collection<int, array{type:string,id:int,label:string,date:string}> $drafts */
        $drafts = $invoices->concat($bills)->concat($expenses)->values();

        return [
            'done' => $drafts->isEmpty(),
            'drafts' => $drafts->all(),
        ];
    }

    /**
     * Step 5 (spec §17.5 point 5): reflects the LATEST persisted
     * integrity_runs row for this company (the same table/model
     * Settings > Health reads — see HealthController::show), so a run
     * triggered from either screen updates both. done = a run exists AND its
     * `clean` flag is true; never run yet is treated as not-done (there's
     * nothing to vacuously pass here — spec §9's IntegrityChecker docblock
     * explicitly warns against ever appearing clean without actually having
     * checked).
     *
     * @return array{done: bool, ran_at: ?string, findings: list<array{check:string,detail:string}>}
     */
    private function integrityStep(): array
    {
        $latest = IntegrityRun::query()->orderByDesc('ran_at')->first();

        return [
            'done' => $latest !== null && $latest->clean,
            'ran_at' => $latest?->ran_at?->toIso8601String(),
            'findings' => $latest?->findings ?? [],
        ];
    }

    /**
     * Step 6 (spec §17.5 point 6): reflects the company's REAL
     * `books_locked_through` column — the exact same column
     * PeriodLockController/LedgerService already read/write. done when it is
     * set and covers (>=) the month end being closed. Movable by
     * Owner/Accountant with an audit trail — see MonthEndCloseController::lock
     * — so this can flip back to not-done just by moving the lock earlier,
     * which is intentional (spec §17.5: "the lock is the only
     * irreversible-ish act ... and even it is movable").
     *
     * @return array{done: bool, locked_through: ?string}
     */
    private function lockStep(Company $company, CarbonImmutable $monthEnd): array
    {
        $lockedThrough = $company->books_locked_through;

        return [
            'done' => $lockedThrough !== null && $lockedThrough->gte($monthEnd),
            'locked_through' => $lockedThrough?->toDateString(),
        ];
    }

    /**
     * Step 7 (spec §17.5 point 7): a best-effort, informational-only signal
     * — there is no way to know whether a human actually opened the
     * downloaded zip, only whether an export for this exact month end was
     * ever logged (AccountantPackageController::download's own
     * `accountant_package.exported` audit row, §17.8). Never gates
     * completion; it's a convenience tick, not a correctness check like
     * steps 1/4/5/6.
     *
     * @return array{done: bool}
     */
    private function exportStep(CarbonImmutable $monthEnd): array
    {
        $exported = ActivityLog::query()
            ->where('action', 'accountant_package.exported')
            ->get()
            ->contains(fn ($log) => ($log->after['to'] ?? null) === $monthEnd->toDateString());

        return ['done' => $exported];
    }
}
