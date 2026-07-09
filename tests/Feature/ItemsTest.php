<?php

namespace Tests\Feature;

use App\Enums\AccountSubtype;
use App\Enums\AccountType;
use App\Enums\Role;
use App\Models\Account;
use App\Models\ActivityLog;
use App\Models\Company;
use App\Models\Item;
use App\Models\TaxRate;
use App\Models\User;
use App\Support\CompanyContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

class ItemsTest extends TestCase
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

    private function incomeAccount(Company $company, array $overrides = []): Account
    {
        return Account::factory()->create(array_merge([
            'company_id' => $company->id,
            'type' => AccountType::Income->value,
            'subtype' => AccountSubtype::OperatingIncome->value,
        ], $overrides));
    }

    // ---------------------------------------------------------------
    // Create
    // ---------------------------------------------------------------

    public function test_owner_can_create_an_item_with_odd_paisa_unit_price_stored_exactly(): void
    {
        $company = Company::factory()->create();
        $this->actingAsRole($company, Role::Owner);

        $response = $this->post('/items', [
            'name' => 'Consulting hour',
            'description' => 'One hour of consulting',
            'unit_price' => 150050,
        ]);

        $response->assertRedirect(route('items.index'));

        app(CompanyContext::class)->set($company);
        $item = Item::query()->sole();
        $this->assertSame('Consulting hour', $item->name);
        $this->assertSame('One hour of consulting', $item->description);
        $this->assertSame(150050, $item->unit_price);
        $this->assertIsInt($item->unit_price);
        $this->assertFalse($item->is_archived);

        $audit = ActivityLog::query()->where('action', 'item.created')->sole();
        $this->assertSame($item->id, $audit->subject_id);
        $this->assertSame(150050, $audit->after['unit_price']);
    }

    public function test_unit_price_defaults_to_zero_when_omitted(): void
    {
        $company = Company::factory()->create();
        $this->actingAsRole($company, Role::Owner);

        $this->post('/items', ['name' => 'Free item'])
            ->assertRedirect(route('items.index'));

        app(CompanyContext::class)->set($company);
        $this->assertSame(0, Item::query()->sole()->unit_price);
    }

    // ---------------------------------------------------------------
    // Validation — name
    // ---------------------------------------------------------------

    public function test_name_is_required(): void
    {
        $company = Company::factory()->create();
        $this->actingAsRole($company, Role::Owner);

        $this->post('/items', ['name' => '', 'unit_price' => 100])
            ->assertSessionHasErrors('name');
    }

    public function test_name_over_one_hundred_twenty_characters_is_rejected(): void
    {
        $company = Company::factory()->create();
        $this->actingAsRole($company, Role::Owner);

        $this->post('/items', ['name' => str_repeat('a', 121)])
            ->assertSessionHasErrors('name');
    }

    // ---------------------------------------------------------------
    // Validation — unit_price
    // ---------------------------------------------------------------

    public function test_negative_unit_price_is_rejected(): void
    {
        $company = Company::factory()->create();
        $this->actingAsRole($company, Role::Owner);

        $this->post('/items', ['name' => 'Bad price', 'unit_price' => -1])
            ->assertSessionHasErrors('unit_price');

        app(CompanyContext::class)->set($company);
        $this->assertSame(0, Item::query()->count());
    }

    public function test_float_string_unit_price_is_rejected(): void
    {
        $company = Company::factory()->create();
        $this->actingAsRole($company, Role::Owner);

        $this->post('/items', ['name' => 'Bad price', 'unit_price' => '19.99'])
            ->assertSessionHasErrors('unit_price');

        app(CompanyContext::class)->set($company);
        $this->assertSame(0, Item::query()->count());
    }

    // ---------------------------------------------------------------
    // Validation — default_income_account_id
    // ---------------------------------------------------------------

    public function test_default_income_account_must_be_an_income_type_account(): void
    {
        $company = Company::factory()->create();
        $this->actingAsRole($company, Role::Owner);
        $expenseAccount = Account::factory()->create([
            'company_id' => $company->id,
            'type' => AccountType::Expense->value,
            'subtype' => AccountSubtype::OperatingExpense->value,
        ]);

        $this->post('/items', [
            'name' => 'Bad income account',
            'default_income_account_id' => $expenseAccount->id,
        ])->assertSessionHasErrors('default_income_account_id');

        app(CompanyContext::class)->set($company);
        $this->assertSame(0, Item::query()->count());
    }

    public function test_default_income_account_rejects_an_archived_account(): void
    {
        $company = Company::factory()->create();
        $this->actingAsRole($company, Role::Owner);
        $archivedIncomeAccount = $this->incomeAccount($company, ['is_archived' => true]);

        $this->post('/items', [
            'name' => 'Archived income account',
            'default_income_account_id' => $archivedIncomeAccount->id,
        ])->assertSessionHasErrors('default_income_account_id');
    }

    public function test_default_income_account_rejects_a_foreign_company_account(): void
    {
        $companyA = Company::factory()->create();
        $foreignAccount = $this->incomeAccount($companyA);

        $companyB = Company::factory()->create();
        $this->actingAsRole($companyB, Role::Owner);

        $this->post('/items', [
            'name' => 'Foreign income account',
            'default_income_account_id' => $foreignAccount->id,
        ])->assertSessionHasErrors('default_income_account_id');
    }

    // ---------------------------------------------------------------
    // Validation — default_tax_rate_id
    // ---------------------------------------------------------------

    public function test_default_tax_rate_rejects_an_archived_rate(): void
    {
        $company = Company::factory()->create();
        $this->actingAsRole($company, Role::Owner);
        $archivedRate = TaxRate::factory()->create(['company_id' => $company->id, 'is_archived' => true]);

        $this->post('/items', [
            'name' => 'Archived tax rate',
            'default_tax_rate_id' => $archivedRate->id,
        ])->assertSessionHasErrors('default_tax_rate_id');
    }

    public function test_default_tax_rate_rejects_a_foreign_company_rate(): void
    {
        $companyA = Company::factory()->create();
        $foreignRate = TaxRate::factory()->create(['company_id' => $companyA->id]);

        $companyB = Company::factory()->create();
        $this->actingAsRole($companyB, Role::Owner);

        $this->post('/items', [
            'name' => 'Foreign tax rate',
            'default_tax_rate_id' => $foreignRate->id,
        ])->assertSessionHasErrors('default_tax_rate_id');

        app(CompanyContext::class)->set($companyB);
        $this->assertSame(0, Item::query()->count());
    }

    // ---------------------------------------------------------------
    // Nullable links
    // ---------------------------------------------------------------

    public function test_nullable_income_account_and_tax_rate_are_allowed(): void
    {
        $company = Company::factory()->create();
        $this->actingAsRole($company, Role::Owner);

        $this->post('/items', ['name' => 'No links'])
            ->assertRedirect(route('items.index'));

        app(CompanyContext::class)->set($company);
        $item = Item::query()->sole();
        $this->assertNull($item->default_income_account_id);
        $this->assertNull($item->default_tax_rate_id);
    }

    public function test_valid_income_account_and_tax_rate_are_linked(): void
    {
        $company = Company::factory()->create();
        $this->actingAsRole($company, Role::Owner);
        $incomeAccount = $this->incomeAccount($company);
        $taxRate = TaxRate::factory()->create(['company_id' => $company->id]);

        $this->post('/items', [
            'name' => 'Linked item',
            'unit_price' => 500,
            'default_income_account_id' => $incomeAccount->id,
            'default_tax_rate_id' => $taxRate->id,
        ])->assertRedirect(route('items.index'));

        app(CompanyContext::class)->set($company);
        $item = Item::query()->sole();
        $this->assertSame($incomeAccount->id, $item->default_income_account_id);
        $this->assertSame($taxRate->id, $item->default_tax_rate_id);
    }

    // ---------------------------------------------------------------
    // Update
    // ---------------------------------------------------------------

    public function test_owner_can_update_an_item(): void
    {
        $company = Company::factory()->create();
        $this->actingAsRole($company, Role::Owner);
        $item = Item::factory()->create(['company_id' => $company->id, 'name' => 'Old name', 'unit_price' => 100]);

        $this->patch("/items/{$item->id}", ['name' => 'New name', 'unit_price' => 200])
            ->assertRedirect(route('items.index'));

        $item->refresh();
        $this->assertSame('New name', $item->name);
        $this->assertSame(200, $item->unit_price);

        $audit = ActivityLog::query()->where('action', 'item.updated')->sole();
        $this->assertSame($item->id, $audit->subject_id);
        $this->assertSame('Old name', $audit->before['name']);
        $this->assertSame('New name', $audit->after['name']);
    }

    public function test_update_can_clear_a_previously_set_income_account_and_tax_rate(): void
    {
        $company = Company::factory()->create();
        $this->actingAsRole($company, Role::Owner);
        $incomeAccount = $this->incomeAccount($company);
        $taxRate = TaxRate::factory()->create(['company_id' => $company->id]);
        $item = Item::factory()->create([
            'company_id' => $company->id,
            'default_income_account_id' => $incomeAccount->id,
            'default_tax_rate_id' => $taxRate->id,
        ]);

        $this->patch("/items/{$item->id}", ['name' => $item->name])
            ->assertRedirect(route('items.index'));

        $item->refresh();
        $this->assertNull($item->default_income_account_id);
        $this->assertNull($item->default_tax_rate_id);
    }

    // ---------------------------------------------------------------
    // Archive / unarchive
    // ---------------------------------------------------------------

    public function test_archive_and_unarchive_an_item(): void
    {
        $company = Company::factory()->create();
        $this->actingAsRole($company, Role::Owner);
        $item = Item::factory()->create(['company_id' => $company->id]);

        $this->post("/items/{$item->id}/archive")->assertRedirect(route('items.index'));
        $this->assertTrue($item->fresh()->is_archived);

        $archiveAudit = ActivityLog::query()->where('action', 'item.archived')->sole();
        $this->assertSame($item->id, $archiveAudit->subject_id);

        $this->post("/items/{$item->id}/unarchive")->assertRedirect(route('items.index'));
        $this->assertFalse($item->fresh()->is_archived);

        $unarchiveAudit = ActivityLog::query()->where('action', 'item.unarchived')->sole();
        $this->assertSame($item->id, $unarchiveAudit->subject_id);
    }

    // ---------------------------------------------------------------
    // Index
    // ---------------------------------------------------------------

    public function test_index_returns_all_items_and_active_option_lists_only(): void
    {
        $company = Company::factory()->create();
        $this->actingAsRole($company, Role::Owner);
        Item::factory()->create(['company_id' => $company->id, 'name' => 'A item']);
        Item::factory()->create(['company_id' => $company->id, 'name' => 'B item', 'is_archived' => true]);

        $this->incomeAccount($company, ['name' => 'Consulting income']);
        $this->incomeAccount($company, ['name' => 'Old income', 'is_archived' => true]);
        Account::factory()->create([
            'company_id' => $company->id,
            'type' => AccountType::Expense->value,
            'subtype' => AccountSubtype::OperatingExpense->value,
        ]);

        TaxRate::factory()->create(['company_id' => $company->id, 'name' => 'GST 18%']);
        TaxRate::factory()->create(['company_id' => $company->id, 'name' => 'Old rate', 'is_archived' => true]);

        $this->get('/items')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Items/Index')
                ->has('items', 2)
                ->has('incomeAccounts', 1)
                ->where('incomeAccounts.0.name', 'Consulting income')
                ->has('taxRates', 1)
                ->where('taxRates.0.name', 'GST 18%')
            );
    }

    // ---------------------------------------------------------------
    // Tenant scoping
    // ---------------------------------------------------------------

    public function test_cross_company_item_returns_404_on_update(): void
    {
        $companyA = Company::factory()->create();
        $itemA = Item::factory()->create(['company_id' => $companyA->id]);

        $companyB = Company::factory()->create();
        $this->actingAsRole($companyB, Role::Owner);

        $this->patch("/items/{$itemA->id}", ['name' => 'Hijacked'])
            ->assertNotFound();
    }

    public function test_cross_company_item_returns_404_on_archive(): void
    {
        $companyA = Company::factory()->create();
        $itemA = Item::factory()->create(['company_id' => $companyA->id]);

        $companyB = Company::factory()->create();
        $this->actingAsRole($companyB, Role::Owner);

        $this->post("/items/{$itemA->id}/archive")->assertNotFound();
    }

    // ---------------------------------------------------------------
    // Gate: items.manage
    // ---------------------------------------------------------------

    public function test_owner_admin_and_accountant_can_create_items(): void
    {
        foreach ([Role::Owner, Role::Admin, Role::Accountant] as $role) {
            $company = Company::factory()->create();
            $this->actingAsRole($company, $role);

            $this->post('/items', ['name' => 'Allowed item'])
                ->assertRedirect(route('items.index'));
        }
    }

    public function test_staff_is_forbidden_from_every_item_action(): void
    {
        $company = Company::factory()->create();
        $this->actingAsRole($company, Role::Staff);
        $item = Item::factory()->create(['company_id' => $company->id]);

        $this->get('/items')->assertForbidden();
        $this->post('/items', ['name' => 'Nope'])->assertForbidden();
        $this->patch("/items/{$item->id}", ['name' => 'Nope'])->assertForbidden();
        $this->post("/items/{$item->id}/archive")->assertForbidden();
        $this->post("/items/{$item->id}/unarchive")->assertForbidden();
    }
}
