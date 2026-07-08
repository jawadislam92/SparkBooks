<?php

namespace App\Exceptions\Ledger;

class ArchivedAccount extends LedgerException
{
    public function __construct(string $message = 'This account is archived and cannot be used on a new entry.')
    {
        parent::__construct($message);
    }
}
