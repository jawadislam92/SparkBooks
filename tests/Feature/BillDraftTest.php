<?php

namespace Tests\Feature;

use App\Enums\AccountSubtype;
use App\Enums\AccountType;
use App\Enums\BillStatus;
use App\Enums\ContactType;
use App\Enums\Role;
use App\Models\Account;
use App\Models\ActivityLog;
use App\Models\Bill;
use App\Models\BillLine;
use App\Models\Company;
use App\Models\Contact;
use App\Models\TaxRate;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

class BillDraftTest extends TestCase
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

    private function vendor(Company $company, array $overrides = []): Contact
    {
        return Contact::factory()->create(array_merge([
            'company_id' => $company->id,
            'type' => ContactType::Vendor->value,
        ], $overrides));
    }

    private function categoryAccount(Company $company, array $overrides = []): Account
    {
        return Account::factory()->create(array_merge([
            'company_id' => $company->id,
            'type' => AccountType::Expense->value,
            'subtype' => AccountSubtype::OperatingExpense->value,
        ], $overrides));
    }

    private function taxRate(Company $company, array $overrides = []): TaxRate
    {
        return TaxRate::factory()->create(array_merge(['company_id' => $company->id], $overrides));
    }

    /** @param array<string, mixed> $overrides */
    private function line(array $overrides = []): array
    {
        return array_merge([
            'description' => 'Consulting services',
            'amount' => 10000,
            'tax_rate_id' => null,
        ], $overrides);
    }

    /** @param array<string, mixed> $data */
    private function postBill(array $data): TestResponse
    {
        return $this->post('/bills', $data);
    }

    /** @param array<string, mixed> $data */
    private function patchBill(Bill $bill, array $data): TestResponse
    {
        return $this->patch("/bills/{$bill->id}", $data);
    }

    // ---------------------------------------------------------------
    // Create — totals exact, vendor required
    // ---------------------------------------------------------------

    public function test_owner_can_create_a_draft_with_a_vendor_and_a_single_untaxed_line(): void
    {
        $company = Company::factory()->create();
        $this->actingAsRole($company, Role::Owner);
        $vendor = $this->vendor($company);
        $category = $this->categoryAccount($company);

        $response = $this->postBill([
            'contact_id' => $vendor->id,
            'reference' => 'INV-778',
            'bill_date' => '2026-07-01',
            'due_date' => '2026-07-15',
            'notes' => 'Net 14',
            'lines' => [
                $this->line(['category_account_id' => $category->id, 'amount' => 150000]),
            ],
        ]);

        $bill = Bill::query()->sole();
        $response->assertRedirect(route('bills.edit', $bill->id));

        $this->assertSame($vendor->id, $bill->contact_id);
        $this->assertSame('INV-778', $bill->reference);
        $this->assertSame(BillStatus::Draft, $bill->status);
        $this->assertSame(150000, $bill->subtotal);
        $this->assertSame(0, $bill->tax_total);
        $this->assertSame(150000, $bill->total);
        $this->assertSame(0, $bill->amount_paid);
        $this->assertSame(150000, $bill->balance);
        $this->assertFalse($bill->is_opening);

        $line = BillLine::query()->sole();
        $this->assertSame('Consulting services', $line->description);
        $this->assertSame(150000, $line->amount);
        $this->assertSame(0, $line->line_tax);
        $this->assertNull($line->tax_rate_snapshot);

        $audit = ActivityLog::query()->where('action', 'bill.draft_created')->sole();
        $this->assertSame($bill->id, $audit->subject_id);
        $this->assertSame(150000, $audit->after['total']);
    }

    public function test_a_both_type_contact_is_accepted_as_a_vendor(): void
    {
        $company = Company::factory()->create();
        $this->actingAsRole($company, Role::Owner);
        $both = $this->vendor($company, ['type' => ContactType::Both->value]);
        $category = $this->categoryAccount($company);

        $this->postBill([
            'contact_id' => $both->id,
            'lines' => [$this->line(['category_account_id' => $category->id])],
        ])->assertRedirect();

        $this->assertSame(1, Bill::query()->count());
    }

    public function test_a_customer_only_contact_is_rejected_as_a_vendor(): void
    {
        $company = Company::factory()->create();
        $this->actingAsRole($company, Role::Owner);
        $customerOnly = $this->vendor($company, ['type' => ContactType::Customer->value]);
        $category = $this->categoryAccount($company);

        $response = $this->postBill([
            'contact_id' => $customerOnly->id,
            'lines' => [$this->line(['category_account_id' => $category->id])],
        ]);

        $response->assertSessionHasErrors('contact_id');
        $this->assertSame(0, Bill::query()->count());
    }

    public function test_a_vendor_is_required(): void
    {
        $company = Company::factory()->create();
        $this->actingAsRole($company, Role::Owner);
        $category = $this->categoryAccount($company);

        $response = $this->postBill([
            'contact_id' => null,
            'lines' => [$this->line(['category_account_id' => $category->id])],
        ]);

        $response->assertSessionHasErrors('contact_id');
        $this->assertSame(0, Bill::query()->count());
    }

    public function test_an_archived_vendor_is_rejected(): void
    {
        $company = Company::factory()->create();
        $this->actingAsRole($company, Role::Owner);
        $vendor = $this->vendor($company, ['is_archived' => true]);
        $category = $this->categoryAccount($company);

        $response = $this->postBill([
            'contact_id' => $vendor->id,
            'lines' => [$this->line(['category_account_id' => $category->id])],
        ]);

        $response->assertSessionHasErrors('contact_id');
        $this->assertSame(0, Bill::query()->count());
    }

    public function test_a_foreign_vendor_is_rejected(): void
    {
        $companyA = Company::factory()->create();
        $foreignVendor = $this->vendor($companyA);

        $companyB = Company::factory()->create();
        $this->actingAsRole($companyB, Role::Owner);
        $category = $this->categoryAccount($companyB);

        $response = $this->postBill([
            'contact_id' => $foreignVendor->id,
            'lines' => [$this->line(['category_account_id' => $category->id])],
        ]);

        $response->assertSessionHasErrors('contact_id');
        $this->assertSame(0, Bill::query()->count());
    }

    /** The brief's own worked example, plus an odd-paisa line. */
    public function test_totals_are_computed_exactly_for_a_multi_line_mixed_taxed_and_untaxed_draft(): void
    {
        $company = Company::factory()->create();
        $this->actingAsRole($company, Role::Owner);
        $vendor = $this->vendor($company);
        $category = $this->categoryAccount($company);
        $eighteenPercent = $this->taxRate($company, ['rate' => '18.0000']);

        $response = $this->postBill([
            'contact_id' => $vendor->id,
            'lines' => [
                // 10,000 @ 18% -> 1,800 tax (the brief's own worked example).
                $this->line(['category_account_id' => $category->id, 'amount' => 10000, 'tax_rate_id' => $eighteenPercent->id]),
                // 9,999 (odd paisa) @ 18% -> 1799.82 half-up 1,800 tax.
                $this->line(['category_account_id' => $category->id, 'amount' => 9999, 'tax_rate_id' => $eighteenPercent->id]),
            ],
        ]);

        $bill = Bill::query()->sole();
        $response->assertRedirect(route('bills.edit', $bill->id));

        $this->assertSame(19999, $bill->subtotal);
        $this->assertSame(3600, $bill->tax_total);
        $this->assertSame(23599, $bill->total);
        $this->assertSame(23599, $bill->balance);
    }

    public function test_due_date_before_bill_date_is_rejected(): void
    {
        $company = Company::factory()->create();
        $this->actingAsRole($company, Role::Owner);
        $vendor = $this->vendor($company);
        $category = $this->categoryAccount($company);

        $response = $this->postBill([
            'contact_id' => $vendor->id,
            'bill_date' => '2026-07-15',
            'due_date' => '2026-07-01',
            'lines' => [$this->line(['category_account_id' => $category->id])],
        ]);

        $response->assertSessionHasErrors('due_date');
        $this->assertSame(0, Bill::query()->count());
    }

    public function test_bill_date_and_due_date_are_nullable_at_draft(): void
    {
        $company = Company::factory()->create();
        $this->actingAsRole($company, Role::Owner);
        $vendor = $this->vendor($company);
        $category = $this->categoryAccount($company);

        $this->postBill([
            'contact_id' => $vendor->id,
            'lines' => [$this->line(['category_account_id' => $category->id])],
        ])->assertRedirect();

        $bill = Bill::query()->sole();
        $this->assertNull($bill->bill_date);
        $this->assertNull($bill->due_date);
    }

    // ---------------------------------------------------------------
    // Category eligibility — representative HTTP-level checks (the full
    // matrix is CategoryAccountGuardTest's own responsibility)
    // ---------------------------------------------------------------

    public function test_a_control_account_line_is_rejected(): void
    {
        $company = Company::factory()->create();
        $this->actingAsRole($company, Role::Owner);
        $vendor = $this->vendor($company);
        $payable = $this->categoryAccount($company, [
            'code' => '2000',
            'type' => AccountType::Liability->value,
            'subtype' => AccountSubtype::Payable->value,
        ]);

        $response = $this->postBill([
            'contact_id' => $vendor->id,
            'lines' => [$this->line(['category_account_id' => $payable->id])],
        ]);

        $response->assertSessionHasErrors('lines.0.category_account_id');
        $this->assertSame(0, Bill::query()->count());
    }

    public function test_a_bank_subtype_account_line_is_rejected(): void
    {
        $company = Company::factory()->create();
        $this->actingAsRole($company, Role::Owner);
        $vendor = $this->vendor($company);
        $bank = $this->categoryAccount($company, [
            'code' => '1010',
            'type' => AccountType::Asset->value,
            'subtype' => AccountSubtype::Bank->value,
        ]);

        $response = $this->postBill([
            'contact_id' => $vendor->id,
            'lines' => [$this->line(['category_account_id' => $bank->id])],
        ]);

        $response->assertSessionHasErrors('lines.0.category_account_id');
        $this->assertSame(0, Bill::query()->count());
    }

    /** P15: the fixed-asset enabler — 1500 Office Equipment. */
    public function test_a_fixed_asset_category_account_line_is_allowed(): void
    {
        $company = Company::factory()->create();
        $this->actingAsRole($company, Role::Owner);
        $vendor = $this->vendor($company);
        $officeEquipment = $this->categoryAccount($company, [
            'code' => '1500',
            'type' => AccountType::Asset->value,
            'subtype' => AccountSubtype::FixedAsset->value,
        ]);

        $this->postBill([
            'contact_id' => $vendor->id,
            'lines' => [$this->line(['category_account_id' => $officeEquipment->id])],
        ])->assertRedirect();

        $this->assertSame(1, Bill::query()->count());
    }

    /** P12: the tax-remittance enabler — 2100 Tax Payable, a NON-control liability. */
    public function test_the_tax_payable_liability_category_account_line_is_allowed(): void
    {
        $company = Company::factory()->create();
        $this->actingAsRole($company, Role::Owner);
        $vendor = $this->vendor($company);
        $taxPayable = $this->categoryAccount($company, [
            'code' => '2100',
            'type' => AccountType::Liability->value,
            'subtype' => AccountSubtype::Tax->value,
        ]);

        $this->postBill([
            'contact_id' => $vendor->id,
            'lines' => [$this->line(['category_account_id' => $taxPayable->id])],
        ])->assertRedirect();

        $this->assertSame(1, Bill::query()->count());
    }

    /** P13: the owner-drawings enabler — 3900 Owner Drawings, equity. */
    public function test_the_owner_drawings_equity_category_account_line_is_allowed(): void
    {
        $company = Company::factory()->create();
        $this->actingAsRole($company, Role::Owner);
        $vendor = $this->vendor($company);
        $drawings = $this->categoryAccount($company, [
            'code' => '3900',
            'type' => AccountType::Equity->value,
            'subtype' => AccountSubtype::Equity->value,
        ]);

        $this->postBill([
            'contact_id' => $vendor->id,
            'lines' => [$this->line(['category_account_id' => $drawings->id])],
        ])->assertRedirect();

        $this->assertSame(1, Bill::query()->count());
    }

    // ---------------------------------------------------------------
    // Validation: reference, tax rate, amount, lines
    // ---------------------------------------------------------------

    public function test_an_archived_tax_rate_is_rejected(): void
    {
        $company = Company::factory()->create();
        $this->actingAsRole($company, Role::Owner);
        $vendor = $this->vendor($company);
        $category = $this->categoryAccount($company);
        $archivedRate = $this->taxRate($company, ['is_archived' => true]);

        $response = $this->postBill([
            'contact_id' => $vendor->id,
            'lines' => [$this->line(['category_account_id' => $category->id, 'tax_rate_id' => $archivedRate->id])],
        ]);

        $response->assertSessionHasErrors('lines.0.tax_rate_id');
        $this->assertSame(0, Bill::query()->count());
    }

    public function test_a_zero_amount_line_is_rejected(): void
    {
        $company = Company::factory()->create();
        $this->actingAsRole($company, Role::Owner);
        $vendor = $this->vendor($company);
        $category = $this->categoryAccount($company);

        $response = $this->postBill([
            'contact_id' => $vendor->id,
            'lines' => [$this->line(['category_account_id' => $category->id, 'amount' => 0])],
        ]);

        $response->assertSessionHasErrors('lines.0.amount');
        $this->assertSame(0, Bill::query()->count());
    }

    public function test_at_least_one_line_is_required(): void
    {
        $company = Company::factory()->create();
        $this->actingAsRole($company, Role::Owner);
        $vendor = $this->vendor($company);

        $response = $this->postBill([
            'contact_id' => $vendor->id,
            'lines' => [],
        ]);

        $response->assertSessionHasErrors('lines');
        $this->assertSame(0, Bill::query()->count());
    }

    public function test_reference_is_optional_and_not_unique(): void
    {
        $company = Company::factory()->create();
        $this->actingAsRole($company, Role::Owner);
        $vendor = $this->vendor($company);
        $category = $this->categoryAccount($company);

        foreach ([1, 2] as $_) {
            $this->postBill([
                'contact_id' => $vendor->id,
                'reference' => 'DUP-1',
                'lines' => [$this->line(['category_account_id' => $category->id])],
            ])->assertRedirect();
        }

        $this->assertSame(2, Bill::query()->where('reference', 'DUP-1')->count());
    }

    // ---------------------------------------------------------------
    // Only status=draft is editable/deletable
    // ---------------------------------------------------------------

    public function test_a_draft_can_be_updated_and_lines_are_replaced_wholesale(): void
    {
        $company = Company::factory()->create();
        $this->actingAsRole($company, Role::Owner);
        $vendor = $this->vendor($company);
        $category = $this->categoryAccount($company);

        $this->postBill([
            'contact_id' => $vendor->id,
            'lines' => [$this->line(['category_account_id' => $category->id, 'amount' => 10000])],
        ]);
        $bill = Bill::query()->sole();
        $originalLineId = BillLine::query()->sole()->id;

        $newVendor = $this->vendor($company);
        $response = $this->patchBill($bill, [
            'contact_id' => $newVendor->id,
            'reference' => 'REV-1',
            'lines' => [
                $this->line(['category_account_id' => $category->id, 'amount' => 20000, 'description' => 'Revised line']),
            ],
        ]);

        $response->assertRedirect(route('bills.edit', $bill->id));

        $bill->refresh();
        $this->assertSame($newVendor->id, $bill->contact_id);
        $this->assertSame('REV-1', $bill->reference);
        $this->assertSame(20000, $bill->total);
        $this->assertSame(20000, $bill->balance);

        $lines = BillLine::query()->get();
        $this->assertCount(1, $lines);
        $this->assertSame('Revised line', $lines->first()->description);
        $this->assertNotSame($originalLineId, $lines->first()->id);

        $audit = ActivityLog::query()->where('action', 'bill.draft_updated')->sole();
        $this->assertSame($bill->id, $audit->subject_id);
        $this->assertSame(20000, $audit->after['total']);
    }

    public function test_updating_a_received_bill_is_rejected_and_nothing_changes(): void
    {
        $company = Company::factory()->create();
        $this->actingAsRole($company, Role::Owner);
        $vendor = $this->vendor($company);
        $category = $this->categoryAccount($company);

        $bill = Bill::factory()->create([
            'company_id' => $company->id,
            'contact_id' => $vendor->id,
            'status' => BillStatus::Received->value,
            'total' => 50000,
        ]);

        $response = $this->patchBill($bill, [
            'contact_id' => $vendor->id,
            'lines' => [$this->line(['category_account_id' => $category->id])],
        ]);

        $response->assertSessionHasErrors('status');
        $this->assertSame(50000, $bill->fresh()->total);
    }

    public function test_a_draft_can_be_deleted(): void
    {
        $company = Company::factory()->create();
        $this->actingAsRole($company, Role::Owner);
        $vendor = $this->vendor($company);
        $category = $this->categoryAccount($company);

        $this->postBill([
            'contact_id' => $vendor->id,
            'lines' => [$this->line(['category_account_id' => $category->id])],
        ]);
        $bill = Bill::query()->sole();

        $response = $this->delete("/bills/{$bill->id}");

        $response->assertRedirect(route('bills.index'));
        $this->assertSame(0, Bill::query()->count());
        $this->assertSame(0, BillLine::query()->count());

        $audit = ActivityLog::query()->where('action', 'bill.draft_deleted')->sole();
        $this->assertSame($bill->id, $audit->subject_id);
    }

    public function test_deleting_a_received_bill_is_rejected(): void
    {
        $company = Company::factory()->create();
        $this->actingAsRole($company, Role::Owner);
        $vendor = $this->vendor($company);

        $bill = Bill::factory()->create([
            'company_id' => $company->id,
            'contact_id' => $vendor->id,
            'status' => BillStatus::Received->value,
        ]);

        $response = $this->delete("/bills/{$bill->id}");

        $response->assertSessionHasErrors('status');
        $this->assertSame(1, Bill::query()->count());
    }

    // ---------------------------------------------------------------
    // Gate: bills.draft — all four roles
    // ---------------------------------------------------------------

    public function test_all_four_roles_can_create_a_draft(): void
    {
        foreach ([Role::Owner, Role::Admin, Role::Accountant, Role::Staff] as $role) {
            $company = Company::factory()->create();
            $this->actingAsRole($company, $role);
            $vendor = $this->vendor($company);
            $category = $this->categoryAccount($company);

            $this->postBill([
                'contact_id' => $vendor->id,
                'lines' => [$this->line(['category_account_id' => $category->id])],
            ])->assertRedirect();
        }
    }

    // ---------------------------------------------------------------
    // Scoped 404
    // ---------------------------------------------------------------

    public function test_a_foreign_bill_404s_on_edit_update_and_destroy(): void
    {
        $companyA = Company::factory()->create();
        $vendorA = $this->vendor($companyA);
        $foreignBill = Bill::factory()->create(['company_id' => $companyA->id, 'contact_id' => $vendorA->id]);

        $companyB = Company::factory()->create();
        $this->actingAsRole($companyB, Role::Owner);
        $vendorB = $this->vendor($companyB);
        $category = $this->categoryAccount($companyB);

        $this->get("/bills/{$foreignBill->id}/edit")->assertNotFound();
        $this->patch("/bills/{$foreignBill->id}", [
            'contact_id' => $vendorB->id,
            'lines' => [$this->line(['category_account_id' => $category->id])],
        ])->assertNotFound();
        $this->delete("/bills/{$foreignBill->id}")->assertNotFound();
    }

    // ---------------------------------------------------------------
    // index / create form options
    // ---------------------------------------------------------------

    public function test_index_lists_drafts_and_received_bills(): void
    {
        $company = Company::factory()->create();
        $this->actingAsRole($company, Role::Owner);
        $vendor = $this->vendor($company);

        $draft = Bill::factory()->create(['company_id' => $company->id, 'contact_id' => $vendor->id, 'status' => BillStatus::Draft->value]);
        $received = Bill::factory()->create(['company_id' => $company->id, 'contact_id' => $vendor->id, 'status' => BillStatus::Received->value]);

        $response = $this->get('/bills');

        $response->assertOk();
        $response->assertInertia(function (AssertableInertia $page) use ($draft, $received) {
            $page->component('Bills/Index');
            $page->where('bills', function ($bills) use ($draft, $received) {
                $ids = collect($bills)->pluck('id')->all();

                return in_array($draft->id, $ids, true) && in_array($received->id, $ids, true);
            });
        });
    }

    public function test_create_form_only_lists_vendor_contacts_and_eligible_category_accounts(): void
    {
        $company = Company::factory()->create();
        $this->actingAsRole($company, Role::Owner);
        $activeVendor = $this->vendor($company, ['name' => 'Active Vendor']);
        $archivedVendor = $this->vendor($company, ['name' => 'Archived Vendor', 'is_archived' => true]);
        $customerOnly = $this->vendor($company, ['name' => 'Customer Only', 'type' => ContactType::Customer->value]);
        $eligible = $this->categoryAccount($company);
        $ineligible = $this->categoryAccount($company, [
            'code' => '1010',
            'type' => AccountType::Asset->value,
            'subtype' => AccountSubtype::Bank->value,
        ]);

        $response = $this->get('/bills/new');

        $response->assertOk();
        $response->assertInertia(function (AssertableInertia $page) use ($activeVendor, $archivedVendor, $customerOnly, $eligible, $ineligible) {
            $page->component('Bills/Edit');
            $page->where('bill', null);
            $page->where('contacts', function ($contacts) use ($activeVendor, $archivedVendor, $customerOnly) {
                $ids = collect($contacts)->pluck('id')->all();

                return in_array($activeVendor->id, $ids, true)
                    && ! in_array($archivedVendor->id, $ids, true)
                    && ! in_array($customerOnly->id, $ids, true);
            });
            $page->where('categoryAccounts', function ($accounts) use ($eligible, $ineligible) {
                $ids = collect($accounts)->pluck('id')->all();

                return in_array($eligible->id, $ids, true) && ! in_array($ineligible->id, $ids, true);
            });
        });
    }
}
