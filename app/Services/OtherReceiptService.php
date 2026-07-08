<?php

namespace App\Services;

use App\Domain\Ledger\EntryDraft;
use App\Domain\Ledger\LineDraft;
use App\Models\BankAccount;
use App\Models\OtherReceipt;
use App\Support\AuditLogger;
use App\Support\IdempotencyService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

class OtherReceiptService
{
    public function __construct(
        private LedgerService $ledger,
        private IdempotencyService $idempotency,
        private AuditLogger $audit,
    ) {}

    /**
     * Records money received that is not an invoice payment — an owner
     * contribution, misc income, a refund, etc. Posting rule P14: Dr the
     * deposit bank/cash account, Cr the selected category account (e.g. Cr
     * 3000 Owner's Equity or Cr 4900 Other Income).
     *
     * The category account's eligibility (not a control account, not a
     * bank/cash account, not archived) is enforced by
     * StoreOtherReceiptRequest before this ever runs. LedgerService::post
     * still provides defense-in-depth of its own: it 404s a foreign-tenant
     * category_account_id (tenant-scoped findOrFail), rejects an archived
     * account on a new entry, and — since other_receipt lines never carry a
     * contact_id — rejects a control account outright via
     * ControlAccountViolation.
     *
     * @param  array{bank_account_id:int, category_account_id:int, receipt_date:string, amount:int, payer?:?string, memo?:?string}  $data
     */
    public function record(string $idempotencyKey, array $data): OtherReceipt
    {
        $result = $this->idempotency->execute($idempotencyKey, 'other_receipt.recorded', function () use ($data) {
            return DB::transaction(function () use ($data) {
                // Scoped findOrFail: BankAccount's BelongsToCompany global scope
                // makes a foreign-company id indistinguishable from a
                // nonexistent one — either way it 404s here. This is a
                // defense-in-depth backstop below StoreOtherReceiptRequest's own
                // friendly existence/archived check. Mirrors TransferService.
                $bankAccount = BankAccount::query()->findOrFail($data['bank_account_id']);

                $receipt = OtherReceipt::query()->create([
                    'bank_account_id' => $bankAccount->id,
                    'category_account_id' => $data['category_account_id'],
                    'receipt_date' => $data['receipt_date'],
                    'amount' => $data['amount'],
                    'payer' => $data['payer'] ?? null,
                    'memo' => $data['memo'] ?? null,
                    'created_by' => auth()->id(),
                ]);

                $entry = $this->ledger->post(new EntryDraft(
                    entryDate: CarbonImmutable::parse($data['receipt_date']),
                    memo: $data['memo'] ?? 'Other receipt',
                    sourceType: 'other_receipt',
                    sourceId: $receipt->id,
                    lines: [
                        // Dr the deposit bank/cash account
                        new LineDraft(accountId: $bankAccount->ledger_account_id, debit: $data['amount'], credit: 0),
                        // Cr the selected category account
                        new LineDraft(accountId: $data['category_account_id'], debit: 0, credit: $data['amount']),
                    ],
                ));

                $this->audit->log('other_receipt.recorded', $receipt, after: [
                    'entry_id' => $entry->id,
                    'bank_account_id' => $bankAccount->id,
                    'category_account_id' => $receipt->category_account_id,
                    'amount' => $receipt->amount,
                ]);

                return ['id' => $receipt->id];
            });
        });

        return OtherReceipt::query()->findOrFail($result['id']);
    }
}
