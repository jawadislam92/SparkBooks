<?php

namespace App\Enums;

enum BillStatus: string
{
    case Draft = 'draft';
    case Received = 'received';
    case PartiallyPaid = 'partially_paid';
    case Paid = 'paid';
    case Void = 'void';

    /** A posted bill has left draft state — its lines are frozen (see BillLine::booted()). */
    public function isPosted(): bool
    {
        return in_array($this, [self::Received, self::PartiallyPaid, self::Paid], true);
    }

    /** Only a draft bill's core fields/lines are freely editable. */
    public function canEdit(): bool
    {
        return $this === self::Draft;
    }

    public function canReceive(): bool
    {
        return $this === self::Draft;
    }

    public function canReceivePayment(): bool
    {
        return in_array($this, [self::Received, self::PartiallyPaid], true);
    }

    /** Correction path for a posted bill is void + reissue, never an edit. */
    public function canVoid(): bool
    {
        return in_array($this, [self::Received, self::PartiallyPaid, self::Paid], true);
    }
}
