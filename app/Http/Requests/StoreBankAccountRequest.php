<?php

namespace App\Http\Requests;

use App\Support\CompanyContext;
use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreBankAccountRequest extends FormRequest
{
    /**
     * Authorization is enforced by the `can:bank_accounts.manage` route
     * middleware (runs before this request is validated), so this stays
     * focused purely on shape validation.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * `ledger_account_id` and `is_archived` are deliberately absent: the
     * linked ledger account is always derived by BankAccountService, and a
     * new bank account is never created pre-archived — the controller never
     * reads either from the request.
     *
     * `currency_code` is constrained to the company's OWN base currency in
     * v1 (spec §17.1 — foreign-currency bank accounts are excluded until a
     * later phase), via a closure rule so the rejection message can name the
     * actual required currency.
     */
    public function rules(CompanyContext $context): array
    {
        $baseCurrency = $context->get()->base_currency;

        return [
            'name' => ['required', 'string', 'max:120'],
            'type' => ['required', Rule::in(['bank', 'cash'])],
            'currency_code' => [
                'required',
                'string',
                function (string $attribute, mixed $value, Closure $fail) use ($baseCurrency) {
                    if ($value !== $baseCurrency) {
                        $fail("Bank accounts must use your base currency ({$baseCurrency}) for now.");
                    }
                },
            ],
        ];
    }
}
