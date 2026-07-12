<?php

namespace App\Http\Requests;

use App\Enums\AccountSubtype;
use App\Enums\CashFlowClass;
use App\Models\Account;
use App\Support\CompanyContext;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateAccountRequest extends FormRequest
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
     * Resolved once and reused by rules()/withValidator(). The {account}
     * route parameter is a plain string, not an implicit Eloquent binding —
     * see AccountController's doc-comment for why (SubstituteBindings runs
     * before SetCompanyContext) — so this repeats the same tenant-scoped
     * lookup the controller itself performs. A foreign-company id throws
     * ModelNotFoundException here exactly as it would in the controller,
     * which Laravel turns into a 404 either way.
     */
    private function targetAccount(): Account
    {
        return Account::query()->findOrFail($this->route('account'));
    }

    /**
     * System accounts may only rename/redescribe (code/type/subtype/is_system
     * are rejected outright by withValidator() below, not merely dropped).
     * Non-system accounts may also change code and subtype (type is
     * re-derived from subtype, never accepted directly).
     */
    public function rules(CompanyContext $context): array
    {
        $account = $this->targetAccount();

        $rules = [
            'name' => ['required', 'string', 'max:120'],
            'description' => ['nullable', 'string', 'max:500'],
            // Phase 7 Task 8 (cf_class fix): the reclassification field —
            // allowed on ANY account, system or not (unlike code/subtype
            // below), since it's never structural the way those are. The
            // model-level guard that actually enforces "a Cash/Bank subtype
            // account can never leave cf_class=Cash" lives in
            // Account::booted()'s `saving` hook, not here — so whatever is
            // submitted for a cash/bank account is silently overwritten back
            // to Cash on save, rather than rejected with a validation error.
            'cf_class' => ['nullable', Rule::enum(CashFlowClass::class)],
        ];

        if (! $account->is_system) {
            $rules['code'] = [
                'required',
                'string',
                'regex:/^\d{4}$/',
                Rule::unique('accounts', 'code')->where('company_id', $context->id())->ignore($account->id),
            ];
            $rules['subtype'] = ['required', Rule::enum(AccountSubtype::class)];
        }

        return $rules;
    }

    /**
     * `type`, `is_system`, and `company_id` must never be accepted from a
     * request at all — reject with a clean 422 rather than silently dropping
     * them. For a system account, `code` and `subtype` are rejected the same
     * way (the Account model's own `updating` guard would throw a
     * LogicException if this ever slipped through, but failing validation
     * here gives a friendly field error instead of a 500).
     */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            foreach (['type', 'is_system', 'company_id'] as $field) {
                if ($this->has($field)) {
                    $validator->errors()->add($field, "The {$field} field cannot be set from a request.");
                }
            }

            $account = $this->targetAccount();
            if (! $account->is_system) {
                return;
            }

            foreach (['code', 'subtype'] as $field) {
                if ($this->has($field)) {
                    $validator->errors()->add($field, 'System accounts cannot change '.$field.'.');
                }
            }
        });
    }
}
