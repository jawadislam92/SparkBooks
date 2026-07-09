<?php

namespace Tests\Feature;

use App\Enums\ContactType;
use App\Enums\Role;
use App\Models\ActivityLog;
use App\Models\BankAccount;
use App\Models\Company;
use App\Models\Contact;
use App\Models\JournalEntry;
use App\Models\OpeningBalanceRun;
use App\Models\User;
use App\Services\OpeningCsvParser;
use App\Support\CompanyContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class OpeningCsvTest extends TestCase
{
    use RefreshDatabase;

    private function actingAsRole(Company $company, Role $role): void
    {
        $user = User::factory()->create();
        $user->companies()->attach($company, ['role' => $role->value]);
        $user->forceFill(['current_company_id' => $company->id])->save();
        $this->actingAs($user);
    }

    /** @param array<string, mixed> $overrides */
    private function bankAccount(Company $company, array $overrides = []): BankAccount
    {
        return app(CompanyContext::class)->runFor($company, fn () => BankAccount::factory()->create(array_merge([
            'company_id' => $company->id,
        ], $overrides)));
    }

    /** @param array<string, mixed> $overrides */
    private function contact(Company $company, array $overrides = []): Contact
    {
        return app(CompanyContext::class)->runFor($company, fn () => Contact::factory()->create(array_merge([
            'company_id' => $company->id,
        ], $overrides)));
    }

    private function customer(Company $company, array $overrides = []): Contact
    {
        return $this->contact($company, array_merge(['type' => ContactType::Customer->value], $overrides));
    }

    private function vendor(Company $company, array $overrides = []): Contact
    {
        return $this->contact($company, array_merge(['type' => ContactType::Vendor->value], $overrides));
    }

    private function upload(string $kind, string $csvContent): TestResponse
    {
        return $this->post("/setup/opening-balances/import/{$kind}", [
            'file' => UploadedFile::fake()->createWithContent('upload.csv', $csvContent),
        ]);
    }

    // ---------------------------------------------------------------
    // Template downloads
    // ---------------------------------------------------------------

    public function test_template_download_has_the_right_headers_content_type_and_disposition_for_every_kind(): void
    {
        $company = Company::factory()->create();
        $this->actingAsRole($company, Role::Owner);

        $expectedHeaders = [
            'customers' => ['name', 'email', 'phone', 'tax_number'],
            'vendors' => ['name', 'email', 'phone', 'tax_number'],
            'open_customer_invoices' => ['customer', 'reference', 'original_date', 'due_date', 'amount'],
            'open_vendor_bills' => ['vendor', 'reference', 'original_date', 'due_date', 'amount'],
            'bank_balances' => ['account', 'amount'],
        ];

        foreach ($expectedHeaders as $kind => $header) {
            $response = $this->get("/setup/opening-balances/template/{$kind}.csv");

            $response->assertOk();
            $this->assertStringStartsWith('text/csv', $response->headers->get('Content-Type'));
            $disposition = $response->headers->get('Content-Disposition');
            $this->assertStringContainsString('attachment', $disposition);
            $this->assertStringContainsString("opening-{$kind}-template.csv", $disposition);

            $csv = $response->streamedContent();
            $lines = array_values(array_filter(preg_split('/\r\n|\r|\n/', $csv)));
            $rows = array_map('str_getcsv', $lines);

            $this->assertSame($header, $rows[0], "header row for kind {$kind}");
            $this->assertCount(2, $rows, "template for kind {$kind} must have exactly one example row");
            $this->assertCount(count($header), $rows[1], "example row for kind {$kind} must match header column count");
        }
    }

    public function test_unknown_template_kind_returns_404(): void
    {
        $company = Company::factory()->create();
        $this->actingAsRole($company, Role::Owner);

        $this->get('/setup/opening-balances/template/nonsense.csv')->assertNotFound();
    }

    // ---------------------------------------------------------------
    // Happy path: open_customer_invoices
    // ---------------------------------------------------------------

    public function test_valid_open_customer_invoices_csv_parses_to_expected_rows_with_zero_errors(): void
    {
        $company = Company::factory()->create();
        $this->actingAsRole($company, Role::Owner);
        $customerA = $this->customer($company, ['name' => 'Acme Traders']);
        $customerB = $this->customer($company, ['name' => 'Zenith Co']);

        $csv = "customer,reference,original_date,due_date,amount\n"
            ."Acme Traders,INV-1,2026-05-01,2026-06-01,1500.50\n"
            ."Zenith Co,INV-2,2026-05-02,2026-06-02,33.33\n";

        $response = $this->upload('open_customer_invoices', $csv);

        $response->assertOk();
        $response->assertJson(['errors' => []]);
        $rows = $response->json('rows');
        $this->assertCount(2, $rows);

        $this->assertSame($customerA->id, $rows[0]['contact_id']);
        $this->assertSame('INV-1', $rows[0]['reference']);
        $this->assertSame('2026-05-01', $rows[0]['original_date']);
        $this->assertSame('2026-06-01', $rows[0]['due_date']);
        $this->assertSame(150050, $rows[0]['amount'], 'Rs 1500.50 must parse to exactly 150050 minor units, no float drift.');

        $this->assertSame($customerB->id, $rows[1]['contact_id']);
        $this->assertSame(3333, $rows[1]['amount']);

        // Importing only ever stages — it never posts.
        $this->assertSame(0, OpeningBalanceRun::query()->count());
        $this->assertSame(0, JournalEntry::query()->count());
    }

    // ---------------------------------------------------------------
    // All-or-nothing: multiple bad rows all surface, nothing is staged
    // ---------------------------------------------------------------

    public function test_all_or_nothing_bad_amount_and_unknown_customer_both_reported_and_nothing_staged(): void
    {
        $company = Company::factory()->create();
        $this->actingAsRole($company, Role::Owner);
        $customerA = $this->customer($company, ['name' => 'Acme Traders']);

        $csv = "customer,reference,original_date,due_date,amount\n"
            ."Acme Traders,INV-1,2026-05-01,2026-06-01,1500.50\n" // line 2: valid
            ."Acme Traders,INV-2,2026-05-01,2026-06-01,abc\n"      // line 3: bad amount
            ."Acme Traders,INV-3,2026-05-01,2026-06-01,200.00\n" // line 4: valid
            ."Ghost Customer,INV-4,2026-05-01,2026-06-01,50.00\n"; // line 5: unknown customer

        $response = $this->upload('open_customer_invoices', $csv);

        $response->assertOk();
        $errors = $response->json('errors');
        $this->assertCount(2, $errors);

        $lineNumbers = array_column($errors, 'line');
        $this->assertContains(3, $lineNumbers);
        $this->assertContains(5, $lineNumbers);

        $line3 = collect($errors)->firstWhere('line', 3);
        $this->assertStringContainsString("amount 'abc' isn't a valid number", $line3['message']);

        $line5 = collect($errors)->firstWhere('line', 5);
        $this->assertStringContainsString("no customer named 'Ghost Customer'", $line5['message']);

        // Nothing staged — rows is empty, and definitely nothing posted.
        $this->assertSame([], $response->json('rows'));
        $this->assertSame(0, OpeningBalanceRun::query()->count());
        $this->assertSame(0, JournalEntry::query()->count());
    }

    // ---------------------------------------------------------------
    // Money exactness (Money::parseToMinor, no float)
    // ---------------------------------------------------------------

    public function test_money_amounts_parse_to_exact_minor_units_with_no_float(): void
    {
        $company = Company::factory()->create();
        $this->actingAsRole($company, Role::Owner);
        $bank = $this->bankAccount($company, ['name' => 'Main Bank Account']);

        $csv = "account,amount\nMain Bank Account,\"1,500.50\"\n";
        $response = $this->upload('bank_balances', $csv);
        $response->assertJson(['errors' => []]);
        $this->assertSame(150050, $response->json('rows.0.amount'));

        $csv = "account,amount\nMain Bank Account,33.33\n";
        $response = $this->upload('bank_balances', $csv);
        $response->assertJson(['errors' => []]);
        $this->assertSame(3333, $response->json('rows.0.amount'));
    }

    public function test_amount_with_more_than_two_decimals_is_rejected(): void
    {
        $company = Company::factory()->create();
        $this->actingAsRole($company, Role::Owner);
        $this->bankAccount($company, ['name' => 'Main Bank Account']);

        $csv = "account,amount\nMain Bank Account,1.999\n";
        $response = $this->upload('bank_balances', $csv);

        $errors = $response->json('errors');
        $this->assertCount(1, $errors);
        $this->assertSame(2, $errors[0]['line']);
        $this->assertStringContainsString("amount '1.999' isn't a valid number", $errors[0]['message']);
        $this->assertSame([], $response->json('rows'));
    }

    public function test_non_numeric_amount_is_rejected(): void
    {
        $company = Company::factory()->create();
        $this->actingAsRole($company, Role::Owner);
        $this->bankAccount($company, ['name' => 'Main Bank Account']);

        $csv = "account,amount\nMain Bank Account,abc\n";
        $response = $this->upload('bank_balances', $csv);

        $errors = $response->json('errors');
        $this->assertCount(1, $errors);
        $this->assertStringContainsString("amount 'abc' isn't a valid number", $errors[0]['message']);
    }

    public function test_zero_amount_is_rejected(): void
    {
        $company = Company::factory()->create();
        $this->actingAsRole($company, Role::Owner);
        $this->bankAccount($company, ['name' => 'Main Bank Account']);

        $csv = "account,amount\nMain Bank Account,0.00\n";
        $response = $this->upload('bank_balances', $csv);

        $errors = $response->json('errors');
        $this->assertCount(1, $errors);
        $this->assertStringContainsString('amount must be greater than zero', $errors[0]['message']);
    }

    // ---------------------------------------------------------------
    // Dates
    // ---------------------------------------------------------------

    public function test_bad_date_format_is_rejected_with_the_line_number(): void
    {
        $company = Company::factory()->create();
        $this->actingAsRole($company, Role::Owner);
        $this->customer($company, ['name' => 'Acme Traders']);

        $csv = "customer,reference,original_date,due_date,amount\n"
            ."Acme Traders,INV-1,05/01/2026,2026-06-01,100.00\n";

        $response = $this->upload('open_customer_invoices', $csv);

        $errors = $response->json('errors');
        $this->assertCount(1, $errors);
        $this->assertSame(2, $errors[0]['line']);
        $this->assertStringContainsString("original_date '05/01/2026' isn't a valid date", $errors[0]['message']);
    }

    public function test_impossible_calendar_date_is_rejected(): void
    {
        $company = Company::factory()->create();
        $this->actingAsRole($company, Role::Owner);
        $this->customer($company, ['name' => 'Acme Traders']);

        $csv = "customer,reference,original_date,due_date,amount\n"
            ."Acme Traders,INV-1,2026-02-30,2026-06-01,100.00\n";

        $response = $this->upload('open_customer_invoices', $csv);

        $errors = $response->json('errors');
        $this->assertCount(1, $errors);
        $this->assertStringContainsString("original_date '2026-02-30' isn't a valid date", $errors[0]['message']);
    }

    public function test_due_date_before_original_date_is_rejected(): void
    {
        $company = Company::factory()->create();
        $this->actingAsRole($company, Role::Owner);
        $this->customer($company, ['name' => 'Acme Traders']);

        $csv = "customer,reference,original_date,due_date,amount\n"
            ."Acme Traders,INV-1,2026-06-01,2026-05-01,100.00\n";

        $response = $this->upload('open_customer_invoices', $csv);

        $errors = $response->json('errors');
        $this->assertCount(1, $errors);
        $this->assertStringContainsString('due_date must be on or after original_date', $errors[0]['message']);
    }

    // ---------------------------------------------------------------
    // Contact resolution
    // ---------------------------------------------------------------

    public function test_unknown_customer_name_is_rejected(): void
    {
        $company = Company::factory()->create();
        $this->actingAsRole($company, Role::Owner);

        $csv = "customer,reference,original_date,due_date,amount\n"
            ."Nobody Inc,INV-1,2026-05-01,2026-06-01,100.00\n";

        $response = $this->upload('open_customer_invoices', $csv);

        $errors = $response->json('errors');
        $this->assertCount(1, $errors);
        $this->assertStringContainsString("no customer named 'Nobody Inc' — create the contact first", $errors[0]['message']);
    }

    public function test_vendor_only_contact_used_in_customer_template_is_rejected(): void
    {
        $company = Company::factory()->create();
        $this->actingAsRole($company, Role::Owner);
        $this->vendor($company, ['name' => 'Beta Supplies']);

        $csv = "customer,reference,original_date,due_date,amount\n"
            ."Beta Supplies,INV-1,2026-05-01,2026-06-01,100.00\n";

        $response = $this->upload('open_customer_invoices', $csv);

        $errors = $response->json('errors');
        $this->assertCount(1, $errors);
        $this->assertStringContainsString("no customer named 'Beta Supplies'", $errors[0]['message']);
    }

    public function test_customer_only_contact_used_in_vendor_template_is_rejected(): void
    {
        $company = Company::factory()->create();
        $this->actingAsRole($company, Role::Owner);
        $this->customer($company, ['name' => 'Acme Traders']);

        $csv = "vendor,reference,original_date,due_date,amount\n"
            ."Acme Traders,BILL-1,2026-05-01,2026-06-01,100.00\n";

        $response = $this->upload('open_vendor_bills', $csv);

        $errors = $response->json('errors');
        $this->assertCount(1, $errors);
        $this->assertStringContainsString("no vendor named 'Acme Traders'", $errors[0]['message']);
    }

    public function test_both_type_contact_is_accepted_in_either_template(): void
    {
        $company = Company::factory()->create();
        $this->actingAsRole($company, Role::Owner);
        $both = $this->contact($company, ['type' => ContactType::Both->value, 'name' => 'Dual Co']);

        $customerCsv = "customer,reference,original_date,due_date,amount\nDual Co,INV-1,2026-05-01,2026-06-01,100.00\n";
        $vendorCsv = "vendor,reference,original_date,due_date,amount\nDual Co,BILL-1,2026-05-01,2026-06-01,100.00\n";

        $this->upload('open_customer_invoices', $customerCsv)->assertJson(['errors' => []]);
        $this->upload('open_vendor_bills', $vendorCsv)->assertJson(['errors' => []]);
    }

    public function test_archived_contact_is_rejected(): void
    {
        $company = Company::factory()->create();
        $this->actingAsRole($company, Role::Owner);
        $this->customer($company, ['name' => 'Acme Traders', 'is_archived' => true]);

        $csv = "customer,reference,original_date,due_date,amount\n"
            ."Acme Traders,INV-1,2026-05-01,2026-06-01,100.00\n";

        $response = $this->upload('open_customer_invoices', $csv);

        $errors = $response->json('errors');
        $this->assertCount(1, $errors);
        $this->assertStringContainsString("customer 'Acme Traders' is archived", $errors[0]['message']);
    }

    public function test_contact_from_another_company_is_unknown_here(): void
    {
        $companyA = Company::factory()->create();
        $this->customer($companyA, ['name' => 'Acme Traders']);

        $companyB = Company::factory()->create();
        $this->actingAsRole($companyB, Role::Owner);

        $csv = "customer,reference,original_date,due_date,amount\n"
            ."Acme Traders,INV-1,2026-05-01,2026-06-01,100.00\n";

        $response = $this->upload('open_customer_invoices', $csv);

        $errors = $response->json('errors');
        $this->assertCount(1, $errors);
        $this->assertStringContainsString("no customer named 'Acme Traders'", $errors[0]['message']);
    }

    // ---------------------------------------------------------------
    // Bank template
    // ---------------------------------------------------------------

    public function test_unknown_bank_account_name_is_rejected(): void
    {
        $company = Company::factory()->create();
        $this->actingAsRole($company, Role::Owner);

        $csv = "account,amount\nNo Such Bank,1000.00\n";
        $response = $this->upload('bank_balances', $csv);

        $errors = $response->json('errors');
        $this->assertCount(1, $errors);
        $this->assertStringContainsString("no bank account named 'No Such Bank' — create the account first", $errors[0]['message']);
    }

    public function test_valid_bank_balances_csv_produces_bank_lines_with_bank_account_id_and_minor_amount(): void
    {
        $company = Company::factory()->create();
        $this->actingAsRole($company, Role::Owner);
        $bank = $this->bankAccount($company, ['name' => 'Main Bank Account']);

        $csv = "account,amount\nMain Bank Account,50000.00\n";
        $response = $this->upload('bank_balances', $csv);

        $response->assertJson(['errors' => []]);
        $rows = $response->json('rows');
        $this->assertCount(1, $rows);
        $this->assertSame($bank->id, $rows[0]['bank_account_id']);
        $this->assertSame(5000000, $rows[0]['amount']);
    }

    public function test_archived_bank_account_is_rejected(): void
    {
        $company = Company::factory()->create();
        $this->actingAsRole($company, Role::Owner);
        $this->bankAccount($company, ['name' => 'Old Account', 'is_archived' => true]);

        $csv = "account,amount\nOld Account,1000.00\n";
        $response = $this->upload('bank_balances', $csv);

        $errors = $response->json('errors');
        $this->assertCount(1, $errors);
        $this->assertStringContainsString("bank account 'Old Account' is archived", $errors[0]['message']);
    }

    public function test_bank_account_from_another_company_is_unknown_here(): void
    {
        $companyA = Company::factory()->create();
        $this->bankAccount($companyA, ['name' => 'Shared Name Bank']);

        $companyB = Company::factory()->create();
        $this->actingAsRole($companyB, Role::Owner);

        $csv = "account,amount\nShared Name Bank,1000.00\n";
        $response = $this->upload('bank_balances', $csv);

        $errors = $response->json('errors');
        $this->assertCount(1, $errors);
        $this->assertStringContainsString("no bank account named 'Shared Name Bank'", $errors[0]['message']);
    }

    // ---------------------------------------------------------------
    // customers / vendors contact templates
    // ---------------------------------------------------------------

    public function test_customers_template_parses_contact_fields(): void
    {
        $company = Company::factory()->create();
        $this->actingAsRole($company, Role::Owner);

        $csv = "name,email,phone,tax_number\nAcme Traders,billing@acme.test,0300-1234567,1234567-8\n";
        $response = $this->upload('customers', $csv);

        $response->assertJson(['errors' => []]);
        $rows = $response->json('rows');
        $this->assertSame([
            'name' => 'Acme Traders',
            'email' => 'billing@acme.test',
            'phone' => '0300-1234567',
            'tax_number' => '1234567-8',
        ], $rows[0]);
    }

    public function test_customers_template_rejects_invalid_email(): void
    {
        $company = Company::factory()->create();
        $this->actingAsRole($company, Role::Owner);

        $csv = "name,email,phone,tax_number\nAcme Traders,not-an-email,,\n";
        $response = $this->upload('customers', $csv);

        $errors = $response->json('errors');
        $this->assertCount(1, $errors);
        $this->assertStringContainsString("email 'not-an-email' isn't valid", $errors[0]['message']);
    }

    public function test_customers_template_requires_name(): void
    {
        $company = Company::factory()->create();
        $this->actingAsRole($company, Role::Owner);

        $csv = "name,email,phone,tax_number\n,billing@acme.test,,\n";
        $response = $this->upload('customers', $csv);

        $errors = $response->json('errors');
        $this->assertCount(1, $errors);
        $this->assertStringContainsString('name is required', $errors[0]['message']);
    }

    // ---------------------------------------------------------------
    // Missing required columns (header-level, whole-file rejection)
    // ---------------------------------------------------------------

    public function test_missing_required_column_rejects_the_whole_file_at_line_one(): void
    {
        $company = Company::factory()->create();
        $this->actingAsRole($company, Role::Owner);

        $csv = "customer,reference,original_date,due_date\nAcme,INV-1,2026-05-01,2026-06-01\n"; // no amount column

        $response = $this->upload('open_customer_invoices', $csv);

        $errors = $response->json('errors');
        $this->assertCount(1, $errors);
        $this->assertSame(1, $errors[0]['line']);
        $this->assertStringContainsString('amount', $errors[0]['message']);
        $this->assertSame([], $response->json('rows'));
    }

    // ---------------------------------------------------------------
    // Gate: settings.manage only
    // ---------------------------------------------------------------

    public function test_accountant_is_forbidden_from_importing(): void
    {
        $company = Company::factory()->create();
        $this->actingAsRole($company, Role::Accountant);

        $csv = "account,amount\nMain,1000.00\n";
        $this->upload('bank_balances', $csv)->assertForbidden();
        $this->get('/setup/opening-balances/template/bank_balances.csv')->assertForbidden();
    }

    public function test_staff_is_forbidden_from_importing(): void
    {
        $company = Company::factory()->create();
        $this->actingAsRole($company, Role::Staff);

        $csv = "account,amount\nMain,1000.00\n";
        $this->upload('bank_balances', $csv)->assertForbidden();
        $this->get('/setup/opening-balances/template/bank_balances.csv')->assertForbidden();
    }

    // ---------------------------------------------------------------
    // Audit logging
    // ---------------------------------------------------------------

    public function test_import_is_audit_logged_with_kind_and_row_and_error_counts(): void
    {
        $company = Company::factory()->create();
        $this->actingAsRole($company, Role::Owner);
        $customerA = $this->customer($company, ['name' => 'Acme Traders']);

        $csv = "customer,reference,original_date,due_date,amount\n"
            ."Acme Traders,INV-1,2026-05-01,2026-06-01,100.00\n"
            ."Ghost,INV-2,2026-05-01,2026-06-01,50.00\n";

        $this->upload('open_customer_invoices', $csv)->assertOk();

        $audit = ActivityLog::query()->where('action', 'opening_csv.imported')->sole();
        $this->assertSame('open_customer_invoices', $audit->after['kind']);
        $this->assertSame(0, $audit->after['row_count']);
        $this->assertSame(1, $audit->after['error_count']);
    }

    // ---------------------------------------------------------------
    // Unknown import kind
    // ---------------------------------------------------------------

    public function test_unknown_import_kind_returns_404(): void
    {
        $company = Company::factory()->create();
        $this->actingAsRole($company, Role::Owner);

        $this->upload('nonsense', "a,b\n1,2\n")->assertNotFound();
    }

    // ---------------------------------------------------------------
    // Parser kind constant sanity (used directly, not just via HTTP)
    // ---------------------------------------------------------------

    public function test_parser_kinds_constant_matches_the_five_template_kinds(): void
    {
        $this->assertSame(
            ['customers', 'vendors', 'open_customer_invoices', 'open_vendor_bills', 'bank_balances'],
            OpeningCsvParser::KINDS,
        );
    }
}
