<?php

namespace App\Services\Reports;

use App\Enums\AccountType;
use App\Models\JournalLine;
use Carbon\CarbonImmutable;

/**
 * The one shared path every report (Trial Balance, P&L, Balance Sheet,
 * agings, General Ledger, tax summary) reads journal_lines through, so they
 * can never diverge into separately-maintained queries.
 *
 * Inclusion rule mirrors TrialBalanceReport exactly: every journal_line
 * whose entry falls in the requested date window, filtered only by
 * entry_date. There is no separate "posted" flag to check — journal_entries
 * has no draft state at all (LedgerService::post is the only writer, and it
 * always creates an already-posted, immutable row via `posted_at`
 * useCurrent()). Reversals are ordinary posted lines and are included like
 * any other; they simply net an account back down (see LedgerBalancesTest).
 *
 * Company scoping is implicit via JournalLine/JournalEntry's
 * `BelongsToCompany` global scope (same as TrialBalanceReport) — never pass
 * or accept company_id here.
 */
class LedgerBalances
{
    /**
     * Cumulative per-account balance as of a date (balance-sheet/Trial
     * Balance style): sum(debit − credit) per account_id for every posted
     * line dated on or before $asOf.
     *
     * @param  list<int>|null  $accountIds  Restrict to these account ids.
     * @param  list<AccountType|string>|null  $accountTypes  Restrict to these account types.
     * @return array<int, array{account_id: int, debit: int, credit: int, net: int}>
     */
    public function cumulativeAsOf(CarbonImmutable $asOf, ?array $accountIds = null, ?array $accountTypes = null): array
    {
        return $this->aggregate(null, $asOf, $accountIds, $accountTypes);
    }

    /**
     * Per-account net movement (P&L style): sum(debit − credit) per
     * account_id for every posted line dated within [$from, $to] inclusive.
     *
     * @param  list<int>|null  $accountIds  Restrict to these account ids.
     * @param  list<AccountType|string>|null  $accountTypes  Restrict to these account types.
     * @return array<int, array{account_id: int, debit: int, credit: int, net: int}>
     */
    public function movement(CarbonImmutable $from, CarbonImmutable $to, ?array $accountIds = null, ?array $accountTypes = null): array
    {
        return $this->aggregate($from, $to, $accountIds, $accountTypes);
    }

    /**
     * @param  list<int>|null  $accountIds
     * @param  list<AccountType|string>|null  $accountTypes
     * @return array<int, array{account_id: int, debit: int, credit: int, net: int}>
     */
    private function aggregate(?CarbonImmutable $from, CarbonImmutable $to, ?array $accountIds, ?array $accountTypes): array
    {
        $query = JournalLine::query()
            ->selectRaw('account_id, SUM(debit) AS d, SUM(credit) AS c')
            // Same relation/predicate shape as TrialBalanceReport::build() and
            // AccountBalances::asOf() — JournalLine's belongsTo(JournalEntry)
            // relation is named `journalEntry()`, not `entry()`.
            ->whereHas('journalEntry', function ($e) use ($from, $to) {
                if ($from !== null) {
                    $e->whereBetween('entry_date', [$from->toDateString(), $to->toDateString()]);
                } else {
                    $e->where('entry_date', '<=', $to->toDateString());
                }
            })
            ->groupBy('account_id');

        if ($accountIds !== null) {
            $query->whereIn('account_id', $accountIds);
        }

        if ($accountTypes !== null) {
            $types = array_map(fn (AccountType|string $t) => $t instanceof AccountType ? $t->value : $t, $accountTypes);
            $query->whereHas('account', fn ($a) => $a->whereIn('type', $types));
        }

        $balances = [];
        foreach ($query->get() as $row) {
            $accountId = (int) $row->account_id;
            $debit = (int) $row->d;
            $credit = (int) $row->c;
            $balances[$accountId] = [
                'account_id' => $accountId,
                'debit' => $debit,
                'credit' => $credit,
                'net' => $debit - $credit,
            ];
        }

        return $balances;
    }
}
