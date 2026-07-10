<?php

namespace App\Http\Requests;

use App\Rules\AllowedAttachmentContent;
use Illuminate\Foundation\Http\FormRequest;

class StoreAttachmentRequest extends FormRequest
{
    /**
     * Authorization is enforced by the `can:expenses.draft`/`can:bills.draft`
     * route middleware (runs before this request is validated), so this
     * stays focused purely on shape validation — mirrors every other
     * FormRequest in this app.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * `max:10240` is KB (10MB) — the friendly, HTTP-path half of the spec
     * §17.8 size guard; AttachmentService::store re-checks the same limit in
     * bytes as the caller-independent backstop. AllowedAttachmentContent is
     * the content-sniff half (never trusts the extension or client-declared
     * mime) — see App\Support\AttachmentContentSniffer for the shared
     * allowlist both layers read from.
     */
    public function rules(): array
    {
        return [
            'file' => ['required', 'file', 'max:10240', new AllowedAttachmentContent],
        ];
    }

    public function messages(): array
    {
        return [
            'file.max' => 'The file must not be larger than 10MB.',
        ];
    }
}
