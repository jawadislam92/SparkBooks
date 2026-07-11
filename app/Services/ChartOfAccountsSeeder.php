<?php

namespace App\Services;

use App\Enums\AccountSubtype;
use App\Models\Account;
use App\Models\Company;

class ChartOfAccountsSeeder
{
    /**
     * Plants the spec §5 default Chart of Accounts. Runs inside the caller's
     * already-open company context and DB transaction — it does not open its
     * own transaction or set company context; Account::creating() auto-fills
     * company_id from the ambient CompanyContext.
     */
    public function seed(Company $company): void
    {
        foreach ($this->accounts() as $account) {
            Account::query()->create([
                'code' => $account['code'],
                'name' => $account['name'],
                'type' => $account['subtype']->type()->value,
                'subtype' => $account['subtype']->value,
                'is_system' => $account['is_system'],
                // Explicit, same reasoning as AccountController::store — see
                // its own comment for why this can't be left to the DB
                // column default alone.
                'parent_id' => null,
                'is_postable' => true,
            ]);
        }
    }

    /** @return list<array{code: string, name: string, subtype: AccountSubtype, is_system: bool}> */
    private function accounts(): array
    {
        return [
            ['code' => '1000', 'name' => 'Cash on Hand', 'subtype' => AccountSubtype::Cash, 'is_system' => true],
            ['code' => '1010', 'name' => 'Bank Account', 'subtype' => AccountSubtype::Bank, 'is_system' => true],
            ['code' => '1100', 'name' => 'Accounts Receivable', 'subtype' => AccountSubtype::Receivable, 'is_system' => true],
            ['code' => '1500', 'name' => 'Office Equipment', 'subtype' => AccountSubtype::FixedAsset, 'is_system' => false],
            ['code' => '2000', 'name' => 'Accounts Payable', 'subtype' => AccountSubtype::Payable, 'is_system' => true],
            ['code' => '2100', 'name' => 'Tax Payable', 'subtype' => AccountSubtype::Tax, 'is_system' => true],
            ['code' => '3000', 'name' => "Owner's Equity", 'subtype' => AccountSubtype::Equity, 'is_system' => false],
            ['code' => '3100', 'name' => 'Opening Balance Equity', 'subtype' => AccountSubtype::Equity, 'is_system' => true],
            ['code' => '3200', 'name' => 'Retained Earnings', 'subtype' => AccountSubtype::Equity, 'is_system' => true],
            ['code' => '3900', 'name' => 'Owner Drawings', 'subtype' => AccountSubtype::Equity, 'is_system' => false],
            ['code' => '4000', 'name' => 'Service Income', 'subtype' => AccountSubtype::OperatingIncome, 'is_system' => false],
            ['code' => '4900', 'name' => 'Other Income', 'subtype' => AccountSubtype::OtherIncome, 'is_system' => false],
            ['code' => '5000', 'name' => 'Salaries & Wages', 'subtype' => AccountSubtype::OperatingExpense, 'is_system' => false],
            ['code' => '5100', 'name' => 'Rent', 'subtype' => AccountSubtype::OperatingExpense, 'is_system' => false],
            ['code' => '5200', 'name' => 'Utilities', 'subtype' => AccountSubtype::OperatingExpense, 'is_system' => false],
            ['code' => '5300', 'name' => 'Internet & Phone', 'subtype' => AccountSubtype::OperatingExpense, 'is_system' => false],
            ['code' => '5400', 'name' => 'Software & Subscriptions', 'subtype' => AccountSubtype::OperatingExpense, 'is_system' => false],
            ['code' => '5500', 'name' => 'Marketing & Advertising', 'subtype' => AccountSubtype::OperatingExpense, 'is_system' => false],
            ['code' => '5600', 'name' => 'Office Supplies', 'subtype' => AccountSubtype::OperatingExpense, 'is_system' => false],
            ['code' => '5700', 'name' => 'Bank Fees & Charges', 'subtype' => AccountSubtype::OperatingExpense, 'is_system' => false],
            ['code' => '5800', 'name' => 'Travel', 'subtype' => AccountSubtype::OperatingExpense, 'is_system' => false],
            ['code' => '5900', 'name' => 'Professional Fees', 'subtype' => AccountSubtype::OperatingExpense, 'is_system' => false],
            ['code' => '5990', 'name' => 'Miscellaneous Expense', 'subtype' => AccountSubtype::OperatingExpense, 'is_system' => false],
            ...$this->fxAccounts(),
        ];
    }

    /**
     * The two FX settlement accounts (spec §17.1 Path 2 "FX-lite"): USD
     * invoice payments that convert to a different PKR amount than the
     * invoiced rate post their difference here. Split out as its own method
     * so App\Console\Commands\BackfillFxAccounts can add these exact same
     * accounts to companies that were seeded before this task existed,
     * without duplicating the definitions.
     *
     * @return list<array{code: string, name: string, subtype: AccountSubtype, is_system: bool}>
     */
    public function fxAccounts(): array
    {
        return [
            ['code' => '4950', 'name' => 'FX Gain', 'subtype' => AccountSubtype::OtherIncome, 'is_system' => true],
            ['code' => '5950', 'name' => 'FX Loss', 'subtype' => AccountSubtype::OperatingExpense, 'is_system' => true],
        ];
    }
}
