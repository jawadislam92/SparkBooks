<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;

class StoreManualJournalRequest extends FormRequest
{
    /**
     * Authorization is enforced by the `can:ledger.post_manual` route
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

    public function rules(): array
    {
        return [
            'idempotency_key' => ['required', 'uuid'],
            'entry_date' => ['required', 'date'],
            'memo' => ['required', 'string', 'max:500'],
            'lines' => ['required', 'array', 'min:2'],
            // account_id is NOT validated against accounts here on purpose:
            // LedgerService::post() already resolves it through the
            // tenant-scoped Account query (foreign/unknown ids 404 via
            // ModelNotFoundException), so a second exists-check here would
            // just duplicate that guard.
            'lines.*.account_id' => ['required', 'integer'],
            'lines.*.debit' => ['required', 'integer', 'min:0'],
            'lines.*.credit' => ['required', 'integer', 'min:0'],
            'lines.*.memo' => ['nullable', 'string', 'max:500'],
        ];
    }

    /**
     * Mirror LedgerService's "exactly one positive side per line" rule here
     * too, so a malformed line comes back as a clean, friendly 422 field
     * error instead of only surfacing once it reaches the ledger.
     */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            foreach ((array) $this->input('lines', []) as $i => $line) {
                if (! is_array($line) || ! array_key_exists('debit', $line) || ! array_key_exists('credit', $line)) {
                    continue;
                }
                $debit = (int) $line['debit'];
                $credit = (int) $line['credit'];
                if (($debit > 0) === ($credit > 0)) {
                    $validator->errors()->add(
                        "lines.{$i}.debit",
                        'Each line must have exactly one positive side — debit or credit, not both or neither.'
                    );
                }
            }
        });
    }
}
