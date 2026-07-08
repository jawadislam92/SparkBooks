<?php

namespace App\Http\Requests;

use App\Models\BankAccount;
use App\Models\Contact;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;

class StoreOpeningBalanceRequest extends FormRequest
{
    /**
     * Authorization is enforced by the `can:settings.manage` route middleware
     * (runs before this request is validated), so this stays focused purely
     * on shape validation.
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
     * bank_account_id / contact_id only get a shape check here (must be an
     * integer) — the semantic checks (belongs to this tenant, not archived)
     * happen in withValidator() below so each row gets its own clear message.
     */
    public function rules(): array
    {
        return [
            'idempotency_key' => ['required', 'uuid'],
            'books_start_date' => ['required', 'date'],

            'bank_lines' => ['array'],
            'bank_lines.*.bank_account_id' => ['required', 'integer'],
            'bank_lines.*.amount' => ['required', 'integer', 'min:1'],

            'customer_lines' => ['array'],
            'customer_lines.*.contact_id' => ['required', 'integer'],
            'customer_lines.*.reference' => ['nullable', 'string', 'max:120'],
            'customer_lines.*.original_date' => ['required', 'date'],
            'customer_lines.*.due_date' => ['required', 'date'],
            'customer_lines.*.amount' => ['required', 'integer', 'min:1'],

            'vendor_lines' => ['array'],
            'vendor_lines.*.contact_id' => ['required', 'integer'],
            'vendor_lines.*.reference' => ['nullable', 'string', 'max:120'],
            'vendor_lines.*.original_date' => ['required', 'date'],
            'vendor_lines.*.due_date' => ['required', 'date'],
            'vendor_lines.*.amount' => ['required', 'integer', 'min:1'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            $this->validateAtLeastOneLine($validator);
            $this->validateBankAccounts($validator);
            $this->validateContacts($validator, 'customer_lines');
            $this->validateContacts($validator, 'vendor_lines');
            $this->validateDueDates($validator, 'customer_lines');
            $this->validateDueDates($validator, 'vendor_lines');
        });
    }

    private function validateAtLeastOneLine(Validator $validator): void
    {
        $total = count($this->input('bank_lines', []))
            + count($this->input('customer_lines', []))
            + count($this->input('vendor_lines', []));

        if ($total === 0) {
            $validator->errors()->add('bank_lines', 'Add at least one bank balance, customer invoice, or vendor bill.');
        }
    }

    private function validateBankAccounts(Validator $validator): void
    {
        foreach ($this->input('bank_lines', []) as $index => $line) {
            $id = $line['bank_account_id'] ?? null;
            if ($id === null || $id === '') {
                continue;
            }

            // BankAccount's BelongsToCompany global scope means a
            // foreign-company id is indistinguishable from a nonexistent one
            // here — both come back null, and both are rejected as "not found".
            $bankAccount = BankAccount::query()->find($id);

            if ($bankAccount === null) {
                $validator->errors()->add("bank_lines.{$index}.bank_account_id", 'The selected account was not found.');

                continue;
            }

            if ($bankAccount->is_archived) {
                $validator->errors()->add("bank_lines.{$index}.bank_account_id", 'The selected account is archived.');
            }
        }
    }

    private function validateContacts(Validator $validator, string $field): void
    {
        foreach ($this->input($field, []) as $index => $line) {
            $id = $line['contact_id'] ?? null;
            if ($id === null || $id === '') {
                continue;
            }

            // Contact's BelongsToCompany global scope means a foreign-company
            // id is indistinguishable from a nonexistent one here — both come
            // back null, and both are rejected as "not found".
            $contact = Contact::query()->find($id);

            if ($contact === null) {
                $validator->errors()->add("{$field}.{$index}.contact_id", 'The selected contact was not found.');

                continue;
            }

            if ($contact->is_archived) {
                $validator->errors()->add("{$field}.{$index}.contact_id", 'The selected contact is archived.');
            }
        }
    }

    private function validateDueDates(Validator $validator, string $field): void
    {
        foreach ($this->input($field, []) as $index => $line) {
            $original = $line['original_date'] ?? null;
            $due = $line['due_date'] ?? null;
            if (! $original || ! $due || ! is_string($original) || ! is_string($due)) {
                continue;
            }

            if (strtotime($due) !== false && strtotime($original) !== false && strtotime($due) < strtotime($original)) {
                $validator->errors()->add("{$field}.{$index}.due_date", 'Due date must be on or after the original date.');
            }
        }
    }
}
