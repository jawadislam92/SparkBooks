<?php

namespace App\Domain\Ledger;

final readonly class LineDraft
{
    public function __construct(
        public int $accountId,
        public int $debit,          // minor units, >= 0
        public int $credit,         // minor units, >= 0
        public ?string $memo = null,
        public ?int $contactId = null,
    ) {}
}
