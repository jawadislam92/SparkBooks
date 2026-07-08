<?php

namespace App\Services;

use App\Domain\Ledger\EntryDraft;
use App\Domain\Ledger\LineDraft;
use App\Enums\EntryRole;
use App\Models\JournalEntry;
use App\Models\ManualJournal;
use App\Support\AuditLogger;
use App\Support\IdempotencyService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

class ManualJournalService
{
    public function __construct(
        private LedgerService $ledger,
        private IdempotencyService $idempotency,
        private AuditLogger $audit,
    ) {}

    /** @param array{entry_date:string, memo:string, lines:list<array{account_id:int, debit:int, credit:int, memo?:?string}>} $data */
    public function post(string $idempotencyKey, array $data): ManualJournal
    {
        $result = $this->idempotency->execute($idempotencyKey, 'manual_journal.post', function () use ($data) {
            return DB::transaction(function () use ($data) {
                $doc = ManualJournal::query()->create([
                    'entry_date' => $data['entry_date'],
                    'memo' => $data['memo'],
                    'created_by' => auth()->id(),
                ]);
                $entry = $this->ledger->post(new EntryDraft(
                    entryDate: CarbonImmutable::parse($data['entry_date']),
                    memo: $data['memo'],
                    sourceType: 'manual_journal',
                    sourceId: $doc->id,
                    lines: array_map(fn ($l) => new LineDraft(
                        accountId: $l['account_id'], debit: $l['debit'],
                        credit: $l['credit'], memo: $l['memo'] ?? null,
                    ), $data['lines']),
                ));
                $this->audit->log('manual_journal.posted', $doc, after: ['entry_id' => $entry->id, 'memo' => $doc->memo]);

                return ['id' => $doc->id];
            });
        });

        return ManualJournal::query()->findOrFail($result['id']);
    }

    public function reverse(ManualJournal $journal, string $date, string $memo): JournalEntry
    {
        return DB::transaction(function () use ($journal, $date, $memo) {
            $entry = JournalEntry::query()
                ->where('source_type', 'manual_journal')->where('source_id', $journal->id)
                ->where('entry_role', EntryRole::Primary->value)->firstOrFail();
            $reversal = $this->ledger->reverse($entry, CarbonImmutable::parse($date), $memo);
            $journal->forceFill(['status' => 'reversed'])->save();
            $this->audit->log('manual_journal.reversed', $journal, after: ['reversal_entry_id' => $reversal->id]);

            return $reversal;
        });
    }
}
