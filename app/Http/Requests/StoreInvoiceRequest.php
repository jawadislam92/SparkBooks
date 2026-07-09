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

class StoreInvoiceRequest extends FormRequest
{
    /**
     * Authorization is enforced by the `can:invoices.draft` route middleware
     * (runs before this request is validated), so this stays focused purely
     * on shape validation.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * `status`/`number`/`subtotal`/`tax_total`/`total`/`amount_paid`/
     * `balance`/`base_*` are deliberately absent: a draft is always created
     * with status=draft and no number, and every total is computed server-
     * side by InvoiceCalculator from the lines — never accepted from the
     * client. `item_id` on a line is a client-only prefill hint (invoice_lines
     * has no item_id column, see database/migrations/..._create_invoice_lines
     * _table.php) — it only gets an existence check below, never persisted.
     *
     * `quantity` arrives as a decimal STRING (e.g. "2.5"), not a number —
     * mirrors how `unit_price` arrives as an already-converted integer
     * (client-side parseMoneyInput). `unit_price`'s `integer` rule rejects a
     * float-string outright, same reasoning as StoreItemRequest.
     *
     * `contact_id`/`income_account_id`/`tax_rate_id`/`item_id` only get a
     * shape check here (must be an integer) — the semantic checks (belongs
     * to this tenant, correct type, not archived) happen in withValidator()
     * below so each failure gets its own clear message.
     */
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

    /**
     * quantity must be a plain positive decimal with at most 4 fractional
     * digits — mirrors Money::multiplyByQuantity's own guard, checked here
     * too so a malformed quantity comes back as a friendly 422 field error
     * instead of only surfacing as an InvalidArgumentException once it
     * reaches InvoiceCalculator.
     */
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

        // Account's BelongsToCompany global scope means a foreign-company id
        // is indistinguishable from a nonexistent one here — both come back
        // null, and both are rejected as "not found".
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

        // Item's BelongsToCompany global scope means a foreign-company id is
        // indistinguishable from a nonexistent one — item_id is only ever a
        // client-side prefill hint (never persisted), so this just confirms
        // it's a real, tenant-owned item rather than a stray/foreign id.
        if (Item::query()->find($id) === null) {
            $validator->errors()->add("lines.{$index}.item_id", 'The selected item was not found.');
        }
    }
}
