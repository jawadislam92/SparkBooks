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
    // Type filter
    //
    // The index list is filtered by type client-side (mirroring the
    // archived toggle, which is also client-side — the full list is already
    // loaded). So the controller always returns every contact; the two tests
    // below pin down (a) that the controller does return all types, and
    // (b) the ContactType "Both counts as either" semantics the client-side
    // filter relies on ("customers" = customer + both, "vendors" = vendor +
    // both).
    // ---------------------------------------------------------------

    public function test_index_returns_all_contact_types_for_client_side_filtering(): void
    {
        $company = Company::factory()->create();
        $this->actingAsRole($company, Role::Owner);
        Contact::factory()->create(['company_id' => $company->id, 'type' => ContactType::Customer->value, 'name' => 'Cust Co']);
        Contact::factory()->create(['company_id' => $company->id, 'type' => ContactType::Vendor->value, 'name' => 'Vend Co']);
        Contact::factory()->create(['company_id' => $company->id, 'type' => ContactType::Both->value, 'name' => 'Both Co']);

        $this->get('/contacts')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Contacts/Index')
                ->has('contacts', 3)
            );
    }

    public function test_contact_type_filter_semantics_match_is_customer_and_is_vendor(): void
    {
        // "Customers" filter = customer + both; "Vendors" filter = vendor + both.
        $this->assertTrue(ContactType::Customer->isCustomer());
        $this->assertTrue(ContactType::Both->isCustomer());
        $this->assertFalse(ContactType::Vendor->isCustomer());

        $this->assertTrue(ContactType::Vendor->isVendor());
        $this->assertTrue(ContactType::Both->isVendor());
        $this->assertFalse(ContactType::Customer->isVendor());
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

    /**
     * Archiving is not deletion: a contact with real posted history (a
     * journal_line referencing it) can still be archived — nothing about the
     * historical line changes, and the contact stays fully resolvable off
     * that line's relation (the "retained in history" half of the brief).
     */
    public function test_a_contact_with_historical_journal_activity_can_still_be_archived(): void
    {
        $company = Company::factory()->create();
        $this->actingAsRole($company, Role::Owner);
        app(CompanyContext::class)->set($company);
        $contact = Contact::factory()->create(['company_id' => $company->id, 'name' => 'Longtime Customer']);
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

        $this->post("/contacts/{$contact->id}/archive")->assertRedirect(route('contacts.index'));
        $this->assertTrue($contact->fresh()->is_archived);

        $this->assertSame(1, DB::table('journal_lines')->where('contact_id', $contact->id)->count());

        // Still fully resolvable off its historical line, name intact.
        $line = DB::table('journal_lines')->where('contact_id', $contact->id)->first();
        $this->assertSame($contact->id, $line->contact_id);
        $this->assertSame('Longtime Customer', $contact->fresh()->name);
    }

    // ---------------------------------------------------------------
    // Tenant scoping
    // ---------------------------------------------------------------

    public function test_cross_company_contact_returns_404_on_archive(): void
    {
        $companyA = Company::factory()->create();
        $contactA = Contact::factory()->create(['company_id' => $companyA->id]);

        $companyB = Company::factory()->create();
        $this->actingAsRole($companyB, Role::Owner);

        $this->post("/contacts/{$contactA->id}/archive")->assertNotFound();
        $this->assertFalse($contactA->fresh()->is_archived);
    }

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
    // Model scopes (Phase 7 Task 2)
    // ---------------------------------------------------------------

    public function test_active_and_archived_scopes_filter_by_is_archived_within_the_current_company(): void
    {
        $company = Company::factory()->create();
        app(CompanyContext::class)->set($company);
        $active = Contact::factory()->create(['company_id' => $company->id]);
        $archived = Contact::factory()->create(['company_id' => $company->id, 'is_archived' => true]);

        $otherCompany = Company::factory()->create();
        $foreignActive = Contact::factory()->create(['company_id' => $otherCompany->id]);

        $activeIds = Contact::active()->pluck('id')->all();
        $archivedIds = Contact::archived()->pluck('id')->all();

        $this->assertContains($active->id, $activeIds);
        $this->assertNotContains($archived->id, $activeIds);
        $this->assertNotContains($foreignActive->id, $activeIds);

        $this->assertContains($archived->id, $archivedIds);
        $this->assertNotContains($active->id, $archivedIds);
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
