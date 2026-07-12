<?php

namespace App\Http\Requests;

use App\Enums\AccountType;
use App\Models\Account;
use App\Models\Contact;
use App\Models\Item;
use App\Models\TaxRate;
use App\Support\Currencies;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateInvoiceRequest extends FormRequest
{
    /**
     * Authorization is enforced by the `can:invoices.draft` route middleware
     * (runs before this request is validated); the invoice's own
     * draft-only-editable business rule is enforced in the controller
     * (checked before this request's validated() data is even used), so this
     * stays focused purely on shape validation. See StoreInvoiceRequest for
     * why the field list is shaped the way it is.
     */
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'contact_id' => ['required', 'integer'],
            'currency_code' => ['required', 'string', Rule::in(Currencies::COMMON)],
            'issue_date' => ['nullable', 'date'],
            'due_date' => ['nullable', 'date'],
            'notes' => ['nullable', 'string', 'max:2000'],

            'lines' => ['required', 'array', 'min:1'],
            'lines.*.description' => ['required', 'string', 'max:255'],
            'lines.*.quantity' => ['required', 'string'],
            'lines.*.unit_price' => ['required', 'integer', 'min:0'],
            'lines.*.income_account_id' => ['required', 'integer'],
            'lines.*.tax_rate_id' => ['nullable', 'integer'],
            'lines.*.item_id' => ['nullable', 'integer'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            $this->validateContact($validator);
            $this->validateDueAfterIssue($validator);
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

            return;
        }

        if (! $contact->type->isCustomer()) {
            $validator->errors()->add('contact_id', "This contact isn't a customer. Choose a customer, or edit the contact to also be a customer.");
        }
    }

    private function validateDueAfterIssue(Validator $validator): void
    {
        $issue = $this->input('issue_date');
        $due = $this->input('due_date');

        if (! $issue || ! $due || ! is_string($issue) || ! is_string($due)) {
            return;
        }

        if (strtotime($due) !== false && strtotime($issue) !== false && strtotime($due) < strtotime($issue)) {
            $validator->errors()->add('due_date', 'Due date must be on or after the issue date.');
        }
    }

    private function validateLines(Validator $validator): void
    {
        foreach ((array) $this->input('lines', []) as $index => $line) {
            if (! is_array($line)) {
                continue;
            }

            $this->validateQuantity($validator, $index, $line);
            $this->validateIncomeAccount($validator, $index, $line);
            $this->validateTaxRate($validator, $index, $line);
            $this->validateItem($validator, $index, $line);
        }
    }

    private function validateQuantity(Validator $validator, int $index, array $line): void
    {
        $quantity = $line['quantity'] ?? null;
        if (! is_string($quantity) || trim($quantity) === '') {
            return;
        }

        $trimmed = trim($quantity);

        if (preg_match('/^\d+(\.\d{1,4})?$/', $trimmed) !== 1) {
            $validator->errors()->add("lines.{$index}.quantity", 'Enter a valid quantity with up to 4 decimal places.');

            return;
        }

        if (bccomp($trimmed, '0', 4) <= 0) {
            $validator->errors()->add("lines.{$index}.quantity", 'Quantity must be greater than zero.');
        }
    }

    private function validateIncomeAccount(Validator $validator, int $index, array $line): void
    {
        $id = $line['income_account_id'] ?? null;
        if ($id === null || $id === '') {
            return;
        }

        $account = Account::query()->find($id);

        if ($account === null) {
            $validator->errors()->add("lines.{$index}.income_account_id", 'The selected income account was not found.');

            return;
        }

        if ($account->is_archived) {
            $validator->errors()->add("lines.{$index}.income_account_id", 'The selected income account is archived.');

            return;
        }

        if ($account->type !== AccountType::Income) {
            $validator->errors()->add("lines.{$index}.income_account_id", 'The selected account is not an income account.');
        }
    }

    private function validateTaxRate(Validator $validator, int $index, array $line): void
    {
        $id = $line['tax_rate_id'] ?? null;
        if ($id === null || $id === '') {
            return;
        }

        $taxRate = TaxRate::query()->find($id);

        if ($taxRate === null) {
            $validator->errors()->add("lines.{$index}.tax_rate_id", 'The selected tax rate was not found.');

            return;
        }

        if ($taxRate->is_archived) {
            $validator->errors()->add("lines.{$index}.tax_rate_id", 'The selected tax rate is archived.');
        }
    }

    private function validateItem(Validator $validator, int $index, array $line): void
    {
        $id = $line['item_id'] ?? null;
        if ($id === null || $id === '') {
            return;
        }

        // See StoreInvoiceRequest::validateItem for why item_id — a
        // client-side prefill hint, never persisted — still gets a full
        // tenant/archived check here.
        $item = Item::query()->find($id);

        if ($item === null) {
            $validator->errors()->add("lines.{$index}.item_id", 'The selected item was not found.');

            return;
        }

        if ($item->is_archived) {
            $validator->errors()->add("lines.{$index}.item_id", 'The selected item is archived.');
        }
    }
}
