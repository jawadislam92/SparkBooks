<?php

namespace App\Http\Requests;

use App\Enums\AccountSubtype;
use App\Support\CompanyContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreAccountRequest extends FormRequest
{
    /**
     * Authorization is enforced by the `can:accounts.manage` route middleware
     * (runs before this request is validated), so this stays focused purely
     * on shape validation.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * `type` and `is_system` are deliberately absent from these rules: type is
     * always derived from `subtype` (AccountSubtype::type()) and `is_system`
     * is always false for a user-created account — the controller never reads
     * either from the request, so anything the client sends for them is
     * simply never honored (mirrors the standing rule to never accept
     * company_id from a request payload).
     */
    public function rules(CompanyContext $context): array
    {
        return [
            'code' => [
                'required',
                'string',
                'regex:/^\d{4}$/',
                Rule::unique('accounts', 'code')->where('company_id', $context->id()),
            ],
            'name' => ['required', 'string', 'max:120'],
            'subtype' => ['required', Rule::enum(AccountSubtype::class)],
            'description' => ['nullable', 'string', 'max:500'],
        ];
    }
}
