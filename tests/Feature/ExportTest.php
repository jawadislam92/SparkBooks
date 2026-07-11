<?php

namespace Tests\Feature;

use App\Enums\AccountSubtype;
use App\Enums\AccountType;
use App\Enums\ContactType;
use App\Enums\Role;
use App\Models\Account;
use App\Models\ActivityLog;
use App\Models\BankAccount;
use App\Models\Bill;
use App\Models\Company;
use App\Models\Contact;
use App\Models\Expense;
use App\Models\Invoice;
use App\Models\Item;
use App\Models\User;
use App\Support\CompanyContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Phase 6 Task 3 (spec §11/§17.8): the seven new list-screen CSV exports
 * (invoices/bills/expenses/contacts/items/bank-accounts/journal-entries) plus
 * the cross-cutting export-access-logging completeness gate covering BOTH
 * these new endpoints AND the seven Phase 5 report exports (which already
 * log — see Reports/*Controller::exportCsv — this test proves it holds for
 * every export endpoint in the app, not just the new ones). The Phase 5
 * report suites (tests/Feature/Reports/*Test.php) already cover each
 * report's own CSV-shape regression + audit logging in isolation; this file
 * doesn't duplicate that per-report detail, it re-hits them ONLY as rows in
 * the completeness table below.
 */
class ExportTest extends TestCase
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
    private function customer(Company $company, array $overrides = []): Contact
    {
        return Contact::factory()->create(array_merge([
            'company_id' => $company->id,
            'type' => ContactType::Customer->value,
        ], $overrides));
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
    private function incomeAccount(Company $company, array $overrides = []): Account
    {
        return Account::factory()->create(array_merge([
            'company_id' => $company->id,
            'type' => AccountType::Income->value,
            'subtype' => AccountSubtype::OperatingIncome->value,
        ], $overrides));
    }

    private function bankAccount(Company $company, array $ledgerOverrides = []): Account
    {
        return Account::factory()->create(array_merge([
            'company_id' => $company->id,
            'type' => AccountType::Asset->value,
            'subtype' => AccountSubtype::Bank->value,
        ], $ledgerOverrides));
    }

    /** Parses a streamed CSV response body into rows (each a list of string cells). */
    private function csvRows(string $csv): array
    {
        $lines = array_values(array_filter(preg_split('/\r\n|\r|\n/', $csv), fn (string $l) => $l !== ''));

        return array_map('str_getcsv', $lines);
    }

    // ---------------------------------------------------------------
    // Invoices
    // ---------------------------------------------------------------

    public function test_invoices_export_is_scoped_and_money_formatted(): void
    {
        $companyA = Company::factory()->create();
        $this->actingAsRole($companyA, Role::Owner);
        $customerA = $this->customer($companyA);
        Invoice::factory()->create([
            'company_id' => $companyA->id,
            'contact_id' => $customerA->id,
            'number' => 'INV-A1',
            'status' => 'sent',
            'total' => 150050,
            'balance' => 150050,
            'currency_code' => 'PKR',
        ]);

        $companyB = Company::factory()->create();
        $customerB = $this->customer($companyB);
        Invoice::factory()->create([
            'company_id' => $companyB->id,
            'contact_id' => $customerB->id,
            'number' => 'INV-B1',
            'status' => 'sent',
            'total' => 999900,
            'balance' => 999900,
            'currency_code' => 'PKR',
        ]);

        $response = $this->get('/invoices/export.csv');
        $response->assertOk();

        $rows = $this->csvRows($response->streamedContent());
        $this->assertSame(['Number', 'Customer', 'Issue Date', 'Due Date', 'Currency', 'Status', 'Total', 'Balance'], $rows[0]);
        $this->assertCount(2, $rows); // header + 1 data row (companyB's invoice absent)
        $this->assertSame('INV-A1', $rows[1][0]);
        $this->assertSame('1500.50', $rows[1][6]);
        $this->assertSame('1500.50', $rows[1][7]);
        $this->assertStringNotContainsString('INV-B1', $response->streamedContent());
    }

    public function test_invoices_export_status_filter_matches_index(): void
    {
        $company = Company::factory()->create();
        $this->actingAsRole($company, Role::Owner);
        $customer = $this->customer($company);

        Invoice::factory()->create(['company_id' => $company->id, 'contact_id' => $customer->id, 'number' => 'INV-1', 'status' => 'paid']);
        Invoice::factory()->create(['company_id' => $company->id, 'contact_id' => $customer->id, 'number' => 'INV-2', 'status' => 'draft']);

        $response = $this->get('/invoices/export.csv?status=paid');
        $response->assertOk();

        $rows = $this->csvRows($response->streamedContent());
        $this->assertCount(2, $rows);
        $this->assertSame('INV-1', $rows[1][0]);
    }

    // ---------------------------------------------------------------
    // Bills
    // ---------------------------------------------------------------

    public function test_bills_export_is_scoped_and_money_formatted(): void
    {
        $companyA = Company::factory()->create();
        $this->actingAsRole($companyA, Role::Owner);
        $vendorA = $this->vendor($companyA);
        Bill::factory()->create([
            'company_id' => $companyA->id,
            'contact_id' => $vendorA->id,
            'reference' => 'BILL-A1',
            'status' => 'received',
            'total' => 3333,
            'balance' => 3333,
        ]);

        $companyB = Company::factory()->create();
        $vendorB = $this->vendor($companyB);
        Bill::factory()->create([
            'company_id' => $companyB->id,
            'contact_id' => $vendorB->id,
            'reference' => 'BILL-B1',
            'status' => 'received',
        ]);

        $response = $this->get('/bills/export.csv');
        $response->assertOk();

        $rows = $this->csvRows($response->streamedContent());
        $this->assertSame(['Reference', 'Vendor', 'Bill Date', 'Due Date', 'Status', 'Total', 'Balance'], $rows[0]);
        $this->assertCount(2, $rows);
        $this->assertSame('BILL-A1', $rows[1][0]);
        $this->assertSame('33.33', $rows[1][5]);
    }

    public function test_bills_export_status_filter(): void
    {
        $company = Company::factory()->create();
        $this->actingAsRole($company, Role::Owner);
        $vendor = $this->vendor($company);

        Bill::factory()->create(['company_id' => $company->id, 'contact_id' => $vendor->id, 'reference' => 'B-PAID', 'status' => 'paid']);
        Bill::factory()->create(['company_id' => $company->id, 'contact_id' => $vendor->id, 'reference' => 'B-DRAFT', 'status' => 'draft']);

        $response = $this->get('/bills/export.csv?status=paid');
        $rows = $this->csvRows($response->streamedContent());

        $this->assertCount(2, $rows);
        $this->assertSame('B-PAID', $rows[1][0]);
    }

    // ---------------------------------------------------------------
    // Expenses
    // ---------------------------------------------------------------

    public function test_expenses_export_is_scoped_and_money_formatted(): void
    {
        $companyA = Company::factory()->create();
        $this->actingAsRole($companyA, Role::Owner);
        $payeeA = $this->vendor($companyA);
        Expense::factory()->create([
            'company_id' => $companyA->id,
            'contact_id' => $payeeA->id,
            'status' => 'recorded',
            'total' => 500,
        ]);

        $companyB = Company::factory()->create();
        Expense::factory()->create(['company_id' => $companyB->id, 'status' => 'recorded', 'total' => 999]);

        $response = $this->get('/expenses/export.csv');
        $response->assertOk();

        $rows = $this->csvRows($response->streamedContent());
        $this->assertSame(['Payee', 'Date', 'Status', 'Total'], $rows[0]);
        $this->assertCount(2, $rows);
        $this->assertSame('5.00', $rows[1][3]);
    }

    public function test_expenses_export_status_filter(): void
    {
        $company = Company::factory()->create();
        $this->actingAsRole($company, Role::Owner);

        Expense::factory()->create(['company_id' => $company->id, 'status' => 'draft', 'total' => 100]);
        Expense::factory()->create(['company_id' => $company->id, 'status' => 'void', 'total' => 200]);

        $response = $this->get('/expenses/export.csv?status=void');
        $rows = $this->csvRows($response->streamedContent());

        $this->assertCount(2, $rows);
        $this->assertSame('void', $rows[1][2]);
    }

    // ---------------------------------------------------------------
    // Contacts
    // ---------------------------------------------------------------

    public function test_contacts_export_is_scoped(): void
    {
        $companyA = Company::factory()->create();
        $this->actingAsRole($companyA, Role::Owner);
        Contact::factory()->create(['company_id' => $companyA->id, 'name' => 'Alpha Traders', 'type' => ContactType::Customer->value]);

        $companyB = Company::factory()->create();
        Contact::factory()->create(['company_id' => $companyB->id, 'name' => 'Beta Traders', 'type' => ContactType::Customer->value]);

        $response = $this->get('/contacts/export.csv');
        $rows = $this->csvRows($response->streamedContent());

        $this->assertSame(['Name', 'Type', 'Email', 'Phone', 'Tax Number', 'Archived'], $rows[0]);
        $this->assertCount(2, $rows);
        $this->assertSame('Alpha Traders', $rows[1][0]);
    }

    /** type=customer must include "both" contacts too — mirrors ContactType::isCustomer()/Contacts/Index's own client-side filter semantics, NOT a bare type-column equality. */
    public function test_contacts_export_type_filter_includes_both_role_contacts(): void
    {
        $company = Company::factory()->create();
        $this->actingAsRole($company, Role::Owner);

        Contact::factory()->create(['company_id' => $company->id, 'name' => 'Customer Only', 'type' => ContactType::Customer->value]);
        Contact::factory()->create(['company_id' => $company->id, 'name' => 'Vendor Only', 'type' => ContactType::Vendor->value]);
        Contact::factory()->create(['company_id' => $company->id, 'name' => 'Both Roles', 'type' => ContactType::Both->value]);

        $response = $this->get('/contacts/export.csv?type=customer');
        $rows = $this->csvRows($response->streamedContent());
        $exportedNames = collect(array_slice($rows, 1))->map(fn (array $row) => $row[0])->all();

        $this->assertContains('Customer Only', $exportedNames);
        $this->assertContains('Both Roles', $exportedNames);
        $this->assertNotContains('Vendor Only', $exportedNames);
    }

    // ---------------------------------------------------------------
    // Items
    // ---------------------------------------------------------------

    public function test_items_export_is_scoped_and_money_formatted(): void
    {
        $companyA = Company::factory()->create();
        $this->actingAsRole($companyA, Role::Owner);
        Item::factory()->create(['company_id' => $companyA->id, 'name' => 'Widget', 'unit_price' => 123456]);

        $companyB = Company::factory()->create();
        Item::factory()->create(['company_id' => $companyB->id, 'name' => 'Gadget', 'unit_price' => 999]);

        $response = $this->get('/items/export.csv');
        $rows = $this->csvRows($response->streamedContent());

        $this->assertSame(['Name', 'Description', 'Default Price', 'Income Account', 'Tax Rate', 'Archived'], $rows[0]);
        $this->assertCount(2, $rows);
        $this->assertSame('Widget', $rows[1][0]);
        $this->assertSame('1234.56', $rows[1][2]);
    }

    // ---------------------------------------------------------------
    // Bank accounts (the "transfers/bank-transactions" list — see
    // BankAccountController::exportCsv's own docblock for why this is the
    // closest existing list screen to that brief item).
    // ---------------------------------------------------------------

    public function test_bank_accounts_export_is_scoped(): void
    {
        $companyA = Company::factory()->create();
        $this->actingAsRole($companyA, Role::Owner);
        // BankAccountFactory's ledger_account_id is a NESTED Account factory
        // with no explicit company_id override, so it relies on
        // CompanyContext being set at creation time (mirrors
        // BankAccountsTest::createBankAccount's own runFor wrapping).
        app(CompanyContext::class)->runFor($companyA, fn () => BankAccount::factory()->create(['company_id' => $companyA->id, 'name' => 'Main Account']));

        $companyB = Company::factory()->create();
        app(CompanyContext::class)->runFor($companyB, fn () => BankAccount::factory()->create(['company_id' => $companyB->id, 'name' => 'Other Account']));

        $response = $this->get('/bank-accounts/export.csv');
        $rows = $this->csvRows($response->streamedContent());

        $this->assertSame(['Name', 'Type', 'Currency', 'Balance', 'Archived'], $rows[0]);
        $this->assertCount(2, $rows);
        $this->assertSame('Main Account', $rows[1][0]);
        $this->assertSame('0.00', $rows[1][3]); // no ledger activity posted -> zero balance
    }

    // ---------------------------------------------------------------
    // Journal entries (manual journals)
    // ---------------------------------------------------------------

    private function postManualJournal(Account $debit, Account $credit, int $amount, string $date, string $memo = 'Test entry'): void
    {
        $response = $this->postJson('/journal-entries', [
            'entry_date' => $date,
            'memo' => $memo,
            'lines' => [
                ['account_id' => $debit->id, 'debit' => $amount, 'credit' => 0],
                ['account_id' => $credit->id, 'debit' => 0, 'credit' => $amount],
            ],
        ], ['X-Idempotency-Key' => (string) Str::uuid()]);

        $response->assertRedirect();
    }

    public function test_journal_entries_export_is_scoped_and_money_formatted(): void
    {
        $companyA = Company::factory()->create();
        $this->actingAsRole($companyA, Role::Owner);
        $bankA = $this->bankAccount($companyA);
        $incomeA = $this->incomeAccount($companyA);
        $this->postManualJournal($bankA, $incomeA, 250075, '2026-07-01', 'Company A entry');

        $companyB = Company::factory()->create();
        $this->actingAsRole($companyB, Role::Owner);
        $bankB = $this->bankAccount($companyB);
        $incomeB = $this->incomeAccount($companyB);
        $this->postManualJournal($bankB, $incomeB, 999999, '2026-07-01', 'Company B entry');

        // Switch back to company A's owner for the export request.
        $this->actingAsRole($companyA, Role::Owner);

        $response = $this->get('/journal-entries/export.csv');
        $response->assertOk();

        $rows = $this->csvRows($response->streamedContent());
        $this->assertSame(['Date', 'Memo', 'Status', 'Created By', 'Amount'], $rows[0]);
        $this->assertCount(2, $rows);
        $this->assertSame('Company A entry', $rows[1][1]);
        $this->assertSame('posted', $rows[1][2]);
        $this->assertSame('2500.75', $rows[1][4]);
    }

    public function test_journal_entries_export_date_range_filter(): void
    {
        $company = Company::factory()->create();
        $this->actingAsRole($company, Role::Owner);
        $bank = $this->bankAccount($company);
        $income = $this->incomeAccount($company);

        $this->postManualJournal($bank, $income, 1000, '2026-01-01', 'January entry');
        $this->postManualJournal($bank, $income, 2000, '2026-07-01', 'July entry');

        $response = $this->get('/journal-entries/export.csv?from=2026-06-01&to=2026-12-31');
        $rows = $this->csvRows($response->streamedContent());

        $this->assertCount(2, $rows);
        $this->assertSame('July entry', $rows[1][1]);
    }

    // ---------------------------------------------------------------
    // Gate: exports.run — Staff 403 on EVERY export endpoint (table-driven);
    // Owner/Admin/Accountant allowed (spot-checked, PermissionMatrixTest
    // already proves the full matrix at the Gate level).
    // ---------------------------------------------------------------

    /** @return list<string> every export endpoint in the app — the new list exports plus the Phase 5 report exports. */
    private function allExportUrls(): array
    {
        return [
            '/invoices/export.csv',
            '/bills/export.csv',
            '/expenses/export.csv',
            '/contacts/export.csv',
            '/items/export.csv',
            '/bank-accounts/export.csv',
            '/journal-entries/export.csv',
            '/reports/trial-balance/export.csv',
            '/reports/profit-and-loss/export.csv',
            '/reports/balance-sheet/export.csv',
            '/reports/ar-aging/export.csv',
            '/reports/ap-aging/export.csv',
            '/reports/general-ledger/export.csv',
            '/reports/tax-summary/export.csv',
        ];
    }

    public function test_staff_is_forbidden_from_every_export_endpoint(): void
    {
        $company = Company::factory()->create();
        $this->actingAsRole($company, Role::Staff);

        foreach ($this->allExportUrls() as $url) {
            $this->get($url)->assertForbidden();
        }
    }

    public function test_owner_admin_and_accountant_can_reach_every_export_endpoint(): void
    {
        foreach ([Role::Owner, Role::Admin, Role::Accountant] as $role) {
            $company = Company::factory()->create();
            $this->actingAsRole($company, $role);

            foreach ($this->allExportUrls() as $url) {
                // /journal-entries/export.csv sits behind ledger.post_manual
                // (config/permissions.php §7.14) — Owner/Accountant hold it,
                // Admin does NOT (same as journal-entries.index itself); this
                // isn't an exports.run gap, exports.run is satisfied by all
                // three roles here, it's the group's own base ability.
                if ($url === '/journal-entries/export.csv' && $role === Role::Admin) {
                    $this->get($url)->assertForbidden();

                    continue;
                }

                $this->get($url)->assertOk("role={$role->value} url={$url}");
            }
        }
    }

    // ---------------------------------------------------------------
    // THE completeness gate (§17.8): every export endpoint — new list
    // exports AND Phase 5 report exports — writes EXACTLY ONE '*.exported'
    // audit row per hit, with the actor recorded automatically.
    // ---------------------------------------------------------------

    /** @return list<array{url: string, action: string, report?: string}> */
    private function exportEndpointExpectations(): array
    {
        return [
            ['url' => '/invoices/export.csv', 'action' => 'invoices.exported'],
            ['url' => '/bills/export.csv', 'action' => 'bills.exported'],
            ['url' => '/expenses/export.csv', 'action' => 'expenses.exported'],
            ['url' => '/contacts/export.csv', 'action' => 'contacts.exported'],
            ['url' => '/items/export.csv', 'action' => 'items.exported'],
            ['url' => '/bank-accounts/export.csv', 'action' => 'bank_accounts.exported'],
            ['url' => '/journal-entries/export.csv', 'action' => 'journal_entries.exported'],
            ['url' => '/reports/trial-balance/export.csv', 'action' => 'report.exported', 'report' => 'trial_balance'],
            ['url' => '/reports/profit-and-loss/export.csv', 'action' => 'report.exported', 'report' => 'profit_and_loss'],
            ['url' => '/reports/balance-sheet/export.csv', 'action' => 'report.exported', 'report' => 'balance_sheet'],
            ['url' => '/reports/ar-aging/export.csv', 'action' => 'report.exported', 'report' => 'ar_aging'],
            ['url' => '/reports/ap-aging/export.csv', 'action' => 'report.exported', 'report' => 'ap_aging'],
            ['url' => '/reports/general-ledger/export.csv', 'action' => 'report.exported', 'report' => 'general_ledger'],
            ['url' => '/reports/tax-summary/export.csv', 'action' => 'report.exported', 'report' => 'tax_summary'],
        ];
    }

    public function test_every_export_endpoint_writes_exactly_one_exported_audit_row(): void
    {
        $company = Company::factory()->create();
        $user = $this->actingAsRole($company, Role::Owner);
        // ActivityLog is itself BelongsToCompany-scoped — set the context
        // explicitly so the `before`/`after` count queries below (which run
        // directly, not via an HTTP request) don't throw MissingCompanyContext
        // ahead of the first $this->get() call, which is what actually sets it
        // for the request pipeline.
        app(CompanyContext::class)->set($company);

        $expectations = $this->exportEndpointExpectations();
        $this->assertCount(14, $expectations, 'Sanity check: 7 new list exports + 7 Phase 5 report exports.');

        foreach ($expectations as $expectation) {
            $before = ActivityLog::query()->where('action', 'like', '%.exported')->count();

            $response = $this->get($expectation['url']);
            $response->assertOk("Export endpoint {$expectation['url']} did not return 200.");

            $after = ActivityLog::query()->where('action', 'like', '%.exported')->count();
            $this->assertSame(
                $before + 1,
                $after,
                "Export endpoint {$expectation['url']} must write EXACTLY ONE '*.exported' audit row (anti-exfiltration trail completeness)."
            );

            $row = ActivityLog::query()->where('action', 'like', '%.exported')->latest('id')->first();
            $this->assertSame($expectation['action'], $row->action, "Export endpoint {$expectation['url']} logged the wrong action.");
            $this->assertSame($user->id, $row->user_id, "Export endpoint {$expectation['url']} did not record the acting user.");
            $this->assertNotNull($row->created_at, "Export endpoint {$expectation['url']} did not record a timestamp.");

            if (isset($expectation['report'])) {
                $this->assertSame($expectation['report'], $row->after['report'] ?? null);
            }
        }
    }

    // ---------------------------------------------------------------
    // Scoped: an export can never include another tenant's audit trail
    // either — company scoping applies to ActivityLog itself (BelongsToCompany).
    // ---------------------------------------------------------------

    public function test_export_audit_rows_are_scoped_per_company(): void
    {
        $companyA = Company::factory()->create();
        $userA = $this->actingAsRole($companyA, Role::Owner);
        $this->get('/invoices/export.csv')->assertOk();

        $companyB = Company::factory()->create();
        $this->actingAsRole($companyB, Role::Owner);
        $this->get('/invoices/export.csv')->assertOk();

        // ActivityLog is itself BelongsToCompany-scoped, and the active
        // CompanyContext is companyB at this point (the last request set
        // it) — bypass the global scope here since this assertion
        // deliberately inspects BOTH companies' rows at once, which no
        // single active context could ever see through the normal scope.
        $rowsForA = ActivityLog::withoutGlobalScope('company')->where('company_id', $companyA->id)->where('action', 'invoices.exported')->get();
        $rowsForB = ActivityLog::withoutGlobalScope('company')->where('company_id', $companyB->id)->where('action', 'invoices.exported')->get();

        $this->assertCount(1, $rowsForA);
        $this->assertCount(1, $rowsForB);
        $this->assertSame($userA->id, $rowsForA->first()->user_id);
    }
}
