<?php

namespace Tests\Feature;

use App\Enums\AccountSubtype;
use App\Enums\AccountType;
use App\Enums\ContactType;
use App\Enums\Role;
use App\Models\Account;
use App\Models\ActivityLog;
use App\Models\Company;
use App\Models\Contact;
use App\Models\JournalEntry;
use App\Models\User;
use App\Support\CompanyContext;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

class ContactsTest extends TestCase
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

    /** A real JournalEntry row to hang raw-inserted journal_lines off of. */
    private function rawJournalEntry(Company $company, int $sourceId = 1): JournalEntry
    {
        app(CompanyContext::class)->set($company);

        return JournalEntry::query()->create([
            'entry_date' => '2026-07-01',
            'memo' => 'FK test entry',
            'source_type' => 'manual_journal',
            'source_id' => $sourceId,
            'entry_role' => 'primary',
        ]);
    }

    // ---------------------------------------------------------------
    // Create + type logic
    // ---------------------------------------------------------------

    public function test_owner_can_create_a_customer_contact(): void
    {
        $company = Company::factory()->create();
        $this->actingAsRole($company, Role::Owner);

        $response = $this->post('/contacts', [
            'type' => 'customer',
            'name' => 'Acme Traders',
            'email' => 'billing@acme.test',
        ]);

        $response->assertRedirect(route('contacts.index'));

        app(CompanyContext::class)->set($company);
        $contact = Contact::query()->sole();
        $this->assertSame('Acme Traders', $contact->name);
        $this->assertSame('billing@acme.test', $contact->email);
        $this->assertSame(ContactType::Customer, $contact->type);
        $this->assertTrue($contact->type->isCustomer());
        $this->assertFalse($contact->type->isVendor());

        $audit = ActivityLog::query()->where('action', 'contact.created')->sole();
        $this->assertSame($contact->id, $audit->subject_id);
    }

    public function test_owner_can_create_a_vendor_contact(): void
    {
        $company = Company::factory()->create();
        $this->actingAsRole($company, Role::Owner);

        $this->post('/contacts', [
            'type' => 'vendor',
            'name' => 'Beta Supplies',
        ])->assertRedirect(route('contacts.index'));

        app(CompanyContext::class)->set($company);
        $contact = Contact::query()->sole();
        $this->assertSame(ContactType::Vendor, $contact->type);
        $this->assertFalse($contact->type->isCustomer());
        $this->assertTrue($contact->type->isVendor());
    }

    public function test_owner_can_create_a_both_contact(): void
    {
        $company = Company::factory()->create();
        $this->actingAsRole($company, Role::Owner);

        $this->post('/contacts', [
            'type' => 'both',
            'name' => 'Gamma Corp',
        ])->assertRedirect(route('contacts.index'));

        app(CompanyContext::class)->set($company);
        $contact = Contact::query()->sole();
        $this->assertSame(ContactType::Both, $contact->type);
        $this->assertTrue($contact->type->isCustomer());
        $this->assertTrue($contact->type->isVendor());
    }

    // ---------------------------------------------------------------
    // Validation
    // ---------------------------------------------------------------

    public function test_name_is_required(): void
    {
        $company = Company::factory()->create();
        $this->actingAsRole($company, Role::Owner);

        $response = $this->post('/contacts', [
            'type' => 'customer',
            'name' => '',
        ]);

        $response->assertSessionHasErrors('name');
    }

    public function test_email_must_be_a_valid_format(): void
    {
        $company = Company::factory()->create();
        $this->actingAsRole($company, Role::Owner);

        $response = $this->post('/contacts', [
            'type' => 'customer',
            'name' => 'Acme Traders',
            'email' => 'not-an-email',
        ]);

        $response->assertSessionHasErrors('email');
    }

    // ---------------------------------------------------------------
    // Archive / unarchive
    // ---------------------------------------------------------------

    public function test_archive_hides_contact_from_the_active_filter_and_unarchive_restores_it(): void
    {
        $company = Company::factory()->create();
        $this->actingAsRole($company, Role::Owner);
        $contact = Contact::factory()->create(['company_id' => $company->id]);

        $this->post("/contacts/{$contact->id}/archive")->assertRedirect(route('contacts.index'));
        $this->assertTrue($contact->fresh()->is_archived);

        $this->get('/contacts')->assertInertia(fn (AssertableInertia $page) => $page
            ->component('Contacts/Index')
            ->where('contacts.0.is_archived', true)
        );

        $audit = ActivityLog::query()->where('action', 'contact.archived')->sole();
        $this->assertSame($contact->id, $audit->subject_id);

        $this->post("/contacts/{$contact->id}/unarchive")->assertRedirect(route('contacts.index'));
        $this->assertFalse($contact->fresh()->is_archived);

        $unarchiveAudit = ActivityLog::query()->where('action', 'contact.unarchived')->sole();
        $this->assertSame($contact->id, $unarchiveAudit->subject_id);
    }

    // ---------------------------------------------------------------
    // Tenant scoping
    // ---------------------------------------------------------------

    public function test_cross_company_contact_returns_404_on_show(): void
    {
        $companyA = Company::factory()->create();
        $contactA = Contact::factory()->create(['company_id' => $companyA->id]);

        $companyB = Company::factory()->create();
        $this->actingAsRole($companyB, Role::Owner);

        $this->get("/contacts/{$contactA->id}")->assertNotFound();
    }

    public function test_cross_company_contact_returns_404_on_update(): void
    {
        $companyA = Company::factory()->create();
        $contactA = Contact::factory()->create(['company_id' => $companyA->id]);

        $companyB = Company::factory()->create();
        $this->actingAsRole($companyB, Role::Owner);

        $this->patch("/contacts/{$contactA->id}", [
            'type' => 'customer',
            'name' => 'Hijacked',
        ])->assertNotFound();
    }

    // ---------------------------------------------------------------
    // journal_lines.contact_id FK
    // ---------------------------------------------------------------

    public function test_journal_line_fk_rejects_a_contact_belonging_to_another_company(): void
    {
        $companyA = Company::factory()->create();
        $companyB = Company::factory()->create();
        $foreignContact = Contact::factory()->create(['company_id' => $companyB->id]);

        app(CompanyContext::class)->set($companyA);
        $account = Account::factory()->create([
            'company_id' => $companyA->id,
            'type' => AccountType::Asset->value,
            'subtype' => AccountSubtype::Receivable->value,
        ]);
        $entry = $this->rawJournalEntry($companyA);

        $this->expectException(QueryException::class);

        DB::table('journal_lines')->insert([
            'journal_entry_id' => $entry->id,
            'company_id' => $companyA->id,
            'account_id' => $account->id,
            'debit' => 1000,
            'credit' => 0,
            'memo' => null,
            'contact_id' => $foreignContact->id,
        ]);
    }

    public function test_journal_line_fk_accepts_a_same_company_contact(): void
    {
        $company = Company::factory()->create();
        app(CompanyContext::class)->set($company);

        $contact = Contact::factory()->create(['company_id' => $company->id]);
        $account = Account::factory()->create([
            'company_id' => $company->id,
            'type' => AccountType::Asset->value,
            'subtype' => AccountSubtype::Receivable->value,
        ]);
        $entry = $this->rawJournalEntry($company);

        DB::table('journal_lines')->insert([
            'journal_entry_id' => $entry->id,
            'company_id' => $company->id,
            'account_id' => $account->id,
            'debit' => 1000,
            'credit' => 0,
            'memo' => null,
            'contact_id' => $contact->id,
        ]);

        $this->assertSame(1, DB::table('journal_lines')->where('contact_id', $contact->id)->count());
    }

    // ---------------------------------------------------------------
    // Gate: contacts.manage
    // ---------------------------------------------------------------

    public function test_owner_admin_and_accountant_can_create_contacts(): void
    {
        foreach ([Role::Owner, Role::Admin, Role::Accountant] as $role) {
            $company = Company::factory()->create();
            $this->actingAsRole($company, $role);

            $this->post('/contacts', ['type' => 'customer', 'name' => 'Allowed Co'])
                ->assertRedirect(route('contacts.index'));
        }
    }

    public function test_staff_is_forbidden_from_every_contacts_action(): void
    {
        $company = Company::factory()->create();
        $this->actingAsRole($company, Role::Staff);
        $contact = Contact::factory()->create(['company_id' => $company->id]);

        $this->get('/contacts')->assertForbidden();
        $this->post('/contacts', ['type' => 'customer', 'name' => 'Nope'])->assertForbidden();
        $this->patch("/contacts/{$contact->id}", ['type' => 'customer', 'name' => 'Nope'])->assertForbidden();
        $this->post("/contacts/{$contact->id}/archive")->assertForbidden();
        $this->post("/contacts/{$contact->id}/unarchive")->assertForbidden();
        $this->get("/contacts/{$contact->id}")->assertForbidden();
    }

    // ---------------------------------------------------------------
    // Outstanding balance
    // ---------------------------------------------------------------

    public function test_outstanding_balance_is_zero_for_a_fresh_contact_with_no_transactions(): void
    {
        $company = Company::factory()->create();
        $this->actingAsRole($company, Role::Owner);
        $contact = Contact::factory()->create(['company_id' => $company->id, 'type' => ContactType::Both->value]);

        $this->get("/contacts/{$contact->id}")
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Contacts/Show')
                ->where('outstanding_balance', 0)
            );
    }
}
