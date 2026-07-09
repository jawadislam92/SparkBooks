<?php

namespace App\Enums;

enum InvoiceStatus: string
{
    case Draft = 'draft';
    case Sent = 'sent';
    case PartiallyPaid = 'partially_paid';
    case Paid = 'paid';
    case Void = 'void';

    /** A posted invoice has left draft state — its lines are frozen (see InvoiceLine::booted()). */
    public function isPosted(): bool
    {
        return in_array($this, [self::Sent, self::PartiallyPaid, self::Paid], true);
    }

    /** Only a draft invoice's core fields/lines are freely editable. */
    public function canEdit(): bool
    {
        return $this === self::Draft;
    }

    public function canReceivePayment(): bool
    {
        return in_array($this, [self::Sent, self::PartiallyPaid], true);
    }

    /** Correction path for a posted invoice is void + reissue, never an edit. */
    public function canVoid(): bool
    {
        return in_array($this, [self::Sent, self::PartiallyPaid, self::Paid], true);
    }
}
