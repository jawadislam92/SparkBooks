<?php

namespace App\Exceptions\Ledger;

class UnbalancedEntry extends LedgerException
{
    public function __construct(string $message = 'Debits must equal credits.')
    {
        parent::__construct($message);
    }
}
