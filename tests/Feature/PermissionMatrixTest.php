<?php
namespace Tests\Feature;

use App\Enums\Role;
use App\Models\Company;
use App\Models\User;
use App\Support\CompanyContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Tests\TestCase;

class PermissionMatrixTest extends TestCase
{
    use RefreshDatabase;

    /** Spec §7.14 — role => permissions it holds. */
    private const MATRIX = [
        'owner' => ['invoices.draft', 'invoices.send', 'invoices.void', 'payments.record', 'expenses.draft', 'bills.draft', 'expenses.record', 'bills.receive', 'expenses.void', 'bills.void', 'transfers.create', 'other_receipts.create', 'contacts.manage', 'items.manage', 'tax_rates.manage', 'accounts.manage', 'bank_accounts.manage', 'reconciliation.manage', 'ledger.post_manual', 'periods.lock', 'reports.view', 'audit.view', 'exports.run', 'settings.manage', 'users.manage', 'company.billing', 'company.delete'],
        'admin' => ['invoices.draft', 'invoices.send', 'invoices.void', 'payments.record', 'expenses.draft', 'bills.draft', 'expenses.record', 'bills.receive', 'expenses.void', 'bills.void', 'transfers.create', 'other_receipts.create', 'contacts.manage', 'items.manage', 'tax_rates.manage', 'accounts.manage', 'bank_accounts.manage', 'reports.view', 'exports.run', 'settings.manage', 'users.manage'],
        'accountant' => ['invoices.draft', 'invoices.send', 'invoices.void', 'payments.record', 'expenses.draft', 'bills.draft', 'expenses.record', 'bills.receive', 'expenses.void', 'bills.void', 'transfers.create', 'other_receipts.create', 'contacts.manage', 'items.manage', 'tax_rates.manage', 'accounts.manage', 'bank_accounts.manage', 'reconciliation.manage', 'ledger.post_manual', 'periods.lock', 'reports.view', 'audit.view', 'exports.run'],
        'staff' => ['invoices.draft', 'expenses.draft', 'bills.draft'],
    ];

    public function test_every_matrix_cell_is_enforced(): void
    {
        $company = Company::factory()->create();
        $catalog = config('permissions.catalog');
        $this->assertNotEmpty($catalog);

        foreach (self::MATRIX as $role => $granted) {
            $user = User::factory()->create();
            $user->companies()->attach($company, ['role' => $role]);
            $this->actingAs($user);
            app(CompanyContext::class)->set($company);

            foreach ($catalog as $permission) {
                $expected = in_array($permission, $granted, true);
                $this->assertSame($expected, Gate::forUser($user)->allows($permission),
                    "role={$role} permission={$permission} expected ".var_export($expected, true));
            }
        }
    }

    public function test_non_member_is_denied_everything(): void
    {
        $company = Company::factory()->create();
        $outsider = User::factory()->create();
        $this->actingAs($outsider);
        app(CompanyContext::class)->set($company);

        foreach (config('permissions.catalog') as $permission) {
            $this->assertFalse(Gate::forUser($outsider)->allows($permission));
        }
    }
}
