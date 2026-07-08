<?php

namespace App\Services;

use App\Enums\AccountSubtype;
use App\Enums\AccountType;
use App\Models\Account;
use App\Models\BankAccount;
use App\Support\AuditLogger;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class BankAccountService
{
    private const CODE_BAND_START = 1000;

    private const CODE_BAND_END = 1099;

    // ChartOfAccountsSeeder already plants 1000 (Cash on Hand) and 1010
    // (Bank Account) for every company, so scanning for the next free code
    // starts past those, stepping by 10 to match the seed's own spacing.
    private const CODE_SCAN_START = 1020;

    public function __construct(private AuditLogger $audit) {}

    /**
     * Creates a bank/cash account AND its linked Chart-of-Accounts entry
     * together, inside one transaction — a bank account never exists without
     * a ledger account to hold its balance, and vice versa. No ledger entry
     * is posted here: opening balances are a later task (Phase 2 Task 8).
     *
     * @param  array{name:string, type:string, currency_code:string}  $data
     */
    public function create(array $data): BankAccount
    {
        return DB::transaction(function () use ($data) {
            $ledgerAccount = Account::query()->create([
                'code' => $this->nextAccountCode(),
                'name' => $data['name'],
                'type' => AccountType::Asset->value,
                'subtype' => ($data['type'] === 'cash' ? AccountSubtype::Cash : AccountSubtype::Bank)->value,
                // Never a system account — but it isn't user-deletable in
                // practice either: Account::booted() forbids deleting ANY
                // account (system or not), so the linked ledger account is
                // already safe from deletion while (or after) the bank
                // account row exists.
                'is_system' => false,
            ]);

            $bankAccount = BankAccount::query()->create([
                'name' => $data['name'],
                'type' => $data['type'],
                'currency_code' => $data['currency_code'],
                'ledger_account_id' => $ledgerAccount->id,
            ]);

            $this->audit->log('bank_account.created', $bankAccount, after: [
                'name' => $bankAccount->name,
                'type' => $bankAccount->type,
                'currency_code' => $bankAccount->currency_code,
                'ledger_account_id' => $ledgerAccount->id,
                'ledger_account_code' => $ledgerAccount->code,
            ]);

            return $bankAccount;
        });
    }

    /**
     * Rename only — type/currency are immutable after creation (the linked
     * ledger account's subtype is fixed at creation time). Also mirrors the
     * new name onto the linked ledger account so the Chart of Accounts stays
     * in sync with the name that was used to auto-create it.
     */
    public function rename(BankAccount $bankAccount, string $name): BankAccount
    {
        return DB::transaction(function () use ($bankAccount, $name) {
            $before = $bankAccount->only(['name']);

            $bankAccount->update(['name' => $name]);
            $bankAccount->ledgerAccount->update(['name' => $name]);

            $this->audit->log('bank_account.updated', $bankAccount, before: $before, after: ['name' => $name]);

            return $bankAccount;
        });
    }

    /**
     * Archiving a bank/cash account is deliberately independent of its
     * linked ledger account: the ledger account's historical balance must
     * stay visible on the Chart of Accounts / Trial Balance even after the
     * bank account itself is archived and hidden from the Banking screen.
     * Only the bank_accounts row is touched here.
     */
    public function archive(BankAccount $bankAccount): BankAccount
    {
        $bankAccount->update(['is_archived' => true]);
        $this->audit->log('bank_account.archived', $bankAccount);

        return $bankAccount;
    }

    public function unarchive(BankAccount $bankAccount): BankAccount
    {
        $bankAccount->update(['is_archived' => false]);
        $this->audit->log('bank_account.unarchived', $bankAccount);

        return $bankAccount;
    }

    /**
     * Next free 4-digit code in the 1000–1099 "money accounts" band, unique
     * per company. Steps by 10 from 1020 to mirror the seed's own spacing;
     * if every multiple of ten in the band is already taken (heavy manual
     * account creation), falls back to scanning every integer in the band
     * before giving up.
     */
    private function nextAccountCode(): string
    {
        $used = Account::query()
            ->whereBetween('code', [(string) self::CODE_BAND_START, (string) self::CODE_BAND_END])
            ->pluck('code')
            ->map(fn ($code) => (int) $code)
            ->all();

        for ($code = self::CODE_SCAN_START; $code <= self::CODE_BAND_END; $code += 10) {
            if (! in_array($code, $used, true)) {
                return (string) $code;
            }
        }

        for ($code = self::CODE_BAND_START; $code <= self::CODE_BAND_END; $code++) {
            if (! in_array($code, $used, true)) {
                return (string) $code;
            }
        }

        throw new RuntimeException('No free account code available in the 1000-1099 band.');
    }
}
