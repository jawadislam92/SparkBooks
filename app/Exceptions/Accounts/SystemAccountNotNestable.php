<?php

namespace App\Exceptions\Accounts;

/**
 * Phase 7 Task 1 review fix: system accounts (is_system=true) are referenced
 * BY CODE in posting rules and reports — Retained Earnings (3200), the AR/AP
 * control accounts (1100/2000), Tax Payable (2100), Opening Balance Equity
 * (3100), FX Gain/Loss (4950/5950). Sub-accounting any of them silently
 * corrupts those code-keyed lookups (e.g. promoting 3200 moves its balance to
 * a child, so BalanceSheetReport's `where('code', '3200')` reads 0 and the
 * child is orphaned from the Equity section → the Balance Sheet is driven
 * permanently out of balance). So a system account can neither BE promoted /
 * gain children nor be nested UNDER another account — it stays a top-level
 * postable leaf forever. Users may still sub-account their own (non-system)
 * accounts freely.
 */
class SystemAccountNotNestable extends AccountException
{
    public function __construct(string $message = 'System accounts like Retained Earnings and the AR/AP control accounts can\'t have sub-accounts.')
    {
        parent::__construct($message);
    }
}
