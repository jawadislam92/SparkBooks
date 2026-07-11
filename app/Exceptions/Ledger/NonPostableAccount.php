<?php

namespace App\Exceptions\Ledger;

/**
 * Thrown by LedgerService::validate when a LineDraft targets an account whose
 * `is_postable` flag is false — i.e. a sub-account PARENT (header) account.
 * Leaf-only posting is enforced THREE ways (Phase 7 Task 1): this exception
 * is enforcement #1 (the ledger's own gate); #2 is
 * IntegrityChecker::checkCompany()'s "non_postable_posting" finding (a
 * fail-loud backstop for any line that somehow bypassed #1 — e.g. seeded
 * directly by SQL, mirroring how every other IntegrityChecker rule exists
 * precisely because raw writes can bypass model/service guards); #3 is the
 * feature test asserting both.
 */
class NonPostableAccount extends LedgerException
{
    public function __construct(string $message = 'This account is not postable — it has sub-accounts and only leaf accounts may be posted to.')
    {
        parent::__construct($message);
    }
}
