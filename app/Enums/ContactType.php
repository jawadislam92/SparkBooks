<?php

namespace App\Enums;

enum ContactType: string
{
    case Customer = 'customer';
    case Vendor = 'vendor';
    case Both = 'both';

    public function isCustomer(): bool
    {
        return in_array($this, [self::Customer, self::Both], true);
    }

    public function isVendor(): bool
    {
        return in_array($this, [self::Vendor, self::Both], true);
    }
}
