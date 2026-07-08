<?php

namespace App\Http\Requests;

use App\Enums\AccountType;
use App\Models\Account;
use App\Models\TaxRate;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;

class StoreItemRequest extends FormRequest
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
     * `is_archived` is deliberately absent: a new item is never created
     * pre-archived — the controller never reads it from the request. Archive
     * status only ever changes via the dedicated archive/unarchive actions.
     *
     * `unit_price` arrives as integer minor units already — the client
     * converts a typed decimal via parseMoneyInput() before submitting, never
     * sending a float. The `integer` rule rejects a float-string (e.g.
     * "19.99") outright, since it fails on any value containing a decimal
     * point.
     *
     * `default_income_account_id` / `default_tax_rate_id` only get a shape
     * check here (must be an integer if present) — the semantic checks
     * (belongs to this tenant, correct type, not archived) happen in
     * withValidator() below so each failure gets its own clear message
     * instead of one generic "invalid selection".
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
