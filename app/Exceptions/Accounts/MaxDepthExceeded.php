<?php

namespace App\Exceptions\Accounts;

class MaxDepthExceeded extends AccountException
{
    public function __construct(string $message = 'Sub-accounts cannot be nested more than 5 levels deep.')
    {
        parent::__construct($message);
    }
}
