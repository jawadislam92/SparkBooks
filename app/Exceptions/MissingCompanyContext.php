<?php

namespace App\Exceptions;

class MissingCompanyContext extends \RuntimeException
{
    public function __construct()
    {
        parent::__construct('No company context is set. Tenant queries must never run unscoped.');
    }
}
