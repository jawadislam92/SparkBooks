<?php

namespace App\Domain\Ledger;

use App\Enums\EntryRole;
use Carbon\CarbonImmutable;

final readonly class EntryDraft
{
    /** @param list<LineDraft> $lines */
    public function __construct(
        public CarbonImmutable $entryDate,
        public string $memo,
        public string $sourceType,   // 'manual_journal' in Phase 1; invoice/payment/... later
        public int $sourceId,
        public array $lines,
        public EntryRole $role = EntryRole::Primary,
        public ?int $reversesEntryId = null,
    ) {}
}
