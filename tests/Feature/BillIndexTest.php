<?php

namespace Tests\Feature;

use App\Enums\AccountSubtype;
use App\Enums\AccountType;
use App\Enums\ContactType;
use App\Enums\Role;
use App\Models\Account;
use App\Models\Bill;
use App\Models\BillLine;
use App\Models\Company;
use App\Models\Contact;
use App\Models\User;
use App\Support\CompanyContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

/**
 * Phase 4 Task 9: Bills/Index's list-row enrichments — `notes`, `categories`
 * (mirrors ExpenseIndexTest exactly), and confirms `due_date`/`status` (both
 * already present pre-Task 9) are the fields the client derives its
 * "Overdue" filter/row-highlight from — no new server-computed flag, mirrors
 * how Invoices/Index has always computed overdue purely from those two
 * fields.
 */
class BillIndexTest extends TestCase
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

    /** @param array<string, mixed> $overrides */
    private function vendor(Company $company, array $overrides = []): Contact
    {
        return Contact::factory()->create(array_merge([
            'company_id' => $company->id,
            'type' => ContactType::Vendor->value,
        ], $overrides));
    }

    /** @param array<string, mixed> $overrides */
    private function categoryAccount(Company $company, array $overrides = []): Account
    {
        return Account::factory()->create(array_merge([
            'company_id' => $company->id,
            'type' => AccountType::Expense->value,
            'subtype' => AccountSubtype::OperatingExpense->value,
        ], $overrides));
    }

    public function test_index_includes_notes_and_distinct_category_names(): void
    {
        $company = Company::factory()->create();
        $this->actingAsRole($company, Role::Owner);
        $vendor = $this->vendor($company);
        $hosting = $this->categoryAccount($company, ['name' => 'Hosting']);
        $software = $this->categoryAccount($company, ['name' => 'Software']);

        $bill = Bill::factory()->create([
            'company_id' => $company->id,
            'contact_id' => $vendor->id,
            'notes' => 'Annual renewal',
        ]);

        app(CompanyContext::class)->runFor($company, function () use ($bill, $hosting, $software) {
            BillLine::factory()->create([
                'bill_id' => $bill->id,
                'company_id' => $bill->company_id,
                'category_account_id' => $hosting->id,
                'amount' => 50000,
            ]);
            BillLine::factory()->create([
                'bill_id' => $bill->id,
                'company_id' => $bill->company_id,
                'category_account_id' => $software->id,
                'amount' => 10000,
            ]);
        });

        $this->get('/bills')->assertInertia(fn (AssertableInertia $page) => $page
            ->component('Bills/Index')
            ->where('bills.0.notes', 'Annual renewal')
            ->where('bills.0.categories', ['Hosting', 'Software'])
        );
    }

    public function test_index_includes_due_date_and_status_for_a_still_payable_bill(): void
    {
        $company = Company::factory()->create();
        $this->actingAsRole($company, Role::Owner);
        $vendor = $this->vendor($company);

        Bill::factory()->create([
            'company_id' => $company->id,
            'contact_id' => $vendor->id,
            'status' => 'received',
            'due_date' => '2026-01-01',
            'total' => 50000,
            'balance' => 50000,
        ]);

        $this->get('/bills')->assertInertia(fn (AssertableInertia $page) => $page
            ->where('bills.0.due_date', '2026-01-01')
            ->where('bills.0.status', 'received')
            ->where('bills.0.balance', 50000)
        );
    }
}
