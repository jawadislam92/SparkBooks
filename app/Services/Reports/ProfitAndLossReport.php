<?php

namespace App\Services\Reports;

use App\Enums\AccountType;
use App\Models\Account;
use Carbon\CarbonImmutable;

/**
 * Profit & Loss (income statement): income and expense account movement over
 * a [from, to] period, plus the same figures broken into calendar-month
 * columns. Reads exclusively through LedgerBalances::movement() (PROJECT_MAP
 * §9 rule 9 — reports derive from journal_lines, never document totals), so
 * this class performs NO ledger writes and is safe to call from a read-only
 * request cycle.
 *
 * SIGN CONVENTION: income accounts are credit-normal
 * (AccountType::Income->isDebitNormal() === false), so LedgerBalances' net
 * (debit − credit) is NEGATIVE for a normal (credited) income account.
 * Expense accounts are debit-normal, so their net is already POSITIVE. Every
 * amount this class returns is the "present to the user" sign — positive
 * income, positive expenses — so Net Profit = Total Income − Total Expenses
 * reads naturally (positive when profitable, negative for a loss). No
 * debit/credit language anywhere in this class or its output (PROJECT_MAP §9
 * rule 8 — P&L is a non-accountant screen).
 *
 * Monthly split: each calendar month in [from, to] gets its own
 * LedgerBalances::movement() call, clipped to the range on the first/last
 * month. Because those sub-ranges partition [from, to] exactly and without
 * overlap, Σ(monthly net per account) always equals the range net per
 * account — SUM() is linear over a partition, and every amount here is an
 * exact integer (minor units), so there is no rounding for that identity to
 * ever violate.
 */
class ProfitAndLossReport
{
    public function __construct(private LedgerBalances $ledger) {}

    /**
     * @return array{
     *     from: string,
     *     to: string,
     *     months: list<array{label: string, start: string, end: string}>,
     *     income: array{rows: list<array{account_id: int, code: string, name: string, amount: int, months: list<int>}>, total: int, total_by_month: list<int>},
     *     expenses: array{rows: list<array{account_id: int, code: string, name: string, amount: int, months: list<int>}>, total: int, total_by_month: list<int>},
     *     net_profit: int,
     *     net_profit_by_month: list<int>,
     * }
     */
    public function build(CarbonImmutable $from, CarbonImmutable $to): array
    {
        $from = $from->startOfDay();
        $to = $to->startOfDay();

        $buckets = $this->monthBuckets($from, $to);
        $monthCount = count($buckets);

        $rangeMovement = $this->ledger->movement($from, $to, null, [AccountType::Income, AccountType::Expense]);

        $monthlyMovements = array_map(
            fn (array $bucket): array => $this->ledger->movement($bucket['start'], $bucket['end'], null, [AccountType::Income, AccountType::Expense]),
            $buckets,
        );

        $income = $this->section(AccountType::Income, $rangeMovement, $monthlyMovements, $monthCount);
        $expenses = $this->section(AccountType::Expense, $rangeMovement, $monthlyMovements, $monthCount);

        $netProfitByMonth = [];
        for ($i = 0; $i < $monthCount; $i++) {
            $netProfitByMonth[] = $income['total_by_month'][$i] - $expenses['total_by_month'][$i];
        }

        return [
            'from' => $from->toDateString(),
            'to' => $to->toDateString(),
            'months' => array_map(fn (array $bucket): array => [
                'label' => $bucket['label'],
                'start' => $bucket['start']->toDateString(),
                'end' => $bucket['end']->toDateString(),
            ], $buckets),
            'income' => $income,
            'expenses' => $expenses,
            'net_profit' => $income['total'] - $expenses['total'],
            'net_profit_by_month' => $netProfitByMonth,
        ];
    }

    /**
     * Builds one section (Income or Expenses): every account of $type,
     * ordered by code, presented in "positive is normal" sign.
     *
     * DISPLAY skip vs. TOTAL accumulation are deliberately separated. An
     * account whose FULL-RANGE amount nets to exactly zero is skipped from
     * the row LIST (mirrors TrialBalanceReport's skip rule — a
     * never-touched, or touched-and-fully-reversed-within-the-range account
     * doesn't earn a row). But its per-month amounts are ALWAYS accumulated
     * into $totalByMonth first, because a zero FULL-RANGE net can still hide
     * real activity spread across individual months — e.g. income posted in
     * January and voided in February nets to 0 over Jan–Feb, yet January's
     * column must still show the real income and February's the reversal.
     * Accumulating before the row-skip keeps Σ(total_by_month) == range
     * total (0 in that example) while never zeroing a month that had
     * activity.
     *
     * @param  array<int, array{account_id: int, debit: int, credit: int, net: int}>  $rangeMovement
     * @param  list<array<int, array{account_id: int, debit: int, credit: int, net: int}>>  $monthlyMovements
     * @return array{rows: list<array{account_id: int, code: string, name: string, amount: int, months: list<int>}>, total: int, total_by_month: list<int>}
     */
    private function section(AccountType $type, array $rangeMovement, array $monthlyMovements, int $monthCount): array
    {
        $isDebitNormal = $type->isDebitNormal();

        $rows = [];
        $total = 0;
        $totalByMonth = array_fill(0, $monthCount, 0);

        $accounts = Account::query()->where('type', $type->value)->orderBy('code')->get();

        foreach ($accounts as $account) {
            $net = $rangeMovement[$account->id]['net'] ?? 0;
            $amount = $isDebitNormal ? $net : -$net;

            $months = [];
            for ($i = 0; $i < $monthCount; $i++) {
                $monthNet = $monthlyMovements[$i][$account->id]['net'] ?? 0;
                $monthAmount = $isDebitNormal ? $monthNet : -$monthNet;
                $months[] = $monthAmount;
                // ALWAYS accumulate the monthly total, even for a
                // zero-full-range account — see method docblock.
                $totalByMonth[$i] += $monthAmount;
            }

            // Skip only the DISPLAY row for a zero-full-range account; its
            // monthly totals are already accumulated above.
            if ($amount === 0) {
                continue;
            }

            $rows[] = [
                'account_id' => $account->id,
                'code' => $account->code,
                'name' => $account->name,
                'amount' => $amount,
                'months' => $months,
            ];
            $total += $amount;
        }

        return ['rows' => $rows, 'total' => $total, 'total_by_month' => $totalByMonth];
    }

    /**
     * Every calendar month whose span overlaps [from, to], clipped to
     * [from, to] on the first and last bucket — so the buckets partition
     * [from, to] exactly (contiguous, no gaps, no overlap). Returns an empty
     * list for an inverted range ($from after $to) rather than looping
     * forever or throwing — the caller then naturally reports all zeros.
     *
     * @return list<array{label: string, start: CarbonImmutable, end: CarbonImmutable}>
     */
    private function monthBuckets(CarbonImmutable $from, CarbonImmutable $to): array
    {
        if ($from->gt($to)) {
            return [];
        }

        $buckets = [];
        $cursor = $from->startOfMonth();

        while ($cursor->lte($to)) {
            $monthEnd = $cursor->endOfMonth();

            $buckets[] = [
                'label' => $cursor->format('M Y'),
                'start' => $cursor->lt($from) ? $from : $cursor,
                'end' => $monthEnd->gt($to) ? $to : $monthEnd,
            ];

            $cursor = $cursor->addMonthNoOverflow()->startOfMonth();
        }

        return $buckets;
    }
}
