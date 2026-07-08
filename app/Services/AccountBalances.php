<?php

namespace App\Services;

use App\Models\Account;
use App\Models\JournalLine;
use Carbon\CarbonImmutable;

class AccountBalances
{
    /**
     * @return array<int, int> account_id => signed presentation balance in
     *                         minor units (debit-normal positive when debits
     *                         exceed; credit-normal negated so a normal
     *                         credit balance shows positive)
     */
    public function asOf(?CarbonImmutable $date = null): array
    {
        $q = JournalLine::query()
            ->selectRaw('account_id, SUM(debit) - SUM(credit) AS raw_balance')
            ->groupBy('account_id');
        if ($date) {
            // NOTE: the brief's literal snippet calls this relation `entry`,
            // but JournalLine's actual belongsTo(JournalEntry::class) relation
            // is named `journalEntry()` (see app/Models/JournalLine.php) — no
            // `entry()` alias exists on the model. Using the brief's name
            // verbatim would throw "Call to undefined relationship [entry]"
            // the moment asOf() is called with a date. Fixed to the real
            // relation name so date filtering actually works.
            $q->whereHas('journalEntry', fn ($e) => $e->where('entry_date', '<=', $date->toDateString()));
        }
        // Presentation sign: debit-normal accounts show raw; credit-normal show negated.
        $balances = [];
        $accounts = Account::query()->get()->keyBy('id');
        foreach ($q->pluck('raw_balance', 'account_id') as $id => $raw) {
            $type = $accounts[$id]->type;
            $balances[$id] = $type->isDebitNormal() ? (int) $raw : -(int) $raw;
        }

        return $balances;
    }
}
