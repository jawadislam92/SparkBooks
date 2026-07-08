<?php
namespace Tests\Feature;

use App\Enums\Role;
use App\Models\ActivityLog;
use App\Models\User;
use App\Support\CompanyContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class OnboardingTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_without_company_is_redirected_to_onboarding(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user)->get('/dashboard')->assertRedirect(route('onboarding.company'));
    }

    public function test_creating_a_company_attaches_owner_and_sets_current(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->post(route('onboarding.company'), [
            'name' => 'SparkingAsia',
            'base_currency' => 'PKR',
            'fiscal_year_start_month' => 7,
        ])->assertRedirect(route('dashboard'));

        $user->refresh();
        $company = $user->currentCompany;
        $this->assertNotNull($company);
        $this->assertSame('SparkingAsia', $company->name);
        $this->assertSame(Role::Owner, $user->roleIn($company));

        app(CompanyContext::class)->set($company);
        $this->assertSame(1, ActivityLog::query()->where('action', 'company.created')->count());
    }

    public function test_invalid_currency_is_rejected(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user)->post(route('onboarding.company'), [
            'name' => 'X', 'base_currency' => 'ZZZ', 'fiscal_year_start_month' => 1,
        ])->assertSessionHasErrors('base_currency');
    }

    public function test_dashboard_loads_once_company_exists(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user)->post(route('onboarding.company'), [
            'name' => 'SparkingAsia', 'base_currency' => 'PKR', 'fiscal_year_start_month' => 7,
        ]);
        $this->actingAs($user)->get('/dashboard')->assertOk();
    }

    public function test_onboarding_form_redirects_to_dashboard_when_user_already_has_a_company(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user)->post(route('onboarding.company'), [
            'name' => 'SparkingAsia', 'base_currency' => 'PKR', 'fiscal_year_start_month' => 7,
        ]);

        $this->actingAs($user)->get(route('onboarding.company'))->assertRedirect(route('dashboard'));
    }
}
