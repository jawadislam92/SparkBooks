<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class CompleteReconciliationRequest extends FormRequest
{
    /**
     * Authorization is enforced by the `can:reconciliation.manage` route
     * middleware (runs before this request is validated), so this stays
     * focused purely on shape validation.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * The idempotency key travels in a header, not the request body — lift
     * it into a virtual `idempotency_key` field so it participates in normal
     * validation (required + UUID format) alongside everything else. Mirrors
     * StoreTransferRequest/StorePaymentRequest.
     */
    protected function prepareForValidation(): void
    {
        $this->merge([
            'idempotency_key' => $this->header('X-Idempotency-Key'),
        ]);
    }

    /**
     * `statement_ending_balance` arrives as integer minor units already —
     * same convention as every other money field in this app. An empty
     * `ticked_journal_line_ids` array is legal (a statement with no
     * clearing activity this period still needs to reconcile to its opening
     * balance) — `present` (not `required`) so an explicit empty array
     * passes but the key itself must exist.
     */
    public function rules(): array
    {
        return [
            'idempotency_key' => ['required', 'uuid'],
            'statement_date' => ['required', 'date'],
            'statement_ending_balance' => ['required', 'integer'],
            'ticked_journal_line_ids' => ['present', 'array'],
            'ticked_journal_line_ids.*' => ['integer'],
        ];
    }
}
