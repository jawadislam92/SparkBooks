<?php

namespace App\Http\Requests;

use App\Http\Requests\Concerns\ValidatesDocumentLines;
use App\Models\Contact;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;

class StoreBillRequest extends FormRequest
{
    use ValidatesDocumentLines;

    /**
     * Authorization is enforced by the `can:bills.draft` route middleware
     * (runs before this request is validated), so this stays focused purely
     * on shape validation. Mirrors StoreInvoiceRequest/StoreExpenseRequest.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * `status`/`subtotal`/`tax_total`/`total`/`amount_paid`/`balance` are
     * deliberately absent: a draft is always created with status=draft and
     * every total is computed server-side by DocumentLineCalculator — never
     * accepted from the client. Unlike Expense, `contact_id` here is
     * REQUIRED and must be a vendor/both contact (spec §7.8: a bill always
     * names a vendor) — checked in withValidator() below. `due_date` is
     * nullable at draft (required at receive — a later task's
     * BillService::receive() guard, per Task 1's own carry-forward note).
     */
    public function rules(): array
    {
        return [
            'contact_id' => ['required', 'integer'],
            'reference' => ['nullable', 'string', 'max:120'],
            'bill_date' => ['nullable', 'date'],
            'due_date' => ['nullable', 'date'],
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
            $this->validateDueAfterBill($validator);
            $this->validateLines($validator);
        });
    }

    /**
     * The vendor is required and must be able to act as a vendor
     * (type vendor or both) — mirrors StoreInvoiceRequest's isCustomer()
     * check exactly, just the AP-side mirror.
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

            return;
        }

        if (! $contact->type->isVendor()) {
            $validator->errors()->add('contact_id', "This contact isn't a vendor. Choose a vendor, or edit the contact to also be a vendor.");
        }
    }

    private function validateDueAfterBill(Validator $validator): void
    {
        $billDate = $this->input('bill_date');
        $dueDate = $this->input('due_date');

        if (! $billDate || ! $dueDate || ! is_string($billDate) || ! is_string($dueDate)) {
            return;
        }

        if (strtotime($dueDate) !== false && strtotime($billDate) !== false && strtotime($dueDate) < strtotime($billDate)) {
            $validator->errors()->add('due_date', 'Due date must be on or after the bill date.');
        }
    }
}
