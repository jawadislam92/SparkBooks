<?php

namespace App\Http\Requests;

use App\Enums\ContactType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateContactRequest extends FormRequest
{
    /**
     * Authorization is enforced by the `can:contacts.manage` route middleware
     * (runs before this request is validated), so this stays focused purely
     * on shape validation.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * `is_archived` is deliberately absent — archive status only ever changes
     * via the dedicated archive/unarchive actions, never through a plain
     * update payload.
     */
    public function rules(): array
    {
        return [
            'type' => ['required', Rule::enum(ContactType::class)],
            'name' => ['required', 'string', 'max:120'],
            'email' => ['nullable', 'email', 'max:255'],
            'phone' => ['nullable', 'string', 'max:40'],
            'billing_address' => ['nullable', 'string', 'max:2000'],
            'tax_number' => ['nullable', 'string', 'max:60'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ];
    }
}
