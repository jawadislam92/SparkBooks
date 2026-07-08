<?php

namespace Tests\Feature;

use App\Enums\AccountSubtype;
use App\Enums\AccountType;
use App\Enums\Role;
use App\Models\Account;
use App\Models\ActivityLog;
use App\Models\Company;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

class PeriodLockTest extends TestCase
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

    private function bankAccount(Company $company): Account
    {
        return Account::factory()->create([
            'company_id' => $company->id,
            'code' => '1010',
            'type' => AccountType::Asset->value,
            'subtype' => AccountSubtype::Bank->value,
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

    // ---------------------------------------------------------------
    // GET /settings/books — gate matrix
    // ---------------------------------------------------------------

    public function test_owner_can_view_current_lock(): void
    {
        $company = Company::factory()->create(['books_locked_through' => '2026-05-31']);
        $this->actingAsRole($company, Role::Owner);

        $this->get('/settings/books')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Settings/Books')
                ->where('lockedThrough', '2026-05-31')
                ->where('canManage', true)
            );
    }

    public function test_accountant_can_view_current_lock(): void
    {
        $company = Company::factory()->create();
        $this->actingAsRole($company, Role::Accountant);

        $this->get('/settings/books')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Settings/Books')
                ->where('lockedThrough', null)
                ->where('canManage', true)
            );
    }

    public function test_admin_can_view_read_only(): void
    {
        $company = Company::factory()->create(['books_locked_through' => '2026-05-31']);
        $this->actingAsRole($company, Role::Admin);

        $this->get('/settings/books')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Settings/Books')
                ->where('lockedThrough', '2026-05-31')
                ->where('canManage', false)
            );
    }

    public function test_staff_cannot_view_settings(): void
    {
        $company = Company::factory()->create();
        $this->actingAsRole($company, Role::Staff);

        $this->get('/settings/books')->assertForbidden();
    }

    // ---------------------------------------------------------------
    // POST /settings/books — gate matrix
    // ---------------------------------------------------------------

    public function test_owner_can_set_the_lock_date_and_it_is_audit_logged(): void
    {
        $company = Company::factory()->create();
        $this->actingAsRole($company, Role::Owner);

        $response = $this->post('/settings/books', ['locked_through' => '2026-06-30']);

        $response->assertRedirect(route('settings.books'));
        $this->assertSame('2026-06-30', $company->fresh()->books_locked_through->toDateString());

        $audit = ActivityLog::query()->where('action', 'periods.lock_moved')->sole();
        $this->assertSame(['books_locked_through' => null], $audit->before);
        $this->assertSame(['books_locked_through' => '2026-06-30'], $audit->after);
    }

    public function test_accountant_can_set_the_lock_date(): void
    {
        $company = Company::factory()->create();
        $this->actingAsRole($company, Role::Accountant);

        $response = $this->post('/settings/books', ['locked_through' => '2026-06-30']);

        $response->assertRedirect(route('settings.books'));
        $this->assertSame('2026-06-30', $company->fresh()->books_locked_through->toDateString());
    }

    public function test_admin_cannot_set_the_lock_date(): void
    {
        $company = Company::factory()->create();
        $this->actingAsRole($company, Role::Admin);

        $response = $this->post('/settings/books', ['locked_through' => '2026-06-30']);

        $response->assertForbidden();
        $this->assertNull($company->fresh()->books_locked_through);
    }

    public function test_staff_cannot_set_the_lock_date(): void
    {
        $company = Company::factory()->create();
        $this->actingAsRole($company, Role::Staff);

        $response = $this->post('/settings/books', ['locked_through' => '2026-06-30']);

        $response->assertForbidden();
        $this->assertNull($company->fresh()->books_locked_through);
    }

    // ---------------------------------------------------------------
    // Validation
    // ---------------------------------------------------------------

    public function test_future_lock_date_returns_422(): void
    {
        $company = Company::factory()->create();
        $this->actingAsRole($company, Role::Owner);

        $future = Carbon::tomorrow()->toDateString();
        $response = $this->post('/settings/books', ['locked_through' => $future]);

        $response->assertSessionHasErrors('locked_through');
        $this->assertStringContainsString(
            "hasn't ended yet",
            session('errors')->first('locked_through')
        );
        $this->assertNull($company->fresh()->books_locked_through);
    }

    public function test_locking_through_today_is_allowed(): void
    {
        $company = Company::factory()->create();
        $this->actingAsRole($company, Role::Owner);

        $today = Carbon::today()->toDateString();
        $response = $this->post('/settings/books', ['locked_through' => $today]);

        $response->assertRedirect(route('settings.books'));
        $this->assertSame($today, $company->fresh()->books_locked_through->toDateString());
    }

    public function test_empty_value_unlocks_and_is_audit_logged(): void
    {
        $company = Company::factory()->create(['books_locked_through' => '2026-06-30']);
        $this->actingAsRole($company, Role::Owner);

        $response = $this->post('/settings/books', ['locked_through' => '']);

        $response->assertRedirect(route('settings.books'));
        $this->assertNull($company->fresh()->books_locked_through);

        $audit = ActivityLog::query()->where('action', 'periods.lock_moved')->sole();
        $this->assertSame(['books_locked_through' => '2026-06-30'], $audit->before);
        $this->assertSame(['books_locked_through' => null], $audit->after);
    }

    public function test_moving_the_lock_backward_is_allowed_and_audit_logged(): void
    {
        $company = Company::factory()->create(['books_locked_through' => '2026-06-30']);
        $this->actingAsRole($company, Role::Owner);

        $response = $this->post('/settings/books', ['locked_through' => '2026-05-01']);

        $response->assertRedirect(route('settings.books'));
        $this->assertSame('2026-05-01', $company->fresh()->books_locked_through->toDateString());

        $audit = ActivityLog::query()->where('action', 'periods.lock_moved')->sole();
        $this->assertSame(['books_locked_through' => '2026-06-30'], $audit->before);
        $this->assertSame(['books_locked_through' => '2026-05-01'], $audit->after);
    }

    // ---------------------------------------------------------------
    // Cross-company safety
    // ---------------------------------------------------------------

    public function test_update_only_affects_the_current_company(): void
    {
        $companyA = Company::factory()->create();
        $companyB = Company::factory()->create(['books_locked_through' => '2026-01-01']);
        $this->actingAsRole($companyA, Role::Owner);

        $this->post('/settings/books', ['locked_through' => '2026-06-30'])
            ->assertRedirect(route('settings.books'));

        $this->assertSame('2026-06-30', $companyA->fresh()->books_locked_through->toDateString());
        $this->assertSame('2026-01-01', $companyB->fresh()->books_locked_through->toDateString());
    }

    // ---------------------------------------------------------------
    // Integration: setting the lock here is enforced end-to-end by the ledger
    // ---------------------------------------------------------------

    public function test_locking_via_settings_blocks_manual_journal_posting_on_or_before_the_lock_date(): void
    {
        $company = Company::factory()->create();
        $this->actingAsRole($company, Role::Owner);
        $bank = $this->bankAccount($company);
        $income = $this->incomeAccount($company);

        $this->post('/settings/books', ['locked_through' => '2026-06-30'])
            ->assertRedirect(route('settings.books'));

        $response = $this->postJson('/journal-entries', [
            'entry_date' => '2026-06-15',
            'memo' => 'Attempted in locked period',
            'lines' => [
                ['account_id' => $bank->id, 'debit' => 10000, 'credit' => 0],
                ['account_id' => $income->id, 'debit' => 0, 'credit' => 10000],
            ],
        ], ['X-Idempotency-Key' => (string) Str::uuid()]);

        $response->assertSessionHasErrors('lines');
        $this->assertStringContainsString('locked', session('errors')->first('lines'));
    }
}
