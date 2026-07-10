<?php

namespace App\Exceptions\Documents;

/**
 * Thrown by ExpenseService::record() when the expense has no bank/cash
 * account chosen, or the one chosen is no longer available (foreign-tenant —
 * indistinguishable from nonexistent under BankAccount's BelongsToCompany
 * scope — or archived since the draft was created). Nullable at draft
 * (Phase 4 T1/T2) but required to record: P4/P5 always Cr a bank/cash
 * account for the gross total.
 */
class ExpenseBankAccountRequired extends DocumentPostException {}
