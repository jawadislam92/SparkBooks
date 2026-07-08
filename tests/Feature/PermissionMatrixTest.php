<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\Company;
use App\Models\User;
use App\Support\CompanyContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
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

        // Catalog completeness guard: config('permissions.catalog') must
        // equal exactly the union of every permission granted across the
        // MATRIX role rows above — no more, no less. This fails loudly if a
        // permission is ever dropped from (or silently omitted from) the
        // catalog while still being granted to a role, or vice versa.
        $expectedCatalog = collect(self::MATRIX)->flatten()->unique()->sort()->values()->all();
        $actualCatalog = collect($catalog)->unique()->sort()->values()->all();
        $this->assertSame($expectedCatalog, $actualCatalog,
            'permissions.catalog must equal the union of all permissions granted across the MATRIX role rows.');

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

    public function test_every_gate_denies_when_no_company_context_is_set(): void
    {
        $company = Company::factory()->create();
        $user = User::factory()->create();
        // Owner is the most-privileged role — if even Owner is denied
        // everything here, weaker roles certainly are too. Deliberately do
        // NOT set CompanyContext.
        $user->companies()->attach($company, ['role' => Role::Owner->value]);
        $this->actingAs($user);

        $this->assertFalse(app(CompanyContext::class)->has());

        foreach (config('permissions.catalog') as $permission) {
            $this->assertFalse(Gate::forUser($user)->allows($permission),
                "permission={$permission} expected false with no company context set");
        }
    }

    public function test_role_in_is_memoized_per_company_and_does_not_leak_across_companies(): void
    {
        [$companyA, $companyB] = Company::factory()->count(2)->create();
        $user = User::factory()->create();
        $user->companies()->attach($companyA, ['role' => Role::Owner->value]);

        $this->assertSame(Role::Owner, $user->roleIn($companyA));

        // Second call for the SAME company must be served from the per-id
        // cache, not re-query company_user.
        DB::enableQueryLog();
        $this->assertSame(Role::Owner, $user->roleIn($companyA));
        $this->assertCount(0, DB::getQueryLog(), 'A second roleIn() call for an already-cached company id must not hit the database.');
        DB::flushQueryLog();
        DB::disableQueryLog();

        // Attaching a brand-new membership for a DIFFERENT company id must
        // resolve fresh (not share/pollute the cache keyed on company A's
        // id) and must not disturb company A's already-cached entry.
        $user->companies()->attach($companyB, ['role' => Role::Staff->value]);
        $this->assertSame(Role::Staff, $user->roleIn($companyB));
        $this->assertSame(Role::Owner, $user->roleIn($companyA));
    }

    public function test_current_role_resolves_via_company_context_or_null(): void
    {
        $company = Company::factory()->create();
        $user = User::factory()->create();
        $user->companies()->attach($company, ['role' => Role::Admin->value]);

        $this->assertNull($user->currentRole(), 'No company context set yet.');

        app(CompanyContext::class)->set($company);
        $this->assertSame(Role::Admin, $user->currentRole());
    }
}
