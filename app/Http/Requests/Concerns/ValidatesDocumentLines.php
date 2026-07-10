<?php

namespace App\Http\Requests\Concerns;

use App\Exceptions\IneligibleCategoryAccount;
use App\Models\Account;
use App\Models\TaxRate;
use App\Support\CategoryAccountGuard;
use Illuminate\Contracts\Validation\Validator;

/**
 * Shared amount-based line validation for Store/UpdateExpenseRequest and
 * Store/UpdateBillRequest (spec §7.8: expense/bill lines are AMOUNT-based —
 * category account + net amount + optional tax, no qty x price). Factored
 * out because all four FormRequests need the IDENTICAL per-line semantic
 * checks (description/category_account_id/tax_rate_id shape is already
 * covered by rules(); this only covers the "belongs to this tenant, correct
 * type, not archived" checks each gets its own field-level message for).
 *
 * The category-account check below is the FormRequest-layer half of the
 * brief's "dual-layer like OtherReceipt" requirement — it delegates to
 * App\Support\CategoryAccountGuard so the eligibility RULE itself is defined
 * exactly once; the other half (a caller-independent guard reachable even
 * without going through a FormRequest) is that same CategoryAccountGuard
 * called directly by ExpenseController/BillController when lines are
 * persisted — see CategoryAccountGuardTest for the guard's own coverage.
 */
trait ValidatesDocumentLines
{
    private function validateLines(Validator $validator): void
    {
        foreach ((array) $this->input('lines', []) as $index => $line) {
            if (! is_array($line)) {
                continue;
            }

            $this->validateCategoryAccount($validator, $index, $line);
            $this->validateTaxRate($validator, $index, $line);
        }
    }

    private function validateCategoryAccount(Validator $validator, int $index, array $line): void
    {
        $id = $line['category_account_id'] ?? null;
        if ($id === null || $id === '') {
            return;
        }

        // Account's BelongsToCompany global scope means a foreign-company id
        // is indistinguishable from a nonexistent one here — both come back
        // null, and both are rejected as "not found".
        $account = Account::query()->find($id);

        if ($account === null) {
            $validator->errors()->add("lines.{$index}.category_account_id", 'The selected category account was not found.');

            return;
        }

        try {
            CategoryAccountGuard::guard($account);
        } catch (IneligibleCategoryAccount $e) {
            $validator->errors()->add("lines.{$index}.category_account_id", $e->getMessage());
        }
    }

    private function validateTaxRate(Validator $validator, int $index, array $line): void
    {
        $id = $line['tax_rate_id'] ?? null;
        if ($id === null || $id === '') {
            return;
        }

        $taxRate = TaxRate::query()->find($id);

        if ($taxRate === null) {
            $validator->errors()->add("lines.{$index}.tax_rate_id", 'The selected tax rate was not found.');

            return;
        }

        if ($taxRate->is_archived) {
            $validator->errors()->add("lines.{$index}.tax_rate_id", 'The selected tax rate is archived.');
        }
    }
}
