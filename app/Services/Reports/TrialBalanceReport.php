<?php

namespace App\Services\Reports;

use App\Models\Account;
use App\Models\JournalLine;
use Carbon\CarbonImmutable;

class TrialBalanceReport
{
    public function __construct(private AccountRollup $rollup) {}

    /**
     * Derives strictly from journal_lines joined to journal_entries for
     * entry_date — never from document tables (invoices, bills, etc.).
     *
     * Phase 7 Task 1 (sub-accounts): rows are depth-first ordered (a parent
     * header immediately followed by its descendants — see AccountRollup)
     * rather than a flat `orderBy('code')`. Every LEAF row is completely
     * unchanged from before (same skip rule, same debit/credit computation)
     * — a company with no sub-accounts degenerates back to the exact same
     * flat, code-ordered list with `depth=0`/`is_subtotal=false` on every
     * row, and the SAME total_debit/total_credit (parent subtotal rows are
     * never counted into the totals — they would double-count their own
     * descendants, which are already counted individually).
     *
     * @return array{rows: list<array{account_id:int, code:string, name:string, type:string, debit:int, credit:int, depth:int, is_subtotal:bool}>, total_debit:int, total_credit:int, as_of:string}
     */
    public function build(CarbonImmutable $asOf): array
    {
        $sums = JournalLine::query()
            ->selectRaw('account_id, SUM(debit) AS d, SUM(credit) AS c')
            // NOTE: the original brief's snippet calls this relation `entry`,
            // but JournalLine's actual belongsTo(JournalEntry::class) relation
            // is named `journalEntry()` (see app/Models/JournalLine.php) — no
            // `entry()` alias exists on the model. Using the brief's name
            // verbatim would throw "Call to undefined relationship [entry]"
            // the moment build() runs. This exact bug was already caught once
            // in Task 8's AccountBalances::asOf(); fixed here the same way.
            ->whereHas('journalEntry', fn ($e) => $e->where('entry_date', '<=', $asOf->toDateString()))
            ->groupBy('account_id')->get()->keyBy('account_id');

        $accounts = Account::query()->get();
        $ordered = $this->rollup->order($accounts);

        $rawNet = [];
        foreach ($accounts as $account) {
            $s = $sums[$account->id] ?? null;
            $rawNet[$account->id] = $s ? ((int) $s->d - (int) $s->c) : 0;
        }
        $rolledNet = $this->rollup->rollup($ordered, $rawNet);

        $rows = [];
        $totalD = $totalC = 0;

        foreach ($ordered as $account) {
            if ($account->isPostable()) {
                $s = $sums[$account->id] ?? null;
                $net = $rawNet[$account->id];

                if ($net === 0 && ! $s) {
                    continue; // never-used accounts skipped
                }

                $debit = max($net, 0);
                $credit = max(-$net, 0);

                if ($debit === 0 && $credit === 0) {
                    continue; // fully offset accounts skipped
                }
            } else {
                // Parent (header) account: never carries a direct posting of
                // its own (enforced by LedgerService/IntegrityChecker), so
                // its row is a pure rollup of every descendant leaf.
                $net = $rolledNet[$account->id] ?? 0;

                if ($net === 0) {
                    continue; // an empty header, nothing to show
                }

                $debit = max($net, 0);
                $credit = max(-$net, 0);
            }

            $rows[] = [
                'account_id' => $account->id,
                'code' => $account->code,
                'name' => $account->name,
                'type' => $account->type->value,
                'debit' => $debit,
                'credit' => $credit,
                'depth' => $account->depth,
                'is_subtotal' => ! $account->isPostable(),
            ];

            // Only LEAF amounts feed the grand total — a parent subtotal row
            // is display-only; its descendants are already counted above.
            if ($account->isPostable()) {
                $totalD += $debit;
                $totalC += $credit;
            }
        }

        return [
            'rows' => $rows,
            'total_debit' => $totalD,
            'total_credit' => $totalC,
            'as_of' => $asOf->toDateString(),
        ];
    }
}
