<?php

namespace Tests\Support;

use Illuminate\Support\Facades\DB;

trait AssertsLedgerBalanced
{
    /**
     * Global invariant: across every journal line in the whole test database
     * (regardless of tenant), total debits must equal total credits. If this
     * ever fails, money has been created or destroyed — the worst possible
     * bug in an accounting system.
     */
    protected function assertLedgerBalances(): void
    {
        $totals = DB::table('journal_lines')
            ->selectRaw('COALESCE(SUM(debit), 0) as total_debit, COALESCE(SUM(credit), 0) as total_credit')
            ->first();

        $this->assertSame(
            (int) $totals->total_debit,
            (int) $totals->total_credit,
            'Ledger invariant violated: total debits must equal total credits across all journal_lines.'
        );
    }
}
