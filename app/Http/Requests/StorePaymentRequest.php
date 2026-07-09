<?php

namespace App\Http\Requests;

use App\Models\BankAccount;
use App\Models\Invoice;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;

class StorePaymentRequest extends FormRequest
{
    /**
     * Authorization is enforced by the `can:payments.record` route
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
     * validation (required + UUID format) alongside everything else. Mirrors
     * StoreTransferRequest.
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
     * an integer — same reasoning as StoreTransferRequest.
     *
     * `base_amount` (Task 8, spec §17.1 P2fx) is the actual base/PKR minor
     * units the bank credited — only meaningful, and only REQUIRED, for a
     * foreign-currency invoice (enforced in withValidator() below, where the
     * invoice's currency is available). `nullable` here rather than
     * `required` because a domestic-invoice payment legitimately omits it —
     * InvoicePaymentService::resolveBaseAmount() defaults/forces it to
     * `amount` server-side either way.
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
            'base_amount' => ['nullable', 'integer', 'min:1'],
            'bank_account_id' => ['required', 'integer'],
            'payment_date' => ['required', 'date'],
            'memo' => ['nullable', 'string', 'max:500'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            $this->validateBankAccount($validator);
            $this->validateBaseAmountRequiredForForeignInvoice($validator);
        });
    }

    /**
     * `$invoice` travels as a plain (non-bound) route parameter — see
     * InvoiceController's own doc note on why — so it's resolved here the
     * same way the controller resolves it. BelongsToCompany's global scope
     * means a foreign-company invoice id comes back null here exactly like a
     * nonexistent one; both are simply skipped rather than erroring — the
     * controller's own findOrFail() is what turns that into the correct 404,
     * and this check has nothing useful to say about an invoice it can't see.
     */
    private function validateBaseAmountRequiredForForeignInvoice(Validator $validator): void
    {
        $invoice = Invoice::query()->find($this->route('invoice'));

        if ($invoice === null || bccomp((string) $invoice->fx_rate, '1', 8) === 0) {
            return;
        }

        $baseAmount = $this->input('base_amount');
        if ($baseAmount === null || $baseAmount === '') {
            $validator->errors()->add(
                'base_amount',
                'The actual base amount the bank credited is required for a foreign-currency invoice payment.'
            );
        }
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
