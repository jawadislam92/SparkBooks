<?php

namespace App\Enums;

enum AccountSubtype: string
{
    case Cash = 'cash';
    case Bank = 'bank';
    case Receivable = 'receivable';
    case FixedAsset = 'fixed_asset';
    case CurrentAsset = 'current_asset';
    case Payable = 'payable';
    case Tax = 'tax';
    case CurrentLiability = 'current_liability';
    case Equity = 'equity';
    case OperatingIncome = 'operating_income';
    case OtherIncome = 'other_income';
    case OperatingExpense = 'operating_expense';

    public function type(): AccountType
    {
        return match ($this) {
            self::Cash, self::Bank, self::Receivable, self::FixedAsset, self::CurrentAsset => AccountType::Asset,
            self::Payable, self::Tax, self::CurrentLiability => AccountType::Liability,
            self::Equity => AccountType::Equity,
            self::OperatingIncome, self::OtherIncome => AccountType::Income,
            self::OperatingExpense => AccountType::Expense,
        };
    }
}
