<?php

namespace App\Http\Requests;

use App\Http\Requests\Concerns\ValidatesDocumentLines;
use App\Models\BankAccount;
use App\Models\Contact;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;

class UpdateExpenseRequest extends FormRequest
{
    use ValidatesDocumentLines;

    /**
     * Authorization is enforced by the `can:expenses.draft` route middleware
     * (runs before this request is validated); the expense's own
     * draft-only-editable business rule is enforced in the controller
     * (checked before this request's validated() data is even used), so this
     * stays focused purely on shape validation. See StoreExpenseRequest for
     * why the field list is shaped the way it is.
     */
    public function authorize(): bool
    {
        return true;
    }

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

    private function validateContact(Validator $validator): void
    {
        $id = $this->input('contact_id');
        if ($id === null || $id === '') {
            return;
        }

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
