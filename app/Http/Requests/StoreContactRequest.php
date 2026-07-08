<?php

namespace App\Http\Requests;

use App\Enums\ContactType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreContactRequest extends FormRequest
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
     * `is_archived` is deliberately absent: a new contact is never created
     * pre-archived — the controller never reads it from the request. Archive
     * status only ever changes via the dedicated archive/unarchive actions.
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
