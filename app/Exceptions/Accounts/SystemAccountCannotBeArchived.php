<?php

namespace App\Exceptions\Accounts;

/**
 * Phase 7 Task 2 (archiving, surfaced everywhere): a system account
 * (is_system=true — Retained Earnings, the AR/AP control accounts, Tax
 * Payable, Opening Balance Equity, FX Gain/Loss, ...) is referenced BY CODE
 * in posting rules and reports (see SystemAccountNotNestable's docblock for
 * the exact corruption a structural change causes). Archiving one would hide
 * it from every picker (income account, category account, manual journal
 * options) that those SAME posting rules rely on being able to post to at
 * any time — e.g. the very next invoice would have no eligible AR control
 * account to post against. So a system account can never be archived; it
 * stays active for the life of the company, exactly like it can never be
 * deleted (Account::booted()'s deleting guard) or nested (SystemAccountNotNestable).
 */
class SystemAccountCannotBeArchived extends AccountException
{
    public function __construct(string $message = 'System accounts like Retained Earnings and the AR/AP control accounts can\'t be archived.')
    {
        parent::__construct($message);
    }
}
