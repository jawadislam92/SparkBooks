<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreTaxRateRequest extends FormRequest
{
    /**
     * Authorization is enforced by the `can:tax_rates.manage` route
     * middleware (runs before this request is validated), so this stays
     * focused purely on shape validation.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * `is_archived` is deliberately absent: a new tax rate is never created
     * pre-archived — the controller never reads it from the request. Archive
     * status only ever changes via the dedicated archive/unarchive actions.
     *
     * `rate` mirrors the tax_rates.rate DECIMAL(7,4) column: a percent value
     * between 0 and 100 with at most 4 fractional digits (`decimal:0,4`
     * accepts "18", "18.5", and "18.0000" alike, but rejects a 5th decimal
     * digit).
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:60'],
            'rate' => ['required', 'numeric', 'between:0,100', 'decimal:0,4'],
        ];
    }
}
