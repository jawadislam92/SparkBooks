<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\Company;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

class InertiaSharedPropsTest extends TestCase
{
    use RefreshDatabase;

    public function test_dashboard_shares_current_company_matching_the_users_company(): void
    {
        $user = User::factory()->create();
        $company = Company::factory()->create(['name' => 'SparkingAsia', 'base_currency' => 'PKR']);
        $user->companies()->attach($company, ['role' => Role::Owner->value]);
        $user->forceFill(['current_company_id' => $company->id])->save();

        $this->actingAs($user)->get('/dashboard')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('currentCompany.id', $company->id)
                ->where('currentCompany.name', 'SparkingAsia')
                ->where('currentCompany.base_currency', 'PKR')
            );
    }

    public function test_dashboard_shares_all_memberships_with_roles(): void
    {
        $user = User::factory()->create();
        $owned = Company::factory()->create();
        $staffed = Company::factory()->create();
        $user->companies()->attach($owned, ['role' => Role::Owner->value]);
        $user->companies()->attach($staffed, ['role' => Role::Staff->value]);
        $user->forceFill(['current_company_id' => $owned->id])->save();

        $this->actingAs($user)->get('/dashboard')
            ->assertOk()
            ->assertInertia(function (AssertableInertia $page) use ($owned, $staffed) {
                $page->has('companies', 2);
                $page->where('companies', function ($companies) use ($owned, $staffed) {
                    $byId = collect($companies)->keyBy('id');

                    return $byId->get($owned->id)['role'] === 'owner'
                        && $byId->get($staffed->id)['role'] === 'staff'
                        && $byId->get($owned->id)['name'] === $owned->name;
                });
            });
    }

    public function test_guest_request_shares_null_current_company_and_no_memberships(): void
    {
        $this->get('/login')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('currentCompany', null)
                ->where('companies', [])
            );
    }

    public function test_guest_request_shares_an_empty_can_map(): void
    {
        $this->get('/login')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('can', [])
            );
    }
}
