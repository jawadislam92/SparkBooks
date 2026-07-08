<?php

namespace Tests\Feature;

use App\Enums\AccountSubtype;
use App\Enums\AccountType;
use App\Enums\ContactType;
use App\Enums\Role;
use App\Models\Account;
use App\Models\BankAccount;
use App\Models\Company;
use App\Models\Contact;
use App\Models\User;
use App\Support\CompanyContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

/**
 * Task 10 — the Dashboard's Phase 2 "light" real-data pass: cash position +
 * customer/vendor counts (DashboardController), plus the `can` ability map
 * the sidebar nav (AppShell.tsx) reads to decide what to render.
 */
class DashboardTest extends TestCase
{
    use RefreshDatabase;

    private function actingAsRole(Company $company, Role $role): User
    {
        $user = User::factory()->create();
        $user->companies()->attach($company, ['role' => $role->value]);
        $user->forceFill(['current_company_id' => $company->id])->save();
        $this->actingAs($user);

        return $user;
    }

    /** Gives a bank account a real ledger balance via a manual journal entry. */
    private function depositInto(BankAccount $bankAccount, int $amountMinor): void
    {
        $incomeAccount = Account::factory()->create([
            'code' => (string) fake()->unique()->numerify('49##'),
            'type' => AccountType::Income->value,
            'subtype' => AccountSubtype::OperatingIncome->value,
        ]);

        $this->postJson('/journal-entries', [
            'entry_date' => '2026-07-01',
            'memo' => 'Deposit',
            'lines' => [
                ['account_id' => $bankAccount->ledger_account_id, 'debit' => $amountMinor, 'credit' => 0],
                ['account_id' => $incomeAccount->id, 'debit' => 0, 'credit' => $amountMinor],
            ],
        ], ['X-Idempotency-Key' => (string) Str::uuid()])->assertRedirect(route('journal-entries.index'));
    }

    // ---------------------------------------------------------------
    // Cash position + counts
    // ---------------------------------------------------------------

    public function test_dashboard_reports_cash_position_and_contact_counts_for_an_onboarded_user(): void
    {
        $company = Company::factory()->create(['base_currency' => 'PKR']);
        $this->actingAsRole($company, Role::Owner);
        app(CompanyContext::class)->set($company);

        $main = BankAccount::factory()->create(['name' => 'Main', 'currency_code' => 'PKR']);
        $petty = BankAccount::factory()->create(['name' => 'Petty cash', 'type' => 'cash', 'currency_code' => 'PKR']);
        // Archived accounts must not count toward cash position or the count.
        BankAccount::factory()->create(['name' => 'Closed', 'currency_code' => 'PKR', 'is_archived' => true]);

        $this->depositInto($main, 75000);
        $this->depositInto($petty, 5000);

        Contact::factory()->count(2)->create(['type' => ContactType::Customer->value]);
        Contact::factory()->create(['type' => ContactType::Vendor->value]);
        Contact::factory()->create(['type' => ContactType::Both->value]); // counts as both
        Contact::factory()->create(['type' => ContactType::Customer->value, 'is_archived' => true]); // excluded

        $this->get('/dashboard')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Dashboard')
                ->where('cash_position', 80000)
                ->where('customer_count', 3) // 2 customer + 1 both
                ->where('vendor_count', 2) // 1 vendor + 1 both
                ->where('bank_account_count', 2) // archived excluded
            );
    }

    public function test_dashboard_reports_zeros_for_a_freshly_onboarded_company(): void
    {
        $company = Company::factory()->create();
        $this->actingAsRole($company, Role::Owner);

        $this->get('/dashboard')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('cash_position', 0)
                ->where('customer_count', 0)
                ->where('vendor_count', 0)
                ->where('bank_account_count', 0)
            );
    }

    public function test_dashboard_figures_are_tenant_scoped(): void
    {
        $companyA = Company::factory()->create(['base_currency' => 'PKR']);
        app(CompanyContext::class)->set($companyA);
        $bankA = BankAccount::factory()->create(['currency_code' => 'PKR']);
        Contact::factory()->count(3)->create(['type' => ContactType::Customer->value]);

        $this->actingAsRole($companyA, Role::Owner);
        $this->depositInto($bankA, 12345);

        $companyB = Company::factory()->create(['base_currency' => 'PKR']);
        $this->actingAsRole($companyB, Role::Owner);

        // Company B has none of company A's bank accounts or contacts.
        $this->get('/dashboard')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('cash_position', 0)
                ->where('customer_count', 0)
                ->where('vendor_count', 0)
                ->where('bank_account_count', 0)
            );
    }

    // ---------------------------------------------------------------
    // `can` ability map — what the sidebar nav gates on
    // ---------------------------------------------------------------

    /**
     * NAV_ABILITIES keys are permission strings that themselves contain dots
     * (e.g. "contacts.manage"), so they can't be reached with Inertia's
     * dot-path `where('can.contacts.manage', …)` — that would look for a
     * *nested* can.contacts.manage, not a "can" map with a literal
     * "contacts.manage" key. Assert the whole map with a callback instead.
     */
    private function assertCan(AssertableInertia $page, array $expected): void
    {
        $page->where('can', function ($can) use ($expected) {
            $can = is_array($can) ? $can : $can->all();
            foreach ($expected as $ability => $value) {
                $this->assertSame(
                    $value,
                    $can[$ability] ?? null,
                    "can.{$ability} expected ".var_export($value, true)
                );
            }

            return true;
        });
    }

    public function test_owner_dashboard_shares_the_full_nav_ability_set(): void
    {
        $company = Company::factory()->create();
        $this->actingAsRole($company, Role::Owner);

        $this->get('/dashboard')
            ->assertOk()
            ->assertInertia(function (AssertableInertia $page) {
                $this->assertCan($page, [
                    'contacts.manage' => true,
                    'items.manage' => true,
                    'accounts.manage' => true,
                    'ledger.post_manual' => true,
                    'bank_accounts.manage' => true,
                    'reports.view' => true,
                    'tax_rates.manage' => true,
                    'periods.lock' => true,
                    'settings.manage' => true,
                    'audit.view' => true,
                ]);
            });
    }

    public function test_staff_dashboard_grants_none_of_the_abilities_it_lacks(): void
    {
        $company = Company::factory()->create();
        $this->actingAsRole($company, Role::Staff);

        // Staff (config/permissions.php) holds none of the nav-gating
        // abilities at all — every one of these must come back false.
        $this->get('/dashboard')
            ->assertOk()
            ->assertInertia(function (AssertableInertia $page) {
                $this->assertCan($page, [
                    'contacts.manage' => false,
                    'items.manage' => false,
                    'accounts.manage' => false,
                    'ledger.post_manual' => false,
                    'bank_accounts.manage' => false,
                    'reports.view' => false,
                    'tax_rates.manage' => false,
                    'periods.lock' => false,
                    'settings.manage' => false,
                    'audit.view' => false,
                ]);
            });
    }

    public function test_admin_dashboard_reflects_the_owner_accountant_ability_split(): void
    {
        // Admin (config/permissions.php) lacks ledger.post_manual, periods.lock,
        // and audit.view — the one gap PROJECT_MAP §10 flags as worth
        // documenting. The nav's `can` map must reflect that split faithfully.
        $company = Company::factory()->create();
        $this->actingAsRole($company, Role::Admin);

        $this->get('/dashboard')
            ->assertOk()
            ->assertInertia(function (AssertableInertia $page) {
                $this->assertCan($page, [
                    'contacts.manage' => true,
                    'accounts.manage' => true,
                    'reports.view' => true,
                    'settings.manage' => true,
                    'ledger.post_manual' => false,
                    'periods.lock' => false,
                    'audit.view' => false,
                ]);
            });
    }
}
