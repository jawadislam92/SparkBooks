<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;

class UpdateBankAccountRequest extends FormRequest
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
     * Rename only: `type` and `currency_code` are immutable after creation
     * (the linked ledger account's subtype/currency assumption is fixed at
     * creation time) — see withValidator() below, which rejects them
     * outright rather than silently dropping them, mirroring
     * UpdateAccountRequest's handling of system-account-only fields.
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:120'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            foreach (['type', 'currency_code', 'company_id', 'ledger_account_id', 'is_archived'] as $field) {
                if ($this->has($field)) {
                    $validator->errors()->add($field, "The {$field} field cannot be changed after creation.");
                }
            }
        });
    }
}
