<?php

namespace App\Services\Reports;

use App\Models\Account;
use Illuminate\Support\Collection;

/**
 * Phase 7 Task 1 (sub-accounts): the ONE shared machinery every report
 * (TrialBalanceReport/BalanceSheetReport/ProfitAndLossReport/
 * GeneralLedgerReport) uses to present a hierarchy — depth-first row order
 * (a parent immediately followed by all its descendants) and parent subtotal
 * computation — so the four reports can never diverge into separately
 * re-derived tree-walking logic (mirrors why LedgerBalances is the one
 * shared path every report reads ledger movement through).
 *
 * A company with NO sub-accounts (every account is top-level, parent_id
 * null) degenerates exactly back to the pre-Phase-7 behavior: order() visits
 * every account once, in `code` order (identical to the `orderBy('code')`
 * every report used before this feature existed), and rollup() returns each
 * account's own raw amount unchanged (there is no parent to fold into).
 */
class AccountRollup
{
    /**
     * Depth-first order: a parent's row comes immediately before all of its
     * descendants, and siblings at every level are ordered by `code` — the
     * same ordering key every report already sorted by. This is what "ordered
     * by path" (spec §3) means in practice: a materialized path string does
     * NOT sort correctly by itself once account ids have different digit
     * counts (e.g. `/12/` sorts before `/2/` lexicographically), so this
     * walks the parent/child structure directly instead of relying on a raw
     * `ORDER BY path` string comparison.
     *
     * @param  Collection<int, Account>  $accounts  every account this report cares about (unordered, any subset of types)
     * @return list<Account>
     */
    public function order(Collection $accounts): array
    {
        $byParent = $accounts->groupBy(fn (Account $account) => $account->parent_id ?? 0);

        $ordered = [];
        $visit = function (int $parentKey) use (&$visit, &$ordered, $byParent): void {
            $siblings = $byParent->get($parentKey, collect())->sortBy('code')->values();
            foreach ($siblings as $account) {
                $ordered[] = $account;
                $visit($account->id);
            }
        };
        $visit(0);

        return $ordered;
    }

    /**
     * Bottom-up subtotal: every account's rollup amount is its own raw
     * amount PLUS the (already-rolled-up) amount of every immediate child.
     * Processing strictly deepest-first guarantees a node's own rollup is
     * fully finalized (all of its descendants already folded in) by the time
     * it contributes to ITS OWN parent's total — a leaf account contributes
     * only its own amount (it has no children to fold in); a parent (never
     * carrying direct postings itself — enforced by LedgerService/
     * IntegrityChecker) contributes exactly Σ(descendant leaf amounts),
     * because its own raw amount is always 0.
     *
     * @param  list<Account>  $orderedAccounts  from order() above (or any list containing every account in the subtree — order within the list doesn't matter here, only depth does)
     * @param  array<int, int>  $rawAmountsByAccountId  each account's own presented amount (already sign-adjusted by the caller); accounts absent from this map are treated as 0
     * @return array<int, int> account_id => rollup amount (own + every descendant)
     */
    public function rollup(array $orderedAccounts, array $rawAmountsByAccountId): array
    {
        $totals = [];
        foreach ($orderedAccounts as $account) {
            $totals[$account->id] = $rawAmountsByAccountId[$account->id] ?? 0;
        }

        $byDepthDesc = $orderedAccounts;
        usort($byDepthDesc, fn (Account $a, Account $b) => $b->depth <=> $a->depth);

        foreach ($byDepthDesc as $account) {
            if ($account->parent_id !== null && array_key_exists($account->parent_id, $totals)) {
                $totals[$account->parent_id] += $totals[$account->id];
            }
        }

        return $totals;
    }
}
