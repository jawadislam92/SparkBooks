<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\ActivityLog;
use App\Models\Company;
use App\Models\TaxRate;
use App\Models\User;
use App\Support\CompanyContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

class TaxRatesTest extends TestCase
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

    // ---------------------------------------------------------------
    // Create
    // ---------------------------------------------------------------

    public function test_owner_can_create_a_tax_rate(): void
    {
        $company = Company::factory()->create();
        $this->actingAsRole($company, Role::Owner);

        $response = $this->post('/tax-rates', [
            'name' => 'GST 18%',
            'rate' => '18',
        ]);

        $response->assertRedirect(route('tax-rates.index'));

        app(CompanyContext::class)->set($company);
        $taxRate = TaxRate::query()->sole();
        $this->assertSame('GST 18%', $taxRate->name);
        $this->assertSame('18.0000', $taxRate->rate);
        $this->assertFalse($taxRate->is_archived);

        $audit = ActivityLog::query()->where('action', 'tax_rate.created')->sole();
        $this->assertSame($taxRate->id, $audit->subject_id);
    }

    public function test_rate_with_up_to_four_decimal_places_is_accepted(): void
    {
        $company = Company::factory()->create();
        $this->actingAsRole($company, Role::Owner);

        $this->post('/tax-rates', ['name' => 'VAT', 'rate' => '17.5'])
            ->assertRedirect(route('tax-rates.index'));

        app(CompanyContext::class)->set($company);
        $this->assertSame('17.5000', TaxRate::query()->sole()->rate);
    }

    // ---------------------------------------------------------------
    // Validation
    // ---------------------------------------------------------------

    public function test_name_is_required(): void
    {
        $company = Company::factory()->create();
        $this->actingAsRole($company, Role::Owner);

        $this->post('/tax-rates', ['name' => '', 'rate' => '18'])
            ->assertSessionHasErrors('name');
    }

    public function test_name_over_sixty_characters_is_rejected(): void
    {
        $company = Company::factory()->create();
        $this->actingAsRole($company, Role::Owner);

        $this->post('/tax-rates', ['name' => str_repeat('a', 61), 'rate' => '18'])
            ->assertSessionHasErrors('name');
    }

    public function test_negative_rate_is_rejected(): void
    {
        $company = Company::factory()->create();
        $this->actingAsRole($company, Role::Owner);

        $this->post('/tax-rates', ['name' => 'Bad rate', 'rate' => '-1'])
            ->assertSessionHasErrors('rate');
    }

    public function test_rate_over_one_hundred_is_rejected(): void
    {
        $company = Company::factory()->create();
        $this->actingAsRole($company, Role::Owner);

        $this->post('/tax-rates', ['name' => 'Bad rate', 'rate' => '100.0001'])
            ->assertSessionHasErrors('rate');
    }

    public function test_rate_with_five_decimal_places_is_rejected(): void
    {
        $company = Company::factory()->create();
        $this->actingAsRole($company, Role::Owner);

        $this->post('/tax-rates', ['name' => 'Bad rate', 'rate' => '18.12345'])
            ->assertSessionHasErrors('rate');
    }

    public function test_non_numeric_rate_is_rejected(): void
    {
        $company = Company::factory()->create();
        $this->actingAsRole($company, Role::Owner);

        $this->post('/tax-rates', ['name' => 'Bad rate', 'rate' => 'eighteen'])
            ->assertSessionHasErrors('rate');
    }

    public function test_rate_at_the_boundaries_zero_and_one_hundred_is_accepted(): void
    {
        $company = Company::factory()->create();
        $this->actingAsRole($company, Role::Owner);

        $this->post('/tax-rates', ['name' => 'Zero rated', 'rate' => '0'])
            ->assertRedirect(route('tax-rates.index'));
        $this->post('/tax-rates', ['name' => 'Max rate', 'rate' => '100'])
            ->assertRedirect(route('tax-rates.index'));

        app(CompanyContext::class)->set($company);
        $this->assertSame(2, TaxRate::query()->count());
    }

    // ---------------------------------------------------------------
    // Update
    // ---------------------------------------------------------------

    public function test_owner_can_update_a_tax_rate(): void
    {
        $company = Company::factory()->create();
        $this->actingAsRole($company, Role::Owner);
        $taxRate = TaxRate::factory()->create(['company_id' => $company->id, 'name' => 'Old name', 'rate' => '5.0000']);

        $this->patch("/tax-rates/{$taxRate->id}", ['name' => 'New name', 'rate' => '7.5'])
            ->assertRedirect(route('tax-rates.index'));

        $taxRate->refresh();
        $this->assertSame('New name', $taxRate->name);
        $this->assertSame('7.5000', $taxRate->rate);

        $audit = ActivityLog::query()->where('action', 'tax_rate.updated')->sole();
        $this->assertSame($taxRate->id, $audit->subject_id);
        $this->assertSame('Old name', $audit->before['name']);
        $this->assertSame('New name', $audit->after['name']);
    }

    // ---------------------------------------------------------------
    // Archive / unarchive
    // ---------------------------------------------------------------

    public function test_archive_and_unarchive_a_tax_rate(): void
    {
        $company = Company::factory()->create();
        $this->actingAsRole($company, Role::Owner);
        $taxRate = TaxRate::factory()->create(['company_id' => $company->id]);

        $this->post("/tax-rates/{$taxRate->id}/archive")->assertRedirect(route('tax-rates.index'));
        $this->assertTrue($taxRate->fresh()->is_archived);

        $archiveAudit = ActivityLog::query()->where('action', 'tax_rate.archived')->sole();
        $this->assertSame($taxRate->id, $archiveAudit->subject_id);

        $this->post("/tax-rates/{$taxRate->id}/unarchive")->assertRedirect(route('tax-rates.index'));
        $this->assertFalse($taxRate->fresh()->is_archived);

        $unarchiveAudit = ActivityLog::query()->where('action', 'tax_rate.unarchived')->sole();
        $this->assertSame($taxRate->id, $unarchiveAudit->subject_id);
    }

    // ---------------------------------------------------------------
    // Index
    // ---------------------------------------------------------------

    public function test_index_returns_all_tax_rates(): void
    {
        $company = Company::factory()->create();
        $this->actingAsRole($company, Role::Owner);
        TaxRate::factory()->create(['company_id' => $company->id, 'name' => 'A rate']);
        TaxRate::factory()->create(['company_id' => $company->id, 'name' => 'B rate', 'is_archived' => true]);

        $this->get('/tax-rates')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Settings/TaxRates')
                ->has('taxRates', 2)
            );
    }

    // ---------------------------------------------------------------
    // Tenant scoping
    // ---------------------------------------------------------------

    public function test_cross_company_tax_rate_returns_404_on_update(): void
    {
        $companyA = Company::factory()->create();
        $taxRateA = TaxRate::factory()->create(['company_id' => $companyA->id]);

        $companyB = Company::factory()->create();
        $this->actingAsRole($companyB, Role::Owner);

        $this->patch("/tax-rates/{$taxRateA->id}", ['name' => 'Hijacked', 'rate' => '5'])
            ->assertNotFound();
    }

    public function test_cross_company_tax_rate_returns_404_on_archive(): void
    {
        $companyA = Company::factory()->create();
        $taxRateA = TaxRate::factory()->create(['company_id' => $companyA->id]);

        $companyB = Company::factory()->create();
        $this->actingAsRole($companyB, Role::Owner);

        $this->post("/tax-rates/{$taxRateA->id}/archive")->assertNotFound();
    }

    // ---------------------------------------------------------------
    // Gate: tax_rates.manage
    // ---------------------------------------------------------------

    public function test_owner_admin_and_accountant_can_create_tax_rates(): void
    {
        foreach ([Role::Owner, Role::Admin, Role::Accountant] as $role) {
            $company = Company::factory()->create();
            $this->actingAsRole($company, $role);

            $this->post('/tax-rates', ['name' => 'Allowed rate', 'rate' => '18'])
                ->assertRedirect(route('tax-rates.index'));
        }
    }

    public function test_staff_is_forbidden_from_every_tax_rate_action(): void
    {
        $company = Company::factory()->create();
        $this->actingAsRole($company, Role::Staff);
        $taxRate = TaxRate::factory()->create(['company_id' => $company->id]);

        $this->get('/tax-rates')->assertForbidden();
        $this->post('/tax-rates', ['name' => 'Nope', 'rate' => '18'])->assertForbidden();
        $this->patch("/tax-rates/{$taxRate->id}", ['name' => 'Nope', 'rate' => '18'])->assertForbidden();
        $this->post("/tax-rates/{$taxRate->id}/archive")->assertForbidden();
        $this->post("/tax-rates/{$taxRate->id}/unarchive")->assertForbidden();
    }
}
