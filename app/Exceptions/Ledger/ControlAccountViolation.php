<?php

namespace App\Exceptions\Ledger;

class ControlAccountViolation extends LedgerException
{
    public function __construct(string $message = 'This operation violates a control account rule.')
    {
        parent::__construct($message);
    }
}
