<?php

namespace App\Exceptions\Ledger;

class EmptyEntry extends LedgerException
{
    public function __construct(string $message = 'An entry must contain at least one line.')
    {
        parent::__construct($message);
    }
}
