<?php

namespace App\Http\Controllers;

use App\Enums\BillStatus;
use App\Enums\ContactType;
use App\Enums\InvoiceStatus;
use App\Models\ActivityLog;
use App\Models\BankAccount;
use App\Models\Bill;
use App\Models\Contact;
use App\Models\Invoice;
use App\Services\AccountBalances;
use App\Services\Reports\AgingReport;
use App\Services\Reports\ProfitAndLossReport;
use App\Services\Reports\ReportPeriod;
use App\Support\AuditHumanizer;
use App\Support\CompanyContext;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Dashboard — Phase 2 gave this a "light" real-data pass (cash position + a
 * couple of counts). Phase 5 Task 7 enriches it to spec §7.12: outstanding &
 * overdue invoices, unpaid bills, a P&L snapshot, an income-vs-expense chart,
 * and a gated recent-activity feed. Every widget reads an EXISTING Phase 5
 * report service (ProfitAndLossReport / AgingReport, both of which read
 * journal_lines via LedgerBalances) or a document model — never a fresh
 * ledger query of its own, and never a NEW ledger aggregation. This
 * controller performs NO ledger writes.
 *
 * CURRENCY DISCIPLINE (the one non-obvious thing here): the invoices widget's
 * TOTALS come from AgingReport, NOT from summing Invoice.balance —
 * Invoice.balance is in the invoice's OWN document currency (PKR for a
 * domestic invoice, USD for a foreign-currency one), so summing it and
 * labelling the result base currency would be flat wrong for any FX invoice
 * (PROJECT_MAP §9: reports derive from journal_lines, never document totals).
 * AgingReport already reconstructs base-correct AR outstanding from
 * journal_lines and ties to the 1100 control account, so the widget reuses it
 * for its money figures and keeps only the currency-agnostic COUNTS from the
 * model. Bills are base-currency-only in v1 (spec §17.1), so Bill.balance IS
 * base currency and the bills widget sums it directly — no aging needed.
 */
class DashboardController extends Controller
{
    /** How many trailing calendar months (including the current, possibly partial, one) the income-vs-expense chart covers. */
    private const CHART_MONTHS = 6;

    /** How many recent audit-log rows the Recent activity widget shows. */
    private const RECENT_ACTIVITY_LIMIT = 8;

    public function show(
        AccountBalances $balances,
        ProfitAndLossReport $plReport,
        AgingReport $agingReport,
        CompanyContext $context,
    ): Response {
        $balancesByAccount = $balances->asOf();

        // Non-archived bank/cash accounts only — an archived account's
        // balance shouldn't count toward the headline cash figure.
        $bankAccounts = BankAccount::query()->where('is_archived', false)->get();

        $cashPosition = $bankAccounts->sum(
            fn (BankAccount $bankAccount) => $bankAccount->balance($balancesByAccount)
        );

        // ContactType::Both counts toward both sides — it genuinely is both
        // a customer and a vendor, same convention as Contact/Show's
        // outstanding-balance math (app/Http/Controllers/ContactController.php).
        $customerCount = Contact::query()
            ->where('is_archived', false)
            ->whereIn('type', [ContactType::Customer->value, ContactType::Both->value])
            ->count();

        $vendorCount = Contact::query()
            ->where('is_archived', false)
            ->whereIn('type', [ContactType::Vendor->value, ContactType::Both->value])
            ->count();

        $today = CarbonImmutable::today();

        return Inertia::render('Dashboard', [
            'cash_position' => $cashPosition,
            'customer_count' => $customerCount,
            'vendor_count' => $vendorCount,
            'bank_account_count' => $bankAccounts->count(),
            'invoices' => $this->invoiceWidget($agingReport, $today),
            'bills' => $this->documentWidget(
                Bill::query()->whereIn('status', $this->outstandingBillStatuses()),
                $today,
            ),
            'pl_snapshot' => $this->plSnapshot($plReport, $context, $today),
            'income_expense_chart' => $this->incomeExpenseChart($plReport, $today),
            'recent_activity' => $this->recentActivity(),
        ]);
    }

    /**
     * Invoice statuses that still carry an outstanding balance — derived
     * from InvoiceStatus::canReceivePayment() (Sent/PartiallyPaid) rather
     * than a hand-copied list, mirroring IntegrityChecker::
     * postedInvoiceStatuses()'s "derive from the enum's own method" pattern
     * (PROJECT_MAP §10 carried cleanup) so this can never drift from the
     * invoice lifecycle's own definition of "still payable".
     *
     * @return list<string>
     */
    private function outstandingInvoiceStatuses(): array
    {
        return array_values(array_map(
            fn (InvoiceStatus $status) => $status->value,
            array_filter(InvoiceStatus::cases(), fn (InvoiceStatus $status) => $status->canReceivePayment()),
        ));
    }

    /** The bill-side mirror of outstandingInvoiceStatuses() above (Received/PartiallyPaid). @return list<string> */
    private function outstandingBillStatuses(): array
    {
        return array_values(array_map(
            fn (BillStatus $status) => $status->value,
            array_filter(BillStatus::cases(), fn (BillStatus $status) => $status->canReceivePayment()),
        ));
    }

    /**
     * The outstanding & overdue invoices widget. COUNTS come per-invoice
     * from the Invoice model (a count has no currency, and the null-due-date/
     * strictly-past-due predicate below is the exact one Invoices/Index.tsx
     * and resources/js/lib/overdue.ts already use). MONEY comes from
     * AgingReport::buildReceivables — see the class docblock's CURRENCY
     * DISCIPLINE note for why Invoice.balance can't be summed here:
     *
     *   outstanding_total = the AR aging grand total (base-correct AR
     *                       outstanding, tied to the 1100 control account).
     *   overdue_total     = Σ of every PAST-DUE aging bucket (everything
     *                       except Current). Current holds both not-yet-due
     *                       AND null-due-date documents (AgingReport::
     *                       bucketFor), so excluding it lines up exactly with
     *                       the count predicate: a null due_date is never
     *                       overdue, due-today is not yet overdue.
     *
     * The counts (per-invoice) and totals (per-contact, ledger-derived) come
     * from different groupings on purpose — the counts answer "how many
     * documents", the totals answer "how much base money", and only the
     * money had a currency problem to fix.
     *
     * @return array{outstanding_count:int, outstanding_total:int, overdue_count:int, overdue_total:int}
     */
    private function invoiceWidget(AgingReport $agingReport, CarbonImmutable $today): array
    {
        $counts = $this->documentWidget(
            Invoice::query()->whereIn('status', $this->outstandingInvoiceStatuses()),
            $today,
        );

        $aging = $agingReport->buildReceivables($today);

        $overdueTotal = 0;
        foreach ($aging['buckets'] as $bucket) {
            if ($bucket !== 'current') {
                $overdueTotal += $aging['totals'][$bucket];
            }
        }

        return [
            'outstanding_count' => $counts['outstanding_count'],
            'outstanding_total' => $aging['grand_total'],
            'overdue_count' => $counts['overdue_count'],
            'overdue_total' => $overdueTotal,
        ];
    }

    /**
     * Count + total of every still-payable document (the query is
     * pre-filtered to the right status set by the caller), plus the overdue
     * subset — a document with a null due_date is NEVER overdue (spec §7.12:
     * no due date means no deadline it could be judged "past"), and "overdue"
     * is strictly due_date < today, mirroring resources/js/lib/overdue.ts's
     * isBillOverdue() (and Invoices/Index.tsx's own identical isOverdue())
     * exactly — due exactly today is not yet overdue.
     *
     * Used directly for the BILLS widget (bills are base-currency-only, so
     * summing Bill.balance IS a base-currency figure). For INVOICES,
     * invoiceWidget() uses only the currency-agnostic COUNTS from here and
     * takes its money figures from AgingReport instead (see that method).
     *
     * @param  Builder<Invoice>|Builder<Bill>  $query
     * @return array{outstanding_count:int, outstanding_total:int, overdue_count:int, overdue_total:int}
     */
    private function documentWidget($query, CarbonImmutable $today): array
    {
        $documents = $query->get(['id', 'due_date', 'balance']);

        $overdue = $documents->filter(
            fn (Invoice|Bill $document) => $document->due_date !== null && $document->due_date->lt($today)
        );

        return [
            'outstanding_count' => $documents->count(),
            'outstanding_total' => (int) $documents->sum('balance'),
            'overdue_count' => $overdue->count(),
            'overdue_total' => (int) $overdue->sum('balance'),
        ];
    }

    /**
     * Current fiscal-year-to-date net profit — the same default range
     * Reports/ProfitAndLossController itself falls back to
     * (ReportPeriod::currentFiscalYearStart, respecting the company's own
     * fiscal_year_start_month), so the dashboard snapshot always agrees
     * with what "Profit & loss" shows when opened with no explicit range.
     *
     * @return array{period_label:string, from:string, to:string, income:int, expenses:int, net_profit:int}
     */
    private function plSnapshot(ProfitAndLossReport $plReport, CompanyContext $context, CarbonImmutable $today): array
    {
        $period = new ReportPeriod($context->get()->fiscal_year_start_month);
        $from = $period->currentFiscalYearStart($today);

        $report = $plReport->build($from, $today);

        return [
            'period_label' => 'Fiscal year to date',
            'from' => $report['from'],
            'to' => $report['to'],
            'income' => $report['income']['total'],
            'expenses' => $report['expenses']['total'],
            'net_profit' => $report['net_profit'],
        ];
    }

    /**
     * The last CHART_MONTHS calendar months (including the current,
     * possibly partial, one) of income vs. expense — read straight from
     * ProfitAndLossReport's own monthly buckets (`months`/
     * `income.total_by_month`/`expenses.total_by_month`), never re-summed
     * from journal_lines here. A deliberately separate build() call from
     * plSnapshot()'s (different range, different purpose) — both are thin
     * reads through the same already-tested report service.
     *
     * @return array{labels:list<string>, income:list<int>, expenses:list<int>}
     */
    private function incomeExpenseChart(ProfitAndLossReport $plReport, CarbonImmutable $today): array
    {
        // subMonths() (not the NoOverflow variant) is safe here because the
        // very next call collapses to startOfMonth() — day-of-month overflow
        // across variable-length months can never matter once only the
        // month itself is kept.
        $from = $today->subMonths(self::CHART_MONTHS - 1)->startOfMonth();

        $report = $plReport->build($from, $today);

        return [
            'labels' => array_map(fn (array $month): string => $month['label'], $report['months']),
            'income' => $report['income']['total_by_month'],
            'expenses' => $report['expenses']['total_by_month'],
        ];
    }

    /**
     * Last RECENT_ACTIVITY_LIMIT activity_log rows the viewer may see
     * (audit.view — Owner/Accountant only per config/permissions.php;
     * Admin/Staff get an empty list, never a 403, so the widget just hides
     * itself). Humanized via the shared AuditHumanizer (Phase 6 Task 2
     * extracted it here so the full Audit log viewer reuses the exact same
     * logic): an action label ("Invoice sent", never the raw 'invoice.sent'
     * code), an optional subject reference ("Invoice #12"), and a relative
     * time — NO before/after JSON reaches the client. ActivityLog's own
     * BelongsToCompany scope keeps this tenant-safe with no explicit
     * company_id filter needed, same as every other query here.
     *
     * @return list<array{id:int, action:string, subject:?string, when:string, created_at:string}>
     */
    private function recentActivity(): array
    {
        if (! Gate::allows('audit.view')) {
            return [];
        }

        return ActivityLog::query()
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->limit(self::RECENT_ACTIVITY_LIMIT)
            ->get()
            ->map(fn (ActivityLog $log): array => [
                'id' => $log->id,
                'action' => AuditHumanizer::action($log->action),
                'subject' => AuditHumanizer::subject($log->subject_type, $log->subject_id),
                'when' => $log->created_at->diffForHumans(),
                'created_at' => $log->created_at->toIso8601String(),
            ])
            ->all();
    }
}
