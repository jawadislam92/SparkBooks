<?php

namespace App\Http\Requests;

use App\Models\BankAccount;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;

class StoreBillPaymentRequest extends FormRequest
{
    /**
     * Authorization is enforced by the `can:payments.record` route
     * middleware (runs before this request is validated), so this stays
     * focused purely on shape validation. Mirrors StorePaymentRequest.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * The idempotency key travels in a header, not the request body — lift it
     * into a virtual `idempotency_key` field so it participates in normal
     * validation (required + UUID format) alongside everything else. Mirrors
     * StorePaymentRequest/StoreTransferRequest.
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
     * an integer — same reasoning as StorePaymentRequest. No `base_amount`
     * here (unlike StorePaymentRequest) — bills are base-currency-only in
     * this phase (Phase 4 brief), so there's no separate "actual base amount
     * the bank credited" to capture.
     *
     * `bank_account_id` only gets a shape check here (must be an integer) —
     * the semantic checks (belongs to this tenant, not archived) happen in
     * withValidator() below so the failure gets its own clear message.
     */
    public function rules(): array
    {
        return [
            'idempotency_key' => ['required', 'uuid'],
            'amount' => ['required', 'integer', 'min:1'],
            'bank_account_id' => ['required', 'integer'],
            'payment_date' => ['required', 'date'],
            'memo' => ['nullable', 'string', 'max:500'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            $this->validateBankAccount($validator);
        });
    }

    private function validateBankAccount(Validator $validator): void
    {
        $id = $this->input('bank_account_id');
        if ($id === null || $id === '') {
            return;
        }

        // BankAccount's BelongsToCompany global scope means a foreign-company
        // id is indistinguishable from a nonexistent one here — both come
        // back null, and both are rejected as "not found".
        $bankAccount = BankAccount::query()->find($id);

        if ($bankAccount === null) {
            $validator->errors()->add('bank_account_id', 'The selected account was not found.');

            return;
        }

        if ($bankAccount->is_archived) {
            $validator->errors()->add('bank_account_id', 'The selected account is archived.');
        }
    }
}
