<?php

namespace App\Exceptions\Accounts;

class AccountHasActiveChildren extends AccountException
{
    public function __construct(string $message = 'This account has active sub-accounts and cannot be archived.')
    {
        parent::__construct($message);
    }
}
