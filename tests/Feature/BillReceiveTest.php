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
use App\Models\JournalEntry;
use App\Models\TaxRate;
use App\Models\User;
use App\Support\CompanyContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\Support\AssertsLedgerBalanced;
use Tests\TestCase;

class BillReceiveTest extends TestCase
{
    use AssertsLedgerBalanced;
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

    private function taxPayableAccount(Company $company): Account
    {
        return Account::factory()->create([
            'company_id' => $company->id,
            'code' => '2100',
            'name' => 'Tax Payable',
            'type' => AccountType::Liability->value,
            'subtype' => AccountSubtype::Tax->value,
            'is_system' => true,
        ]);
    }

    private function apAccount(Company $company): Account
    {
        return Account::factory()->create([
            'company_id' => $company->id,
            'code' => '2000',
            'name' => 'Accounts Payable',
            'type' => AccountType::Liability->value,
            'subtype' => AccountSubtype::Payable->value,
            'is_system' => true,
        ]);
    }

    /** @param array<string, mixed> $overrides */
    private function taxRate(Company $company, array $overrides = []): TaxRate
    {
        return TaxRate::factory()->create(array_merge(['company_id' => $company->id], $overrides));
    }

    /** @param array<string, mixed> $overrides */
    private function draftBill(Company $company, Contact $contact, array $overrides = []): Bill
    {
        return Bill::factory()->create(array_merge([
            'company_id' => $company->id,
            'contact_id' => $contact->id,
            'status' => BillStatus::Draft->value,
            'due_date' => '2026-08-01',
        ], $overrides));
    }

    /**
     * Wrapped in CompanyContext::runFor: BillLine::booted()'s freeze guard
     * re-queries `Bill::query()->find($line->bill_id)` on every create, which
     * needs a live CompanyContext — mirrors ExpenseRecordTest::expenseLine /
     * InvoiceSendTest::invoiceLine.
     *
     * @param  array<string, mixed>  $overrides
     */
    private function billLine(Bill $bill, Account $categoryAccount, array $overrides = []): BillLine
    {
        $company = Company::query()->findOrFail($bill->company_id);

        return app(CompanyContext::class)->runFor($company, fn () => BillLine::factory()->create(array_merge([
            'bill_id' => $bill->id,
            'company_id' => $bill->company_id,
            'category_account_id' => $categoryAccount->id,
        ], $overrides)));
    }

    private function receiveBill(Bill $bill, ?string $key = null): TestResponse
    {
        return $this->postJson("/bills/{$bill->id}/receive", [], [
            'X-Idempotency-Key' => $key ?? (string) Str::uuid(),
        ]);
    }

    // ---------------------------------------------------------------
    // P6: untaxed — Dr category / Cr AP (gross, with contact_id)
    // ---------------------------------------------------------------

    public function test_receive_posts_p6_exactly_for_an_untaxed_bill(): void
    {
        $company = Company::factory()->create();
        $this->actingAsRole($company, Role::Owner);
        $vendor = $this->vendor($company);
        $ap = $this->apAccount($company);
        $category = $this->categoryAccount($company);

        $bill = $this->draftBill($company, $vendor, ['bill_date' => '2026-07-05']);
        $this->billLine($bill, $category, ['amount' => 30000, 'tax_rate_id' => null]);

        $response = $this->receiveBill($bill);
        $response->assertRedirect(route('bills.edit', $bill->id));

        $bill->refresh();
        $this->assertSame(BillStatus::Received, $bill->status);
        $this->assertNotNull($bill->received_at);
        $this->assertSame(30000, $bill->subtotal);
        $this->assertSame(0, $bill->tax_total);
        $this->assertSame(30000, $bill->total);
        $this->assertSame(0, $bill->amount_paid);
        $this->assertSame(30000, $bill->balance);

        $entry = JournalEntry::query()->where('source_type', 'bill')->where('source_id', $bill->id)->sole();
        $this->assertCount(2, $entry->lines);

        $categoryLine = $entry->lines->firstWhere('account_id', $category->id);
        $this->assertSame(30000, $categoryLine->debit);
        $this->assertSame(0, $categoryLine->credit);

        $apLine = $entry->lines->firstWhere('account_id', $ap->id);
        $this->assertSame(0, $apLine->debit);
        $this->assertSame(30000, $apLine->credit);
        $this->assertSame($vendor->id, $apLine->contact_id);

        $this->assertSame($entry->lines->sum('debit'), $entry->lines->sum('credit'));
        $this->assertLedgerBalances();

        $audit = ActivityLog::query()->where('action', 'bill.received')->sole();
        $this->assertSame($bill->id, $audit->subject_id);
        $this->assertSame($entry->id, $audit->after['entry_id']);
    }

    // ---------------------------------------------------------------
    // P6t: 10,000 net + 1,800 tax
    // ---------------------------------------------------------------

    public function test_receive_posts_p6t_exactly_for_a_taxed_bill(): void
    {
        $company = Company::factory()->create();
        $this->actingAsRole($company, Role::Owner);
        $vendor = $this->vendor($company);
        $ap = $this->apAccount($company);
        $taxPayable = $this->taxPayableAccount($company);
        $category = $this->categoryAccount($company);
        $eighteenPercent = $this->taxRate($company, ['rate' => '18.0000']);

        $bill = $this->draftBill($company, $vendor);
        $this->billLine($bill, $category, ['amount' => 10000, 'tax_rate_id' => $eighteenPercent->id]);

        $this->receiveBill($bill)->assertRedirect();

        $bill->refresh();
        $this->assertSame(10000, $bill->subtotal);
        $this->assertSame(1800, $bill->tax_total);
        $this->assertSame(11800, $bill->total);
        $this->assertSame(11800, $bill->balance);

        $entry = JournalEntry::query()->where('source_type', 'bill')->where('source_id', $bill->id)->sole();
        $this->assertCount(3, $entry->lines);

        $categoryLine = $entry->lines->firstWhere('account_id', $category->id);
        $this->assertSame(10000, $categoryLine->debit);
        $this->assertSame(0, $categoryLine->credit);

        $taxLine = $entry->lines->firstWhere('account_id', $taxPayable->id);
        $this->assertSame(1800, $taxLine->debit);
        $this->assertSame(0, $taxLine->credit);

        $apLine = $entry->lines->firstWhere('account_id', $ap->id);
        $this->assertSame(0, $apLine->debit);
        $this->assertSame(11800, $apLine->credit);
        $this->assertSame($vendor->id, $apLine->contact_id);

        $this->assertSame($entry->lines->sum('debit'), $entry->lines->sum('credit'));
        $this->assertLedgerBalances();
    }

    public function test_multi_line_mixed_taxed_and_untaxed_bill_posts_correctly(): void
    {
        $company = Company::factory()->create();
        $this->actingAsRole($company, Role::Owner);
        $vendor = $this->vendor($company);
        $ap = $this->apAccount($company);
        $this->taxPayableAccount($company);
        $categoryA = $this->categoryAccount($company, ['code' => '5000']);
        $categoryB = $this->categoryAccount($company, ['code' => '5010']);
        $eighteenPercent = $this->taxRate($company, ['rate' => '18.0000']);

        $bill = $this->draftBill($company, $vendor);
        $this->billLine($bill, $categoryA, ['amount' => 10000, 'tax_rate_id' => $eighteenPercent->id]);
        $this->billLine($bill, $categoryB, ['amount' => 5000, 'tax_rate_id' => null]);

        $this->receiveBill($bill)->assertRedirect();

        $bill->refresh();
        $this->assertSame(15000, $bill->subtotal);
        $this->assertSame(1800, $bill->tax_total);
        $this->assertSame(16800, $bill->total);

        $entry = JournalEntry::query()->where('source_type', 'bill')->where('source_id', $bill->id)->sole();
        $this->assertCount(4, $entry->lines);

        $apLine = $entry->lines->firstWhere('account_id', $ap->id);
        $this->assertSame(16800, $apLine->credit);
        $this->assertSame($vendor->id, $apLine->contact_id);

        $this->assertSame($entry->lines->sum('debit'), $entry->lines->sum('credit'));
        $this->assertLedgerBalances();
    }

    // ---------------------------------------------------------------
    // AP line ALWAYS carries contact_id (also a `both`-type vendor)
    // ---------------------------------------------------------------

    public function test_the_ap_line_carries_contact_id_even_for_a_both_type_contact(): void
    {
        $company = Company::factory()->create();
        $this->actingAsRole($company, Role::Owner);
        $both = $this->vendor($company, ['type' => ContactType::Both->value]);
        $ap = $this->apAccount($company);
        $category = $this->categoryAccount($company);

        $bill = $this->draftBill($company, $both);
        $this->billLine($bill, $category, ['amount' => 12000]);

        $this->receiveBill($bill)->assertRedirect();

        $entry = JournalEntry::query()->where('source_type', 'bill')->where('source_id', $bill->id)->sole();
        $apLine = $entry->lines->firstWhere('account_id', $ap->id);
        $this->assertSame($both->id, $apLine->contact_id);
    }

    // ---------------------------------------------------------------
    // $0-value lines: filtered from the ledger but stay on the document;
    // an all-$0 bill is rejected before anything posts.
    // ---------------------------------------------------------------

    public function test_a_zero_value_line_is_not_posted_but_stays_on_the_bill(): void
    {
        $company = Company::factory()->create();
        $this->actingAsRole($company, Role::Owner);
        $vendor = $this->vendor($company);
        $this->apAccount($company);
        $freeCategory = $this->categoryAccount($company, ['code' => '5000']);
        $paidCategory = $this->categoryAccount($company, ['code' => '5010']);

        $bill = $this->draftBill($company, $vendor);
        $freeLine = $this->billLine($bill, $freeCategory, ['amount' => 0, 'tax_rate_id' => null]);
        $this->billLine($bill, $paidCategory, ['amount' => 40000, 'tax_rate_id' => null]);

        $this->receiveBill($bill)->assertRedirect();

        $entry = JournalEntry::query()->where('source_type', 'bill')->where('source_id', $bill->id)->sole();
        // ONLY the paid category + AP — the $0 line produces NO line.
        $this->assertCount(2, $entry->lines);
        $this->assertNull($entry->lines->firstWhere('account_id', $freeCategory->id));
        $this->assertLedgerBalances();

        $bill->refresh();
        $this->assertSame(2, $bill->lines()->count());
        $freeLine->refresh();
        $this->assertSame(0, $freeLine->amount);
    }

    public function test_an_all_zero_bill_with_no_value_is_rejected_and_posts_nothing(): void
    {
        $company = Company::factory()->create();
        $this->actingAsRole($company, Role::Owner);
        $vendor = $this->vendor($company);
        $this->apAccount($company);
        $category = $this->categoryAccount($company);

        $bill = $this->draftBill($company, $vendor);
        $this->billLine($bill, $category, ['amount' => 0, 'tax_rate_id' => null]);

        $response = $this->receiveBill($bill);

        $response->assertSessionHasErrors('status');
        $this->assertSame(0, JournalEntry::query()->where('source_type', 'bill')->count());

        $bill->refresh();
        $this->assertSame(BillStatus::Draft, $bill->status);
        $this->assertLedgerBalances();
    }

    // ---------------------------------------------------------------
    // due_date required
    // ---------------------------------------------------------------

    public function test_receiving_without_a_due_date_returns_422_and_posts_nothing(): void
    {
        $company = Company::factory()->create();
        $this->actingAsRole($company, Role::Owner);
        $vendor = $this->vendor($company);
        $this->apAccount($company);
        $category = $this->categoryAccount($company);

        $bill = $this->draftBill($company, $vendor, ['due_date' => null]);
        $this->billLine($bill, $category, ['amount' => 10000]);

        $response = $this->receiveBill($bill);

        $response->assertSessionHasErrors('status');
        $this->assertSame(0, JournalEntry::query()->where('source_type', 'bill')->count());
        $this->assertSame(BillStatus::Draft, $bill->fresh()->status);
    }

    // ---------------------------------------------------------------
    // bill_date defaults to today when not already set
    // ---------------------------------------------------------------

    public function test_bill_date_defaults_to_today_when_not_already_set(): void
    {
        $company = Company::factory()->create();
        $this->actingAsRole($company, Role::Owner);
        $vendor = $this->vendor($company);
        $this->apAccount($company);
        $category = $this->categoryAccount($company);

        $bill = $this->draftBill($company, $vendor, ['bill_date' => null]);
        $this->billLine($bill, $category, ['amount' => 10000]);

        $this->receiveBill($bill)->assertRedirect();

        $this->assertSame(now()->toDateString(), $bill->fresh()->bill_date->toDateString());
    }

    // ---------------------------------------------------------------
    // Vendor re-guard (defense in depth — StoreBillRequest already blocks
    // this at draft-write time; this proves a row that reaches receive() some
    // other way, e.g. the contact's type changed after the draft, is caught)
    // ---------------------------------------------------------------

    public function test_receiving_a_bill_whose_contact_is_customer_only_returns_422(): void
    {
        $company = Company::factory()->create();
        $this->actingAsRole($company, Role::Owner);
        $customerOnly = $this->vendor($company, ['type' => ContactType::Customer->value]);
        $this->apAccount($company);
        $category = $this->categoryAccount($company);

        $bill = $this->draftBill($company, $customerOnly);
        $this->billLine($bill, $category, ['amount' => 10000]);

        $response = $this->receiveBill($bill);

        $response->assertSessionHasErrors('status');
        $this->assertSame(0, JournalEntry::query()->where('source_type', 'bill')->count());
    }

    // ---------------------------------------------------------------
    // Locked period
    // ---------------------------------------------------------------

    public function test_receiving_into_a_locked_period_returns_422_and_posts_nothing(): void
    {
        $company = Company::factory()->create(['books_locked_through' => '2026-06-30']);
        $this->actingAsRole($company, Role::Owner);
        $vendor = $this->vendor($company);
        $this->apAccount($company);
        $category = $this->categoryAccount($company);

        $bill = $this->draftBill($company, $vendor, ['bill_date' => '2026-06-15']);
        $this->billLine($bill, $category, ['amount' => 10000]);

        $response = $this->receiveBill($bill);

        $response->assertSessionHasErrors('status');
        $this->assertSame(0, JournalEntry::query()->where('source_type', 'bill')->count());

        $bill->refresh();
        $this->assertSame(BillStatus::Draft, $bill->status);
        $this->assertLedgerBalances();
    }

    // ---------------------------------------------------------------
    // Idempotency
    // ---------------------------------------------------------------

    public function test_idempotent_double_receive_posts_exactly_one_entry(): void
    {
        $company = Company::factory()->create();
        $this->actingAsRole($company, Role::Owner);
        $vendor = $this->vendor($company);
        $this->apAccount($company);
        $category = $this->categoryAccount($company);
        $key = (string) Str::uuid();

        $bill = $this->draftBill($company, $vendor);
        $this->billLine($bill, $category, ['amount' => 10000]);

        $first = $this->receiveBill($bill, $key);
        $second = $this->receiveBill($bill, $key);

        $first->assertRedirect();
        $second->assertRedirect();

        $this->assertSame(1, JournalEntry::query()->where('source_type', 'bill')->count());
        $this->assertSame(1, ActivityLog::query()->where('action', 'bill.received')->count());
        $this->assertLedgerBalances();
    }

    // ---------------------------------------------------------------
    // Guards: draft-only, at least one line
    // ---------------------------------------------------------------

    public function test_receiving_a_non_draft_bill_returns_422_and_posts_nothing(): void
    {
        $company = Company::factory()->create();
        $this->actingAsRole($company, Role::Owner);
        $vendor = $this->vendor($company);

        $bill = $this->draftBill($company, $vendor, [
            'status' => BillStatus::Received->value,
            'received_at' => now(),
        ]);

        $response = $this->receiveBill($bill);

        $response->assertSessionHasErrors('status');
        $this->assertSame(0, JournalEntry::query()->where('source_type', 'bill')->count());
    }

    public function test_receiving_a_draft_with_no_lines_returns_422_and_posts_nothing(): void
    {
        $company = Company::factory()->create();
        $this->actingAsRole($company, Role::Owner);
        $vendor = $this->vendor($company);

        $bill = $this->draftBill($company, $vendor);

        $response = $this->receiveBill($bill);

        $response->assertSessionHasErrors('status');
        $this->assertSame(0, JournalEntry::query()->where('source_type', 'bill')->count());
    }

    // ---------------------------------------------------------------
    // Freeze: lines cannot change after receive
    // ---------------------------------------------------------------

    public function test_editing_a_line_after_receive_throws(): void
    {
        $company = Company::factory()->create();
        $this->actingAsRole($company, Role::Owner);
        $vendor = $this->vendor($company);
        $this->apAccount($company);
        $category = $this->categoryAccount($company);

        $bill = $this->draftBill($company, $vendor);
        $this->billLine($bill, $category, ['amount' => 10000]);

        $this->receiveBill($bill)->assertRedirect();

        $line = BillLine::query()->sole();

        $this->expectException(\LogicException::class);
        $line->update(['description' => 'Changed after receive']);
    }

    // ---------------------------------------------------------------
    // Gate: bills.receive — Owner/Admin/Accountant ok, Staff forbidden
    // ---------------------------------------------------------------

    public function test_owner_admin_and_accountant_can_receive_a_bill(): void
    {
        foreach ([Role::Owner, Role::Admin, Role::Accountant] as $role) {
            $company = Company::factory()->create();
            $this->actingAsRole($company, $role);
            $vendor = $this->vendor($company);
            $this->apAccount($company);
            $category = $this->categoryAccount($company);

            $bill = $this->draftBill($company, $vendor);
            $this->billLine($bill, $category, ['amount' => 10000]);

            $this->receiveBill($bill)->assertRedirect();
        }
    }

    public function test_staff_is_forbidden_from_receiving_a_bill(): void
    {
        $company = Company::factory()->create();
        $this->actingAsRole($company, Role::Staff);
        $vendor = $this->vendor($company);
        $this->apAccount($company);
        $category = $this->categoryAccount($company);

        $bill = $this->draftBill($company, $vendor);
        $this->billLine($bill, $category, ['amount' => 10000]);

        $response = $this->receiveBill($bill);

        $response->assertForbidden();
        $this->assertSame(0, JournalEntry::query()->where('source_type', 'bill')->count());
    }

    // ---------------------------------------------------------------
    // Scoped 404
    // ---------------------------------------------------------------

    public function test_a_foreign_bill_404s_on_receive(): void
    {
        $companyA = Company::factory()->create();
        $vendorA = $this->vendor($companyA);
        $this->apAccount($companyA);
        $categoryA = $this->categoryAccount($companyA);
        $foreignBill = $this->draftBill($companyA, $vendorA);
        $this->billLine($foreignBill, $categoryA, ['amount' => 10000]);

        $companyB = Company::factory()->create();
        $this->actingAsRole($companyB, Role::Owner);

        $this->receiveBill($foreignBill)->assertNotFound();
    }

    // ---------------------------------------------------------------
    // Idempotency key required
    // ---------------------------------------------------------------

    public function test_a_missing_idempotency_key_returns_422_and_posts_nothing(): void
    {
        $company = Company::factory()->create();
        $this->actingAsRole($company, Role::Owner);
        $vendor = $this->vendor($company);
        $this->apAccount($company);
        $category = $this->categoryAccount($company);

        $bill = $this->draftBill($company, $vendor);
        $this->billLine($bill, $category, ['amount' => 10000]);

        $response = $this->postJson("/bills/{$bill->id}/receive");

        $response->assertSessionHasErrors('idempotency_key');
        $this->assertSame(0, JournalEntry::query()->where('source_type', 'bill')->count());
    }
}
