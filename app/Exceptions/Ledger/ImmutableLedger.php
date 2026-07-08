<?php

namespace App\Exceptions\Ledger;

class ImmutableLedger extends LedgerException
{
    public function __construct(string $message = 'Posted ledger entries are immutable — post a reversal instead.')
    {
        parent::__construct($message);
    }
}
