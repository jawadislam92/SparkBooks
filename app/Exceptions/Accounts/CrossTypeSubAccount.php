<?php

namespace App\Exceptions\Accounts;

/** A sub-account must share its parent's root AccountType (e.g. an expense account cannot nest under an asset). */
class CrossTypeSubAccount extends AccountException
{
    public function __construct(string $message = 'A sub-account must share its parent account\'s type.')
    {
        parent::__construct($message);
    }
}
