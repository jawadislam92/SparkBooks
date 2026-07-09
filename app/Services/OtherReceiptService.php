<?php

namespace App\Services;

use App\Domain\Ledger\EntryDraft;
use App\Domain\Ledger\LineDraft;
use App\Enums\AccountSubtype;
use App\Exceptions\IneligibleCategoryAccount;
use App\Models\Account;
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
     * The category account's eligibility (tenant-owned, not archived, not a
     * control account, not a bank/cash account) is ALSO enforced up-front by
     * StoreOtherReceiptRequest for a friendly, field-level error before the
     * service is even reached — but that request lives on the HTTP path only.
     * This service is the caller-independent guard: it re-checks the rule
     * itself (see guardCategoryAccount) so a direct caller (e.g. the Task 9
     * CSV import) can never post a disguised transfer or a receivable-faking
     * receipt. The one rule with no other backstop is bank/cash-as-category:
     * LedgerService legitimately accepts bank/cash lines (they are how
     * transfers post), so the ban has to live here, not in the frozen gate.
     *
     * @param  array{bank_account_id:int, category_account_id:int, receipt_date:string, amount:int, payer?:?string, memo?:?string}  $data
     *
     * @throws IneligibleCategoryAccount when the category account is archived,
     *                                   a control account, or a bank/cash account.
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

                // Same scoped-findOrFail tenant guard for the category account,
                // then the OtherReceipt-specific eligibility rule.
                $categoryAccount = Account::query()->findOrFail($data['category_account_id']);
                $this->guardCategoryAccount($categoryAccount);

                $receipt = OtherReceipt::query()->create([
                    'bank_account_id' => $bankAccount->id,
                    'category_account_id' => $categoryAccount->id,
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
                        new LineDraft(accountId: $categoryAccount->id, debit: 0, credit: $data['amount']),
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

    /**
     * The OtherReceipt-specific eligibility rule for the category (credit)
     * side. Money-in that isn't an invoice must land against an income /
     * equity / other real category account — never against a control account
     * (that would fake a receivable/payable) and never against a bank/cash
     * account (that would be a disguised transfer). Archived accounts are also
     * refused. Kept out of LedgerService on purpose: the gate legitimately
     * accepts bank/cash lines for transfers, so this ban belongs to the
     * receipt use case, not the ledger.
     *
     * @throws IneligibleCategoryAccount
     */
    private function guardCategoryAccount(Account $account): void
    {
        if ($account->is_archived) {
            throw new IneligibleCategoryAccount("Account {$account->code} is archived and cannot be used as a category.");
        }

        if ($account->isControlAccount()) {
            throw new IneligibleCategoryAccount("Account {$account->code} tracks money owed to or from a customer or vendor and cannot be used as a category.");
        }

        if (in_array($account->subtype, [AccountSubtype::Cash, AccountSubtype::Bank], true)) {
            throw new IneligibleCategoryAccount("This account can't be used as a category. Choose an income or equity account.");
        }
    }
}
