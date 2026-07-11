<?php

namespace App\Exceptions\Accounts;

use DomainException;

/** Base class for sub-account (hierarchical chart of accounts) domain exceptions — mirrors App\Exceptions\Ledger\LedgerException's shape. */
abstract class AccountException extends DomainException {}
