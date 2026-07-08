<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Exceptions\MissingCompanyContext;
use App\Models\Company;
use App\Models\User;
use App\Support\CompanyContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CompanyContextTest extends TestCase
{
    use RefreshDatabase;

    public function test_get_without_context_throws(): void
    {
        $this->expectException(MissingCompanyContext::class);
        app(CompanyContext::class)->get();
    }

    public function test_run_for_restores_previous_context(): void
    {
        [$a, $b] = Company::factory()->count(2)->create();
        $ctx = app(CompanyContext::class);
        $ctx->set($a);
        $inner = $ctx->runFor($b, fn () => $ctx->id());
        $this->assertSame($b->id, $inner);
        $this->assertSame($a->id, $ctx->id());
    }

    public function test_middleware_sets_context_from_current_company(): void
    {
        $user = User::factory()->create();
        $company = Company::factory()->create();
        $user->companies()->attach($company, ['role' => Role::Owner->value]);
        $user->forceFill(['current_company_id' => $company->id])->save();

        $this->actingAs($user)->get('/dashboard')->assertOk();
        $this->assertSame($company->id, app(CompanyContext::class)->id());
    }

    public function test_switch_requires_membership(): void
    {
        $user = User::factory()->create();
        $mine = Company::factory()->create();
        $other = Company::factory()->create();
        $user->companies()->attach($mine, ['role' => Role::Owner->value]);
        $user->forceFill(['current_company_id' => $mine->id])->save();

        $this->actingAs($user)->post(route('company.switch'), ['company_id' => $other->id])
            ->assertNotFound();
        $this->assertSame($mine->id, $user->fresh()->current_company_id);
    }

    public function test_switch_updates_current_company(): void
    {
        $user = User::factory()->create();
        [$a, $b] = Company::factory()->count(2)->create();
        $user->companies()->attach($a, ['role' => Role::Owner->value]);
        $user->companies()->attach($b, ['role' => Role::Staff->value]);
        $user->forceFill(['current_company_id' => $a->id])->save();

        $this->actingAs($user)->post(route('company.switch'), ['company_id' => $b->id])
            ->assertRedirect();
        $this->assertSame($b->id, $user->fresh()->current_company_id);
    }

    public function test_middleware_repairs_stale_current_company(): void
    {
        $user = User::factory()->create();
        $stale = Company::factory()->create();
        $mine = Company::factory()->create();
        $user->companies()->attach($mine, ['role' => Role::Owner->value]);
        $user->forceFill(['current_company_id' => $stale->id])->save();

        $this->actingAs($user)->get('/dashboard')->assertOk();
        $this->assertSame($mine->id, app(CompanyContext::class)->id());
        $this->assertSame($mine->id, $user->fresh()->current_company_id);
    }

    public function test_middleware_handles_user_with_no_companies(): void
    {
        $user = User::factory()->create();

        // Since Task 8, a user with no company is redirected to onboarding
        // (RequireCompany) rather than reaching the dashboard. SetCompanyContext
        // still runs beforehand and must not crash for a user with no companies.
        $this->actingAs($user)->get('/dashboard')->assertRedirect(route('onboarding.company'));
        $this->assertNull($user->fresh()->current_company_id);
        $this->assertFalse(app(CompanyContext::class)->has());
    }

    public function test_run_for_restores_context_when_callback_throws(): void
    {
        [$a, $b] = Company::factory()->count(2)->create();
        $ctx = app(CompanyContext::class);
        $ctx->set($a);

        try {
            $ctx->runFor($b, fn () => throw new \RuntimeException('boom'));
            $this->fail('Expected RuntimeException was not thrown.');
        } catch (\RuntimeException $e) {
            $this->assertSame('boom', $e->getMessage());
        }

        $this->assertSame($a->id, $ctx->id());
    }
}
