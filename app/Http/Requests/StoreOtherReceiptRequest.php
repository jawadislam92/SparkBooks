<?php

namespace App\Http\Requests;

use App\Enums\AccountSubtype;
use App\Models\Account;
use App\Models\BankAccount;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;

class StoreOtherReceiptRequest extends FormRequest
{
    /**
     * Authorization is enforced by the `can:other_receipts.create` route
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
     * Both account ids only get a shape check here (must be an integer) — the
     * semantic checks (belongs to this tenant, not archived, category
     * eligibility) happen in withValidator() below so each failure gets its
     * own clear message.
     */
    public function rules(): array
    {
        return [
            'idempotency_key' => ['required', 'uuid'],
            'bank_account_id' => ['required', 'integer'],
            'category_account_id' => ['required', 'integer'],
            'receipt_date' => ['required', 'date'],
            'amount' => ['required', 'integer', 'min:1'],
            'payer' => ['nullable', 'string', 'max:120'],
            'memo' => ['nullable', 'string', 'max:500'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            $this->validateBankAccount($validator);
            $this->validateCategoryAccount($validator);
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

    /**
     * The category (credit side) account must be a real, non-archived,
     * tenant-owned account that is NOT a control account (AR/AP — money in
     * must never fake a receivable) and NOT a bank/cash account (moving money
     * between the tenant's own bank/cash accounts is a transfer, not a
     * receipt).
     */
    private function validateCategoryAccount(Validator $validator): void
    {
        $id = $this->input('category_account_id');
        if ($id === null || $id === '') {
            return;
        }

        // Account's BelongsToCompany global scope means a foreign-company id
        // is indistinguishable from a nonexistent one here — both come back
        // null, and both are rejected as "not found".
        $account = Account::query()->find($id);

        if ($account === null) {
            $validator->errors()->add('category_account_id', 'The selected category was not found.');

            return;
        }

        if ($account->is_archived) {
            $validator->errors()->add('category_account_id', 'The selected category is archived.');

            return;
        }

        if ($account->isControlAccount()) {
            $validator->errors()->add('category_account_id', 'Choose a category account — accounts that track money owed to or from a customer or vendor cannot be used here.');

            return;
        }

        if (in_array($account->subtype, [AccountSubtype::Cash, AccountSubtype::Bank], true)) {
            $validator->errors()->add('category_account_id', 'Choose a category account — moving money into one of your own bank/cash accounts is a transfer, not a receipt.');
        }
    }
}
