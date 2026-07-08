<?php

namespace App\Http\Requests;

use App\Enums\AccountType;
use App\Models\Account;
use App\Models\TaxRate;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;

class UpdateItemRequest extends FormRequest
{
    /**
     * Authorization is enforced by the `can:items.manage` route middleware
     * (runs before this request is validated), so this stays focused purely
     * on shape validation.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * `is_archived` is deliberately absent — archive status only ever changes
     * via the dedicated archive/unarchive actions, never through a plain
     * update payload. See StoreItemRequest for why unit_price/account/tax
     * rules are shaped the way they are.
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:120'],
            'description' => ['nullable', 'string', 'max:2000'],
            'unit_price' => ['nullable', 'integer', 'min:0'],
            'default_income_account_id' => ['nullable', 'integer'],
            'default_tax_rate_id' => ['nullable', 'integer'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            $this->validateIncomeAccount($validator);
            $this->validateTaxRate($validator);
        });
    }

    private function validateIncomeAccount(Validator $validator): void
    {
        $accountId = $this->input('default_income_account_id');
        if ($accountId === null || $accountId === '') {
            return;
        }

        // Account's BelongsToCompany global scope means a foreign-company id
        // is indistinguishable from a nonexistent one here — both come back
        // null, and both are rejected as "not found".
        $account = Account::query()->find($accountId);

        if ($account === null) {
            $validator->errors()->add('default_income_account_id', 'The selected income account was not found.');

            return;
        }

        if ($account->is_archived) {
            $validator->errors()->add('default_income_account_id', 'The selected income account is archived.');

            return;
        }

        if ($account->type !== AccountType::Income) {
            $validator->errors()->add('default_income_account_id', 'The selected account is not an income account.');
        }
    }

    private function validateTaxRate(Validator $validator): void
    {
        $taxRateId = $this->input('default_tax_rate_id');
        if ($taxRateId === null || $taxRateId === '') {
            return;
        }

        $taxRate = TaxRate::query()->find($taxRateId);

        if ($taxRate === null) {
            $validator->errors()->add('default_tax_rate_id', 'The selected tax rate was not found.');

            return;
        }

        if ($taxRate->is_archived) {
            $validator->errors()->add('default_tax_rate_id', 'The selected tax rate is archived.');
        }
    }
}
