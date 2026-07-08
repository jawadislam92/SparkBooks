<?php

namespace App\Services\Reports;

use App\Models\Account;
use App\Models\JournalLine;
use Carbon\CarbonImmutable;

class TrialBalanceReport
{
    /**
     * Derives strictly from journal_lines joined to journal_entries for
     * entry_date — never from document tables (invoices, bills, etc.).
     *
     * @return array{rows: list<array{account_id:int, code:string, name:string, type:string, debit:int, credit:int}>, total_debit:int, total_credit:int, as_of:string}
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

        $rows = [];
        $totalD = $totalC = 0;

        foreach (Account::query()->orderBy('code')->get() as $account) {
            $s = $sums[$account->id] ?? null;
            $net = $s ? ((int) $s->d - (int) $s->c) : 0;

            if ($net === 0 && ! $s) {
                continue; // never-used accounts skipped
            }

            $debit = max($net, 0);
            $credit = max(-$net, 0);

            if ($debit === 0 && $credit === 0) {
                continue; // fully offset accounts skipped
            }

            $rows[] = [
                'account_id' => $account->id,
                'code' => $account->code,
                'name' => $account->name,
                'type' => $account->type->value,
                'debit' => $debit,
                'credit' => $credit,
            ];
            $totalD += $debit;
            $totalC += $credit;
        }

        return [
            'rows' => $rows,
            'total_debit' => $totalD,
            'total_credit' => $totalC,
            'as_of' => $asOf->toDateString(),
        ];
    }
}
