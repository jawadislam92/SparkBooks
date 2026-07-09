<?php

namespace App\Http\Requests;

use App\Models\BankAccount;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;

class StoreTransferRequest extends FormRequest
{
    /**
     * Authorization is enforced by the `can:transfers.create` route
     * middleware (runs before this request is validated), so this stays
     * focused purely on shape validation.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * The idempotency key travels in a header, not the request body — lift it
     * into a virtual `idempotency_key` field so it participates in normal
     * validation (required + UUID format) alongside everything else.
     */
    protected function prepareForValidation(): void
    {
        $this->merge([
            'idempotency_key' => $this->header('X-Idempotency-Key'),
        ]);
    }

    /**
     * `amount` arrives as integer minor units already — the client converts a
     * typed decimal via parseMoneyInput() before submitting, never sending a
     * float. `min:1` enforces "> 0" since amount is already constrained to be
     * an integer.
     *
     * Bank account ids only get a shape check here (must be an integer) — the
     * semantic checks (belongs to this tenant, not archived, distinct from
     * each other) happen in withValidator() below so each failure gets its
     * own clear message.
     */
    public function rules(): array
    {
        return [
            'idempotency_key' => ['required', 'uuid'],
            'from_bank_account_id' => ['required', 'integer'],
            'to_bank_account_id' => ['required', 'integer'],
            'transfer_date' => ['required', 'date'],
            'amount' => ['required', 'integer', 'min:1'],
            'memo' => ['nullable', 'string', 'max:500'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            $this->validateDistinctAccounts($validator);
            $this->validateBankAccount($validator, 'from_bank_account_id');
            $this->validateBankAccount($validator, 'to_bank_account_id');
        });
    }

    private function validateDistinctAccounts(Validator $validator): void
    {
        $from = $this->input('from_bank_account_id');
        $to = $this->input('to_bank_account_id');

        if ($from !== null && $from !== '' && $to !== null && $to !== '' && $from == $to) {
            $validator->errors()->add('to_bank_account_id', 'Choose two different accounts.');
        }
    }

    private function validateBankAccount(Validator $validator, string $field): void
    {
        $id = $this->input($field);
        if ($id === null || $id === '') {
            return;
        }

        // BankAccount's BelongsToCompany global scope means a foreign-company
        // id is indistinguishable from a nonexistent one here — both come
        // back null, and both are rejected as "not found".
        $bankAccount = BankAccount::query()->find($id);

        if ($bankAccount === null) {
            $validator->errors()->add($field, 'The selected account was not found.');

            return;
        }

        if ($bankAccount->is_archived) {
            $validator->errors()->add($field, 'The selected account is archived.');
        }
    }
}
