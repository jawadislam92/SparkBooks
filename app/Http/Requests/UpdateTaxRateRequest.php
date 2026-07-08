<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateTaxRateRequest extends FormRequest
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
     * `is_archived` is deliberately absent — archive status only ever
     * changes via the dedicated archive/unarchive actions, never through a
     * plain update payload. Editing `rate` here never touches historical
     * documents: the rate is copied onto document lines at creation time
     * (spec §7.6), so this only affects what future documents pick up.
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:60'],
            'rate' => ['required', 'numeric', 'between:0,100', 'decimal:0,4'],
        ];
    }
}
