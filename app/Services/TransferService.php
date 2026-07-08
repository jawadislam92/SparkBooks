<?php

namespace App\Services;

use App\Domain\Ledger\EntryDraft;
use App\Domain\Ledger\LineDraft;
use App\Models\BankAccount;
use App\Models\Transfer;
use App\Support\AuditLogger;
use App\Support\IdempotencyService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

class TransferService
{
    public function __construct(
        private LedgerService $ledger,
        private IdempotencyService $idempotency,
        private AuditLogger $audit,
    ) {}

    /**
     * Posts a transfer of money between two of the tenant's own bank/cash
     * accounts. Posting rule P11: a transfer from account A to account B
     * debits B's linked ledger account and credits A's — money leaves A,
     * arrives at B.
     *
     * @param  array{from_bank_account_id:int, to_bank_account_id:int, transfer_date:string, amount:int, memo?:?string}  $data
     */
    public function transfer(string $idempotencyKey, array $data): Transfer
    {
        $result = $this->idempotency->execute($idempotencyKey, 'transfer.created', function () use ($data) {
            return DB::transaction(function () use ($data) {
                // Scoped findOrFail: BankAccount's BelongsToCompany global scope
                // makes a foreign-company id indistinguishable from a
                // nonexistent one — either way it 404s here. This is a
                // defense-in-depth backstop below StoreTransferRequest's own
                // friendly existence/archived check.
                $fromAccount = BankAccount::query()->findOrFail($data['from_bank_account_id']);
                $toAccount = BankAccount::query()->findOrFail($data['to_bank_account_id']);

                $memo = $data['memo'] ?? 'Transfer';

                $transfer = Transfer::query()->create([
                    'from_bank_account_id' => $fromAccount->id,
                    'to_bank_account_id' => $toAccount->id,
                    'transfer_date' => $data['transfer_date'],
                    'amount' => $data['amount'],
                    'memo' => $data['memo'] ?? null,
                    'created_by' => auth()->id(),
                ]);

                $entry = $this->ledger->post(new EntryDraft(
                    entryDate: CarbonImmutable::parse($data['transfer_date']),
                    memo: $memo,
                    sourceType: 'transfer',
                    sourceId: $transfer->id,
                    lines: [
                        // Dr destination
                        new LineDraft(accountId: $toAccount->ledger_account_id, debit: $data['amount'], credit: 0),
                        // Cr source
                        new LineDraft(accountId: $fromAccount->ledger_account_id, debit: 0, credit: $data['amount']),
                    ],
                ));

                $this->audit->log('transfer.created', $transfer, after: [
                    'entry_id' => $entry->id,
                    'from_bank_account_id' => $fromAccount->id,
                    'to_bank_account_id' => $toAccount->id,
                    'amount' => $transfer->amount,
                ]);

                return ['id' => $transfer->id];
            });
        });

        return Transfer::query()->findOrFail($result['id']);
    }
}
