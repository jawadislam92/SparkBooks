<?php

namespace App\Services;

use App\Enums\AccountSubtype;
use App\Enums\AccountType;
use App\Models\Account;
use App\Models\BankAccount;
use App\Support\AuditLogger;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class BankAccountService
{
    private const CODE_BAND_START = 1000;

    private const CODE_BAND_END = 1099;

    // ChartOfAccountsSeeder already plants 1000 (Cash on Hand) and 1010
    // (Bank Account) for every company, so scanning for the next free code
    // starts past those, stepping by 10 to match the seed's own spacing.
    private const CODE_SCAN_START = 1020;

    /** Belt: max create() attempts before a code collision surfaces as an error. */
    private const MAX_ATTEMPTS = 3;

    public function __construct(private AuditLogger $audit) {}

    /**
     * Creates a bank/cash account AND its linked Chart-of-Accounts entry
     * together, inside one transaction — a bank account never exists without
     * a ledger account to hold its balance, and vice versa. No ledger entry
     * is posted here: opening balances are a later task (Phase 2 Task 8).
     *
     * Concurrency: two simultaneous create() calls for the same company must
     * not pick the same 4-digit code. Primary guard is `lockForUpdate()` in
     * nextAccountCode() — it serializes the read-then-insert per company
     * inside the transaction (the second caller waits, then reads the
     * now-updated set and picks the next free code), mirroring how
     * LedgerService allocates the invoice counter with SELECT … FOR UPDATE.
     * The bounded retry below is defense-in-depth: if the lock is ever
     * bypassed, a duplicate (company_id, code) insert is caught and the whole
     * transaction is retried with a fresh code rather than surfacing a 500.
     *
     * @param  array{name:string, type:string, currency_code:string}  $data
     */
    public function create(array $data): BankAccount
    {
        for ($attempt = 1; ; $attempt++) {
            try {
                return DB::transaction(fn () => $this->createOnce($data));
            } catch (UniqueConstraintViolationException $e) {
                if ($this->isAccountCodeCollision($e) && $attempt < self::MAX_ATTEMPTS) {
                    continue;
                }

                throw $e;
            }
        }
    }

    /** @param array{name:string, type:string, currency_code:string} $data */
    private function createOnce(array $data): BankAccount
    {
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
     * account creation), falls back to scanning every integer in the band.
     *
     * The used-codes read takes `lockForUpdate()` on the company's band rows.
     * Because create() already owns the surrounding DB::transaction, this
     * serializes concurrent same-company creates: the second create blocks on
     * the row/gap locks until the first commits, then reads the freshly
     * inserted code and picks the next one — no two concurrent creates can
     * choose the same code. MUST only be called from within create()'s
     * transaction (it is) for the lock to hold.
     */
    private function nextAccountCode(): string
    {
        $used = Account::query()
            ->whereBetween('code', [(string) self::CODE_BAND_START, (string) self::CODE_BAND_END])
            ->lockForUpdate()
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

        // Every code in the 1000–1099 band is taken — surface a friendly user
        // error (422), not an unhandled 500.
        throw ValidationException::withMessages([
            'name' => 'You’ve reached the maximum number of bank and cash accounts. Please contact support.',
        ]);
    }

    /**
     * Whether the violated unique index is the accounts (company_id, code)
     * index — i.e. a genuine code collision that the retry should re-pick,
     * as opposed to any other unique failure which must propagate untouched.
     * Prefers the driver's populated index name, falling back to a message
     * substring where ->index isn't set (mirrors ManualJournalController's
     * narrow duplicate-reversal classification).
     */
    private function isAccountCodeCollision(UniqueConstraintViolationException $e): bool
    {
        if ($e->index !== null) {
            return $e->index === 'accounts_company_id_code_unique';
        }

        return str_contains($e->getMessage(), 'accounts_company_id_code_unique');
    }
}
