<?php

namespace App\Enums;

enum ExpenseStatus: string
{
    case Draft = 'draft';
    case Recorded = 'recorded';
    case Void = 'void';

    /** A posted expense has left draft state — its lines are frozen (see ExpenseLine::booted()). */
    public function isPosted(): bool
    {
        return $this === self::Recorded;
    }

    /** Only a draft expense's core fields/lines are freely editable. */
    public function canEdit(): bool
    {
        return $this === self::Draft;
    }

    public function canRecord(): bool
    {
        return $this === self::Draft;
    }

    /** Correction path for a recorded expense is void + re-enter, never an edit. */
    public function canVoid(): bool
    {
        return $this === self::Recorded;
    }
}
