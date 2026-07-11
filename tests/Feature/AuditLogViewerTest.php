<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\ActivityLog;
use App\Models\Company;
use App\Models\Contact;
use App\Models\User;
use App\Support\CompanyContext;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

/**
 * Phase 6 Task 2 (spec §7.13) — the READ-ONLY audit-log viewer
 * (AuditLogController + Audit/Index.tsx) over the append-only activity_log
 * that AuditLogTest already proves is written correctly and immutably. This
 * file covers the VIEWER only: gating, listing, filtering, tenant
 * isolation, and — the one thing that matters most for a screen surfacing
 * every user's audited actions to anyone with audit.view — that the raw
 * before/after diff never reaches the default list payload.
 *
 * Rows are built directly via ActivityLog::query()->create() rather than
 * firing a real mutating request per row: this is fixture setup for a read
 * screen, not new application logging (AuditLogger itself is untouched).
 */
class AuditLogViewerTest extends TestCase
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
    private function makeLog(Company $company, User $user, string $action, array $overrides = []): ActivityLog
    {
        return ActivityLog::query()->create(array_merge([
            'company_id' => $company->id,
            'user_id' => $user->id,
            'action' => $action,
            'subject_type' => null,
            'subject_id' => null,
            'before' => null,
            'after' => null,
            'ip' => '10.0.0.1',
            'created_at' => CarbonImmutable::now(),
        ], $overrides));
    }

    // ---------------------------------------------------------------
    // Gate: audit.view (Owner/Accountant only — config/permissions.php)
    // ---------------------------------------------------------------

    public function test_owner_can_view_the_audit_log(): void
    {
        $company = Company::factory()->create();
        $this->actingAsRole($company, Role::Owner);

        $this->get('/settings/audit-log')->assertOk();
    }

    public function test_accountant_can_view_the_audit_log(): void
    {
        $company = Company::factory()->create();
        $this->actingAsRole($company, Role::Accountant);

        $this->get('/settings/audit-log')->assertOk();
    }

    public function test_staff_is_forbidden(): void
    {
        $company = Company::factory()->create();
        $this->actingAsRole($company, Role::Staff);

        $this->get('/settings/audit-log')->assertForbidden();
    }

    public function test_admin_is_forbidden(): void
    {
        // Admin is the one deliberate gap PROJECT_MAP §10 documents: it lacks
        // ledger.post_manual/periods.lock/audit.view.
        $company = Company::factory()->create();
        $this->actingAsRole($company, Role::Admin);

        $this->get('/settings/audit-log')->assertForbidden();
    }

    // ---------------------------------------------------------------
    // Listing: newest-first, paginated, renders the Inertia page
    // ---------------------------------------------------------------

    public function test_lists_rows_newest_first_and_renders_the_page(): void
    {
        $company = Company::factory()->create();
        $owner = $this->actingAsRole($company, Role::Owner);

        $older = $this->makeLog($company, $owner, 'contact.created', [
            'created_at' => CarbonImmutable::now()->subDays(2),
        ]);
        $newer = $this->makeLog($company, $owner, 'invoice.sent', [
            'created_at' => CarbonImmutable::now()->subDay(),
        ]);

        $this->get('/settings/audit-log')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Audit/Index')
                ->where('logs.data.0.id', $newer->id)
                ->where('logs.data.1.id', $older->id)
                ->etc()
            );
    }

    public function test_paginates_at_fifty_per_page(): void
    {
        $company = Company::factory()->create();
        $owner = $this->actingAsRole($company, Role::Owner);

        for ($i = 0; $i < 51; $i++) {
            $this->makeLog($company, $owner, 'contact.created', [
                'created_at' => CarbonImmutable::now()->subMinutes($i),
            ]);
        }

        $this->get('/settings/audit-log')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->has('logs.data', 50)
                ->where('logs.total', 51)
                ->where('logs.last_page', 2)
                ->etc()
            );

        $this->get('/settings/audit-log?page=2')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->has('logs.data', 1)
                ->etc()
            );
    }

    // ---------------------------------------------------------------
    // Filters
    // ---------------------------------------------------------------

    public function test_filter_by_user_narrows_to_that_users_rows(): void
    {
        $company = Company::factory()->create();
        $owner = $this->actingAsRole($company, Role::Owner);
        $accountant = User::factory()->create();
        $accountant->companies()->attach($company, ['role' => Role::Accountant->value]);

        $this->makeLog($company, $owner, 'contact.created');
        $accountantLog = $this->makeLog($company, $accountant, 'invoice.sent');

        $this->get('/settings/audit-log?user_id='.$accountant->id)
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->has('logs.data', 1)
                ->where('logs.data.0.id', $accountantLog->id)
                ->etc()
            );
    }

    public function test_filter_by_module_narrows_to_that_action_prefix_only(): void
    {
        $company = Company::factory()->create();
        $owner = $this->actingAsRole($company, Role::Owner);

        $this->makeLog($company, $owner, 'invoice.sent');
        $this->makeLog($company, $owner, 'invoice.draft_created');
        $billLog = $this->makeLog($company, $owner, 'bill.received');

        // A module filter must not accidentally match a DIFFERENT module
        // that merely shares a substring (e.g. 'bill' vs 'bill_payment').
        $this->makeLog($company, $owner, 'bill_payment.recorded');

        $this->get('/settings/audit-log?module=bill')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->has('logs.data', 1)
                ->where('logs.data.0.id', $billLog->id)
                ->etc()
            );

        $this->get('/settings/audit-log?module=invoice')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->has('logs.data', 2)
                ->etc()
            );
    }

    public function test_filter_by_date_range_narrows_to_created_at_in_range(): void
    {
        $company = Company::factory()->create();
        $owner = $this->actingAsRole($company, Role::Owner);

        $inRange = $this->makeLog($company, $owner, 'contact.created', [
            'created_at' => CarbonImmutable::parse('2026-06-15 10:00:00'),
        ]);
        $this->makeLog($company, $owner, 'contact.created', [
            'created_at' => CarbonImmutable::parse('2026-05-01 10:00:00'),
        ]);
        $this->makeLog($company, $owner, 'contact.created', [
            'created_at' => CarbonImmutable::parse('2026-07-01 10:00:00'),
        ]);

        $this->get('/settings/audit-log?from=2026-06-01&to=2026-06-30')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->has('logs.data', 1)
                ->where('logs.data.0.id', $inRange->id)
                ->etc()
            );
    }

    // ---------------------------------------------------------------
    // Tenant isolation
    // ---------------------------------------------------------------

    public function test_another_companys_rows_are_absent(): void
    {
        $company = Company::factory()->create();
        $otherCompany = Company::factory()->create();
        $owner = $this->actingAsRole($company, Role::Owner);
        $otherUser = User::factory()->create();

        $ownLog = $this->makeLog($company, $owner, 'contact.created');
        $this->makeLog($otherCompany, $otherUser, 'contact.created');

        $this->get('/settings/audit-log')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->has('logs.data', 1)
                ->where('logs.data.0.id', $ownLog->id)
                ->etc()
            );
    }

    // ---------------------------------------------------------------
    // No raw before/after JSON leak
    // ---------------------------------------------------------------

    public function test_default_list_payload_never_includes_the_raw_before_after_diff(): void
    {
        $company = Company::factory()->create();
        $owner = $this->actingAsRole($company, Role::Owner);

        $this->makeLog($company, $owner, 'contact.updated', [
            'before' => ['name' => 'Old-Sensitive-Name'],
            'after' => ['name' => 'New-Sensitive-Name'],
        ]);

        $response = $this->get('/settings/audit-log')->assertOk();

        $response->assertInertia(fn (AssertableInertia $page) => $page
            ->has('logs.data.0', fn (AssertableInertia $row) => $row
                ->missing('before')
                ->missing('after')
                ->etc()
            )
            ->etc()
        );

        // Belt-and-suspenders: the actual sensitive values must not appear
        // ANYWHERE in the rendered response, not just absent from the two
        // expected keys.
        $response->assertDontSee('Old-Sensitive-Name');
        $response->assertDontSee('New-Sensitive-Name');
    }

    // ---------------------------------------------------------------
    // Humanization
    // ---------------------------------------------------------------

    public function test_humanizes_action_and_subject_readably(): void
    {
        $company = Company::factory()->create();
        $owner = $this->actingAsRole($company, Role::Owner);
        app(CompanyContext::class)->set($company);
        $contact = Contact::factory()->create(['name' => 'Acme Corp']);

        $this->makeLog($company, $owner, 'invoice.sent', [
            'created_at' => CarbonImmutable::now()->subMinute(),
        ]);
        $this->makeLog($company, $owner, 'contact.created', [
            'subject_type' => Contact::class,
            'subject_id' => $contact->id,
        ]);

        $this->get('/settings/audit-log')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('logs.data.0.action', 'Contact created')
                ->where('logs.data.0.subject', 'Contact #'.$contact->id)
                ->where('logs.data.1.action', 'Invoice sent')
                ->etc()
            );
    }
}
