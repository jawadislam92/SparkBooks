<?php // tests/Feature/CompanyMembershipTest.php
namespace Tests\Feature;

use App\Enums\Role;
use App\Models\Company;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CompanyMembershipTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_can_belong_to_companies_with_roles(): void
    {
        $user = User::factory()->create();
        $a = Company::factory()->create(['base_currency' => 'PKR']);
        $b = Company::factory()->create();
        $user->companies()->attach($a, ['role' => Role::Owner->value]);
        $user->companies()->attach($b, ['role' => Role::Staff->value]);

        $this->assertTrue($user->belongsToCompany($a));
        $this->assertSame(Role::Owner, $user->roleIn($a));
        $this->assertSame(Role::Staff, $user->roleIn($b));
        $this->assertNull($user->roleIn(Company::factory()->create()));
    }

    public function test_membership_is_unique_per_company(): void
    {
        $user = User::factory()->create();
        $c = Company::factory()->create();
        $user->companies()->attach($c, ['role' => Role::Owner->value]);
        $this->expectException(\Illuminate\Database\QueryException::class);
        $user->companies()->attach($c, ['role' => Role::Admin->value]);
    }
}
