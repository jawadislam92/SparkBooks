<?php

namespace Tests\Feature\Reports;

use App\Domain\Ledger\EntryDraft;
use App\Domain\Ledger\LineDraft;
use App\Enums\AccountSubtype;
use App\Enums\AccountType;
use App\Enums\Role;
use App\Models\Account;
use App\Models\ActivityLog;
use App\Models\Company;
use App\Models\Contact;
use App\Models\Invoice;
use App\Models\User;
use App\Services\LedgerService;
use App\Support\CompanyContext;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

class ArAgingTest extends TestCase
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

    private function arAccount(Company $company): Account
    {
        return Account::factory()->create([
            'company_id' => $company->id,
            'code' => '1100',
            'type' => AccountType::Asset->value,
            'subtype' => AccountSubtype::Receivable->value,
        ]);
    }

    private function incomeAccount(Company $company): Account
    {
        return Account::factory()->create([
            'company_id' => $company->id,
            'code' => '4000',
            'type' => AccountType::Income->value,
            'subtype' => AccountSubtype::OperatingIncome->value,
        ]);
    }

    /** Creates a real (sent) invoice + its P1 ledger entry, so the report has a genuine contact row. */
    private function seedOutstandingInvoice(Company $company, Account $ar, Account $income, int $amount, string $dueDate): void
    {
        $contact = Contact::factory()->create(['company_id' => $company->id]);
        $invoice = Invoice::factory()->create([
            'contact_id' => $contact->id,
            'status' => 'sent',
            'issue_date' => '2026-01-01',
            'due_date' => $dueDate,
            'subtotal' => $amount,
            'tax_total' => 0,
            'total' => $amount,
            'balance' => $amount,
            'base_subtotal' => $amount,
            'base_total' => $amount,
            'is_opening' => false,
            'sent_at' => now(),
        ]);

        DB::transaction(fn () => app(LedgerService::class)->post(new EntryDraft(
            entryDate: CarbonImmutable::parse('2026-01-01'),
            memo: 'Invoice',
            sourceType: 'invoice',
            sourceId: $invoice->id,
            lines: [
                new LineDraft(accountId: $ar->id, debit: $amount, credit: 0, contactId: $contact->id),
                new LineDraft(accountId: $income->id, debit: 0, credit: $amount),
            ],
        )));
    }

    // ---------------------------------------------------------------
    // Gates
    // ---------------------------------------------------------------

    public function test_staff_forbidden_from_viewing_report(): void
    {
        $company = Company::factory()->create();
        $this->actingAsRole($company, Role::Staff);

        $this->get('/reports/ar-aging')->assertForbidden();
    }

    public function test_staff_forbidden_from_exporting_report(): void
    {
        $company = Company::factory()->create();
        $this->actingAsRole($company, Role::Staff);

        $this->get('/reports/ar-aging/export.csv')->assertForbidden();
    }

    public function test_owner_can_view_and_export(): void
    {
        $company = Company::factory()->create();
        $this->actingAsRole($company, Role::Owner);

        $this->get('/reports/ar-aging')->assertOk();
        $this->get('/reports/ar-aging/export.csv')->assertOk();
    }

    public function test_view_prop_reflects_export_permission(): void
    {
        $company = Company::factory()->create();
        $this->actingAsRole($company, Role::Owner);

        $this->get('/reports/ar-aging')->assertInertia(fn (AssertableInertia $page) => $page
            ->component('Reports/ArAging')
            ->where('canExport', true)
        );
    }

    // ---------------------------------------------------------------
    // Cross-company scoping
    // ---------------------------------------------------------------

    public function test_report_only_includes_current_companys_documents(): void
    {
        $companyA = Company::factory()->create();
        app(CompanyContext::class)->set($companyA);
        $arA = $this->arAccount($companyA);
        $incomeA = $this->incomeAccount($companyA);
        $this->seedOutstandingInvoice($companyA, $arA, $incomeA, 10000, '2026-01-15');

        $companyB = Company::factory()->create();
        $this->actingAsRole($companyB, Role::Owner);
        app(CompanyContext::class)->set($companyB);
        $arB = $this->arAccount($companyB);
        $incomeB = $this->incomeAccount($companyB);
        $this->seedOutstandingInvoice($companyB, $arB, $incomeB, 5000, '2026-01-15');

        $response = $this->get('/reports/ar-aging?as_of=2026-07-31');

        $response->assertOk();
        $response->assertInertia(function (AssertableInertia $page) {
            $page->component('Reports/ArAging');
            $page->where('report.grand_total', 5000);
        });
    }

    // ---------------------------------------------------------------
    // CSV export shape
    // ---------------------------------------------------------------

    public function test_csv_has_correct_header_row_and_a_matching_data_row(): void
    {
        $company = Company::factory()->create();
        $this->actingAsRole($company, Role::Owner);
        app(CompanyContext::class)->set($company);
        $ar = $this->arAccount($company);
        $income = $this->incomeAccount($company);
        $this->seedOutstandingInvoice($company, $ar, $income, 12345, '2026-01-01'); // 91+ days past due as of 2026-07-31

        $response = $this->get('/reports/ar-aging/export.csv?as_of=2026-07-31');
        $response->assertOk();

        $csv = $response->streamedContent();
        $lines = array_values(array_filter(preg_split('/\r\n|\r|\n/', $csv)));
        $rows = array_map('str_getcsv', $lines);

        $this->assertSame(['Contact', 'Current', '1–30 days', '31–60 days', '61–90 days', '90+ days', 'Total'], $rows[0]);

        $dataRow = $rows[1];
        $this->assertSame('123.45', $dataRow[5]); // 90+ days column
        $this->assertSame('123.45', $dataRow[6]); // Total column

        $totalsRow = end($rows);
        $this->assertSame('Totals', $totalsRow[0]);
        $this->assertSame('123.45', $totalsRow[6]);
    }

    public function test_csv_has_correct_content_type_and_attachment_disposition(): void
    {
        $company = Company::factory()->create();
        $this->actingAsRole($company, Role::Owner);

        $response = $this->get('/reports/ar-aging/export.csv?as_of=2026-07-15');

        $response->assertOk();
        $this->assertStringStartsWith('text/csv', $response->headers->get('Content-Type'));
        $disposition = $response->headers->get('Content-Disposition');
        $this->assertStringContainsString('attachment', $disposition);
        $this->assertStringContainsString('ar-aging-2026-07-15.csv', $disposition);
    }

    public function test_export_is_audit_logged(): void
    {
        $company = Company::factory()->create();
        $this->actingAsRole($company, Role::Owner);

        $this->get('/reports/ar-aging/export.csv?as_of=2026-07-15')->assertOk();

        $audit = ActivityLog::query()->where('action', 'report.exported')->sole();
        $this->assertSame('ar_aging', $audit->after['report']);
        $this->assertSame('2026-07-15', $audit->after['as_of']);
    }
}
