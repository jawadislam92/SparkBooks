<?php

namespace App\Exceptions\Accounts;

/** Thrown by AccountService::moveSubtree() for a structurally impossible move — into itself, into its own descendant, or across root account types. */
class InvalidSubtreeMove extends AccountException
{
    public function __construct(string $message = 'This account cannot be moved there.')
    {
        parent::__construct($message);
    }
}
