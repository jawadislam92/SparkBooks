<?php

namespace App\Exceptions\Ledger;

class LockedPeriod extends LedgerException
{
    public function __construct(string $message = 'The books are locked for this period.')
    {
        parent::__construct($message);
    }
}
