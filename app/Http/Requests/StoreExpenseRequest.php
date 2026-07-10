<?php

namespace App\Http\Requests;

use App\Http\Requests\Concerns\ValidatesDocumentLines;
use App\Models\BankAccount;
use App\Models\Contact;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;

class StoreExpenseRequest extends FormRequest
{
    use ValidatesDocumentLines;

    /**
     * Authorization is enforced by the `can:expenses.draft` route middleware
     * (runs before this request is validated), so this stays focused purely
     * on shape validation. Mirrors StoreInvoiceRequest.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * `status`/`subtotal`/`tax_total`/`total` are deliberately absent: a
     * draft is always created with status=draft and every total is computed
     * server-side by DocumentLineCalculator from the lines — never accepted
     * from the client. Unlike Invoice/Bill, `contact_id` here is the
     * (optional) PAYEE — spec §7.8 puts no type restriction on it (any
     * tenant contact, not just a vendor), since a cash purchase may have no
     * contact on file at all. `bank_account_id` is nullable at draft
     * (required at record — a later task's ExpenseService::record() guard,
     * not a schema/request constraint here, per Task 1's own carry-forward
     * note).
     *
     * `amount` arrives as integer minor units already (client-side
     * parseMoneyInput) — `min:1` enforces "> 0" per the brief (unlike
     * invoice_lines.unit_price, which allows a $0 line).
     */
    public function rules(): array
    {
        return [
            'contact_id' => ['nullable', 'integer'],
            'expense_date' => ['nullable', 'date'],
            'bank_account_id' => ['nullable', 'integer'],
            'notes' => ['nullable', 'string', 'max:2000'],

            'lines' => ['required', 'array', 'min:1'],
            'lines.*.description' => ['required', 'string', 'max:255'],
            'lines.*.category_account_id' => ['required', 'integer'],
            'lines.*.tax_rate_id' => ['nullable', 'integer'],
            'lines.*.amount' => ['required', 'integer', 'min:1'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            $this->validateContact($validator);
            $this->validateBankAccount($validator);
            $this->validateLines($validator);
        });
    }

    /**
     * The payee, if given, only needs to be a real, non-archived, tenant-
     * owned contact — no customer/vendor type restriction (spec §7.8: "any
     * tenant contact").
     */
    private function validateContact(Validator $validator): void
    {
        $id = $this->input('contact_id');
        if ($id === null || $id === '') {
            return;
        }

        // Contact's BelongsToCompany global scope means a foreign-company id
        // is indistinguishable from a nonexistent one here — both come back
        // null, and both are rejected as "not found".
        $contact = Contact::query()->find($id);

        if ($contact === null) {
            $validator->errors()->add('contact_id', 'The selected contact was not found.');

            return;
        }

        if ($contact->is_archived) {
            $validator->errors()->add('contact_id', 'The selected contact is archived.');
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
