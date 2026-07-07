# SparkBooks Phase 0 — Foundation Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Stand up the SparkBooks Laravel application with multi-tenant company context, the §7.14 permission matrix, audit-log and idempotency plumbing, database-level constraints, and a green CI-ready test suite — Phase 0 of the spec at `docs/superpowers/specs/2026-07-08-accounting-software-v1-design.md`.

**Architecture:** Laravel 12 + Breeze (Inertia/React/TypeScript) monolith. Single database, `company_id` on every tenant table, enforced by a `BelongsToCompany` global scope that reads a request-scoped `CompanyContext` service — never `auth()` directly. All correctness plumbing (audit, idempotency) is service-class based so later phases consume it uniformly.

**Tech Stack:** PHP 8.3.30 (Laragon), Laravel 12, laravel/breeze (react + TS), MySQL 8.4.3, Vite, Tailwind, PHPUnit.

## Global Constraints

- **Shell env (every Bash session):** `export PATH="/c/laragon/bin/php/php-8.3.30-Win32-vs16-x64:/c/laragon/bin/composer:/c/laragon/bin/nodejs/node-v22:/c/laragon/bin/mysql/mysql-8.4.3-winx64/bin:$PATH"` — Laragon tools are not on PATH otherwise. Working dir: `/c/laragon/www/SparkBooks`.
- MySQL root has **no password** (Laragon default). Dev DB `sparkbooks`, test DB `sparkbooks_test`.
- Money will be BIGINT minor units (Phase 1+); **no float ever** on money paths (spec §3).
- Tenant tables: `company_id` NOT NULL + FK, always via `BelongsToCompany` (spec §3/§10).
- Missing company context must **throw** (`MissingCompanyContext`), never return unscoped or empty results silently (spec §3 — vacuous-checker rule).
- Roles/permissions must match spec §7.14 **exactly** — the config file is the artifact the permission-matrix tests assert against.
- Audit log rows are append-only — model-level guards throw on update/delete (spec §7.13).
- Commit after every task with the `Co-Authored-By: Claude Fable 5 <noreply@anthropic.com>` trailer.
- Tests run against real MySQL (`sparkbooks_test`), `RefreshDatabase`. Run with `php artisan test`.

---

### Task 1: Scaffold Laravel 12 + Breeze (React/TS) into the existing repo

**Files:**
- Create: entire Laravel skeleton at repo root (composer scaffolds into a temp dir, then contents move into `/c/laragon/www/SparkBooks`)
- Modify: `.env`, `phpunit.xml`

**Interfaces:**
- Produces: a bootable app; `php artisan test` green; databases `sparkbooks` + `sparkbooks_test` exist.

- [ ] **Step 1: Scaffold into temp dir and merge into repo root**

```bash
cd /c/laragon/www
composer create-project laravel/laravel SparkBooks-tmp --no-interaction
# move skeleton into the repo (docs/ and .git already live there; no filename collisions expected)
cp -r SparkBooks-tmp/. SparkBooks/ && rm -rf SparkBooks-tmp
cd SparkBooks && git status --short | head -20
```

- [ ] **Step 2: Install Breeze with React + TypeScript + Inertia**

```bash
composer require laravel/breeze --dev
php artisan breeze:install react --typescript --no-interaction
npm install
```

- [ ] **Step 3: Create databases and configure env**

```bash
mysql -uroot -e "CREATE DATABASE IF NOT EXISTS sparkbooks CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci; CREATE DATABASE IF NOT EXISTS sparkbooks_test CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci;"
```

In `.env` set: `APP_NAME=SparkBooks`, `DB_CONNECTION=mysql`, `DB_DATABASE=sparkbooks`, `DB_USERNAME=root`, `DB_PASSWORD=` (empty). In `phpunit.xml` replace the sqlite env lines with:

```xml
<env name="DB_CONNECTION" value="mysql"/>
<env name="DB_DATABASE" value="sparkbooks_test"/>
```

- [ ] **Step 4: Migrate, build, verify green**

```bash
php artisan migrate --force
npm run build
php artisan test
```
Expected: default Breeze auth tests PASS (0 failures).

- [ ] **Step 5: Commit**

```bash
git add -A && git commit -m "chore: scaffold Laravel 12 + Breeze react-ts, MySQL config, test DB"
```

---

### Task 2: Companies, membership + Role enum

**Files:**
- Create: `database/migrations/xxxx_create_companies_table.php`, `xxxx_create_company_user_table.php`, `xxxx_add_current_company_id_to_users.php`
- Create: `app/Models/Company.php`, `app/Enums/Role.php`, `config/permissions.php` (matrix lands Task 5; create file with `catalog` + `roles` keys now)
- Create: `database/factories/CompanyFactory.php`
- Modify: `app/Models/User.php`
- Test: `tests/Feature/CompanyMembershipTest.php`

**Interfaces:**
- Produces: `Company` model (`name`, `base_currency`, `fiscal_year_start_month`, `books_start_date?`, `books_locked_through?`); `User::companies(): BelongsToMany` (pivot `role`); `User::roleIn(Company $c): ?Role`; `User::belongsToCompany(Company $c): bool`; `Role` string enum `Owner|Admin|Accountant|Staff`.

- [ ] **Step 1: Write failing tests**

```php
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
```

- [ ] **Step 2: Run to verify failure** — `php artisan test --filter=CompanyMembershipTest` → FAIL (class Company not found).

- [ ] **Step 3: Implement**

```php
<?php // database/migrations/xxxx_create_companies_table.php (php artisan make:migration)
Schema::create('companies', function (Blueprint $t) {
    $t->id();
    $t->string('name');
    $t->char('base_currency', 3);
    $t->unsignedTinyInteger('fiscal_year_start_month')->default(1);
    $t->date('books_start_date')->nullable();
    $t->date('books_locked_through')->nullable();
    $t->timestamps();
});
```

```php
<?php // xxxx_create_company_user_table.php
Schema::create('company_user', function (Blueprint $t) {
    $t->id();
    $t->foreignId('company_id')->constrained()->restrictOnDelete();
    $t->foreignId('user_id')->constrained()->restrictOnDelete();
    $t->string('role', 20);
    $t->timestamps();
    $t->unique(['company_id', 'user_id']);
});
```

```php
<?php // xxxx_add_current_company_id_to_users.php
Schema::table('users', fn (Blueprint $t) =>
    $t->foreignId('current_company_id')->nullable()->constrained('companies')->nullOnDelete());
```

```php
<?php // app/Enums/Role.php
namespace App\Enums;

enum Role: string
{
    case Owner = 'owner';
    case Admin = 'admin';
    case Accountant = 'accountant';
    case Staff = 'staff';

    /** @return list<string> */
    public function permissions(): array
    {
        return config("permissions.roles.{$this->value}", []);
    }

    public function allows(string $permission): bool
    {
        return in_array($permission, $this->permissions(), true);
    }
}
```

```php
<?php // app/Models/Company.php
namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class Company extends Model
{
    use HasFactory;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['books_start_date' => 'date', 'books_locked_through' => 'date'];
    }

    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class)->withPivot('role')->withTimestamps();
    }
}
```

`User.php` additions:

```php
use App\Enums\Role;
use App\Models\Company;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

public function companies(): BelongsToMany
{
    return $this->belongsToMany(Company::class)->withPivot('role')->withTimestamps();
}

public function currentCompany(): BelongsTo
{
    return $this->belongsTo(Company::class, 'current_company_id');
}

public function roleIn(Company $company): ?Role
{
    $pivot = $this->companies()->whereKey($company->id)->first()?->pivot;
    return $pivot ? Role::from($pivot->role) : null;
}

public function belongsToCompany(Company $company): bool
{
    return $this->roleIn($company) !== null;
}
```

```php
<?php // database/factories/CompanyFactory.php
namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;

class CompanyFactory extends Factory
{
    public function definition(): array
    {
        return [
            'name' => fake()->company(),
            'base_currency' => 'PKR',
            'fiscal_year_start_month' => 1,
        ];
    }
}
```

`config/permissions.php` placeholder (Task 5 fills the matrix):

```php
<?php
return ['catalog' => [], 'roles' => []];
```

- [ ] **Step 4: Run to verify pass** — `php artisan test --filter=CompanyMembershipTest` → PASS.
- [ ] **Step 5: Commit** — `git add -A && git commit -m "feat: companies, membership pivot with roles, Role enum"`

---

### Task 3: CompanyContext service, middleware, company switcher

**Files:**
- Create: `app/Support/CompanyContext.php`, `app/Exceptions/MissingCompanyContext.php`, `app/Http/Middleware/SetCompanyContext.php`, `app/Http/Controllers/CompanySwitchController.php`
- Modify: `bootstrap/app.php` (middleware alias + web group append), `routes/web.php`, `app/Providers/AppServiceProvider.php` (scoped singleton)
- Test: `tests/Feature/CompanyContextTest.php`

**Interfaces:**
- Produces: `CompanyContext::set(Company)/get(): Company/id(): int/has(): bool/clear()/runFor(Company, Closure): mixed` (resolve via `app(CompanyContext::class)`; registered **scoped**). Route `POST /company/switch` (`company.switch`), body `{company_id}`. `MissingCompanyContext extends RuntimeException`.

- [ ] **Step 1: Write failing tests**

```php
<?php // tests/Feature/CompanyContextTest.php
namespace Tests\Feature;

use App\Enums\Role;
use App\Models\Company;
use App\Models\User;
use App\Support\CompanyContext;
use App\Exceptions\MissingCompanyContext;
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
            ->assertForbidden();
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
}
```

- [ ] **Step 2: Run** → FAIL (CompanyContext not found).
- [ ] **Step 3: Implement**

```php
<?php // app/Exceptions/MissingCompanyContext.php
namespace App\Exceptions;

class MissingCompanyContext extends \RuntimeException
{
    public function __construct()
    {
        parent::__construct('No company context is set. Tenant queries must never run unscoped.');
    }
}
```

```php
<?php // app/Support/CompanyContext.php
namespace App\Support;

use App\Exceptions\MissingCompanyContext;
use App\Models\Company;
use Closure;

class CompanyContext
{
    private ?Company $company = null;

    public function set(Company $company): void
    {
        $this->company = $company;
    }

    public function clear(): void
    {
        $this->company = null;
    }

    public function has(): bool
    {
        return $this->company !== null;
    }

    public function get(): Company
    {
        return $this->company ?? throw new MissingCompanyContext();
    }

    public function id(): int
    {
        return $this->get()->id;
    }

    public function runFor(Company $company, Closure $callback): mixed
    {
        $previous = $this->company;
        $this->set($company);
        try {
            return $callback();
        } finally {
            $this->company = $previous;
        }
    }
}
```

`AppServiceProvider::register()`: `$this->app->scoped(CompanyContext::class);`

```php
<?php // app/Http/Middleware/SetCompanyContext.php
namespace App\Http\Middleware;

use App\Support\CompanyContext;
use Closure;
use Illuminate\Http\Request;

class SetCompanyContext
{
    public function __construct(private CompanyContext $context) {}

    public function handle(Request $request, Closure $next)
    {
        $user = $request->user();
        if ($user) {
            $company = $user->currentCompany;
            if (! $company || ! $user->belongsToCompany($company)) {
                $company = $user->companies()->first();
                $user->forceFill(['current_company_id' => $company?->id])->save();
            }
            if ($company) {
                $this->context->set($company);
            }
        }
        return $next($request);
    }
}
```

Append to the `web` middleware group in `bootstrap/app.php`: `->withMiddleware(fn ($m) => $m->web(append: [\App\Http\Middleware\SetCompanyContext::class]))` (merge into existing callback).

```php
<?php // app/Http/Controllers/CompanySwitchController.php
namespace App\Http\Controllers;

use App\Models\Company;
use Illuminate\Http\Request;

class CompanySwitchController extends Controller
{
    public function __invoke(Request $request)
    {
        $validated = $request->validate(['company_id' => ['required', 'integer']]);
        $company = Company::query()->findOrFail($validated['company_id']);
        abort_unless($request->user()->belongsToCompany($company), 403);
        $request->user()->forceFill(['current_company_id' => $company->id])->save();
        return back();
    }
}
```

Route (auth group in `routes/web.php`): `Route::post('/company/switch', CompanySwitchController::class)->name('company.switch');`

- [ ] **Step 4: Run** → PASS (all 5).
- [ ] **Step 5: Commit** — `git commit -m "feat: request-scoped company context, middleware, switcher"`

---

### Task 4: BelongsToCompany trait + scoped route binding

**Files:**
- Create: `app/Models/Concerns/BelongsToCompany.php`
- Test: `tests/Feature/BelongsToCompanyTest.php` (uses a test-only table)

**Interfaces:**
- Produces: `BelongsToCompany` trait — global scope filtering by `CompanyContext::id()` (throws `MissingCompanyContext` when unset), auto-fills `company_id` on create, `company(): BelongsTo`. All later tenant models use it. Route-model binding of a scoped model from another company must 404 (the global scope guarantees this — asserted here once for all).

- [ ] **Step 1: Write failing tests**

```php
<?php // tests/Feature/BelongsToCompanyTest.php
namespace Tests\Feature;

use App\Exceptions\MissingCompanyContext;
use App\Models\Company;
use App\Models\Concerns\BelongsToCompany;
use App\Support\CompanyContext;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class TenantWidget extends Model
{
    use BelongsToCompany;
    protected $guarded = [];
    protected $table = 'tenant_widgets';
}

class BelongsToCompanyTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Schema::create('tenant_widgets', function (Blueprint $t) {
            $t->id();
            $t->foreignId('company_id')->constrained();
            $t->string('name');
            $t->timestamps();
        });
    }

    public function test_query_without_context_throws(): void
    {
        $this->expectException(MissingCompanyContext::class);
        TenantWidget::query()->count();
    }

    public function test_creating_fills_company_id_and_queries_are_scoped(): void
    {
        [$a, $b] = Company::factory()->count(2)->create();
        $ctx = app(CompanyContext::class);

        $ctx->set($a);
        $widgetA = TenantWidget::query()->create(['name' => 'A thing']);
        $this->assertSame($a->id, $widgetA->company_id);

        $ctx->runFor($b, fn () => TenantWidget::query()->create(['name' => 'B thing']));

        $this->assertSame(1, TenantWidget::query()->count());
        $this->assertSame('A thing', TenantWidget::query()->sole()->name);
    }

    public function test_route_binding_cross_company_is_404(): void
    {
        [$a, $b] = Company::factory()->count(2)->create();
        $ctx = app(CompanyContext::class);
        $foreign = $ctx->runFor($b, fn () => TenantWidget::query()->create(['name' => 'B thing']));

        Route::middleware('web')->get('/_test/widgets/{widget}', fn (TenantWidget $widget) => $widget->name);

        $ctx->set($a);
        $this->get('/_test/widgets/'.$foreign->id)->assertNotFound();
    }
}
```

- [ ] **Step 2: Run** → FAIL (trait not found).
- [ ] **Step 3: Implement**

```php
<?php // app/Models/Concerns/BelongsToCompany.php
namespace App\Models\Concerns;

use App\Models\Company;
use App\Support\CompanyContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

trait BelongsToCompany
{
    public static function bootBelongsToCompany(): void
    {
        static::addGlobalScope('company', function (Builder $query) {
            $query->where(
                $query->getModel()->getTable().'.company_id',
                app(CompanyContext::class)->id(),
            );
        });

        static::creating(function (Model $model) {
            $model->company_id ??= app(CompanyContext::class)->id();
        });
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }
}
```

- [ ] **Step 4: Run** → PASS (3 tests).
- [ ] **Step 5: Commit** — `git commit -m "feat: BelongsToCompany tenant scope with loud missing-context failure"`

---

### Task 5: Permission matrix (§7.14) as config + gates

**Files:**
- Modify: `config/permissions.php` (full matrix), `app/Providers/AppServiceProvider.php` (gate registration)
- Test: `tests/Feature/PermissionMatrixTest.php`

**Interfaces:**
- Produces: `config('permissions.catalog')` — the definitive permission list; `Gate::allows('<permission>')` works for the authenticated user against their **current-context** company role. Gate names are exactly the §7.14 keys. Later phases add `Gate::authorize('invoices.send')` etc.

- [ ] **Step 1: Write the failing test** — the matrix below is copied from spec §7.14; the test asserts *every* cell:

```php
<?php // tests/Feature/PermissionMatrixTest.php
namespace Tests\Feature;

use App\Enums\Role;
use App\Models\Company;
use App\Models\User;
use App\Support\CompanyContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Tests\TestCase;

class PermissionMatrixTest extends TestCase
{
    use RefreshDatabase;

    /** Spec §7.14 — role => permissions it holds. */
    private const MATRIX = [
        'owner' => ['invoices.draft', 'invoices.send', 'invoices.void', 'payments.record', 'expenses.draft', 'bills.draft', 'expenses.record', 'bills.receive', 'expenses.void', 'bills.void', 'transfers.create', 'other_receipts.create', 'contacts.manage', 'items.manage', 'tax_rates.manage', 'accounts.manage', 'bank_accounts.manage', 'reconciliation.manage', 'ledger.post_manual', 'periods.lock', 'reports.view', 'audit.view', 'exports.run', 'settings.manage', 'users.manage', 'company.billing', 'company.delete'],
        'admin' => ['invoices.draft', 'invoices.send', 'invoices.void', 'payments.record', 'expenses.draft', 'bills.draft', 'expenses.record', 'bills.receive', 'expenses.void', 'bills.void', 'transfers.create', 'other_receipts.create', 'contacts.manage', 'items.manage', 'tax_rates.manage', 'accounts.manage', 'bank_accounts.manage', 'reports.view', 'exports.run', 'settings.manage', 'users.manage'],
        'accountant' => ['invoices.draft', 'invoices.send', 'invoices.void', 'payments.record', 'expenses.draft', 'bills.draft', 'expenses.record', 'bills.receive', 'expenses.void', 'bills.void', 'transfers.create', 'other_receipts.create', 'contacts.manage', 'items.manage', 'tax_rates.manage', 'accounts.manage', 'bank_accounts.manage', 'reconciliation.manage', 'ledger.post_manual', 'periods.lock', 'reports.view', 'audit.view', 'exports.run'],
        'staff' => ['invoices.draft', 'expenses.draft', 'bills.draft'],
    ];

    public function test_every_matrix_cell_is_enforced(): void
    {
        $company = Company::factory()->create();
        $catalog = config('permissions.catalog');
        $this->assertNotEmpty($catalog);

        foreach (self::MATRIX as $role => $granted) {
            $user = User::factory()->create();
            $user->companies()->attach($company, ['role' => $role]);
            $this->actingAs($user);
            app(CompanyContext::class)->set($company);

            foreach ($catalog as $permission) {
                $expected = in_array($permission, $granted, true);
                $this->assertSame($expected, Gate::forUser($user)->allows($permission),
                    "role={$role} permission={$permission} expected ".var_export($expected, true));
            }
        }
    }

    public function test_non_member_is_denied_everything(): void
    {
        $company = Company::factory()->create();
        $outsider = User::factory()->create();
        $this->actingAs($outsider);
        app(CompanyContext::class)->set($company);

        foreach (config('permissions.catalog') as $permission) {
            $this->assertFalse(Gate::forUser($outsider)->allows($permission));
        }
    }
}
```

- [ ] **Step 2: Run** → FAIL (catalog empty).
- [ ] **Step 3: Implement** — `config/permissions.php` with catalog + per-role lists **identical to the test matrix above** (owner = full catalog), and gate registration in `AppServiceProvider::boot()`:

```php
use App\Support\CompanyContext;
use Illuminate\Support\Facades\Gate;

foreach (config('permissions.catalog') as $permission) {
    Gate::define($permission, function ($user) use ($permission) {
        $context = app(CompanyContext::class);
        if (! $context->has()) {
            return false;
        }
        return $user->roleIn($context->get())?->allows($permission) ?? false;
    });
}
```

- [ ] **Step 4: Run** → PASS (both tests; 4×27 + outsider cells asserted).
- [ ] **Step 5: Commit** — `git commit -m "feat: §7.14 permission matrix as config-backed gates"`

---

### Task 6: Audit log plumbing (append-only)

**Files:**
- Create: `database/migrations/xxxx_create_activity_log_table.php`, `app/Models/ActivityLog.php`, `app/Support/AuditLogger.php`
- Test: `tests/Feature/AuditLogTest.php`

**Interfaces:**
- Produces: `AuditLogger::log(string $action, ?Model $subject = null, ?array $before = null, ?array $after = null): ActivityLog` (resolve via container). Rows carry company (via `BelongsToCompany`), user, ip. `ActivityLog` throws `LogicException` on update/delete.

- [ ] **Step 1: Write failing tests**

```php
<?php // tests/Feature/AuditLogTest.php
namespace Tests\Feature;

use App\Models\ActivityLog;
use App\Models\Company;
use App\Models\User;
use App\Support\AuditLogger;
use App\Support\CompanyContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AuditLogTest extends TestCase
{
    use RefreshDatabase;

    public function test_log_records_actor_company_and_diff(): void
    {
        $company = Company::factory()->create();
        $user = User::factory()->create();
        $this->actingAs($user);
        app(CompanyContext::class)->set($company);

        $row = app(AuditLogger::class)->log('company.updated', $company,
            before: ['name' => 'Old'], after: ['name' => 'New']);

        $this->assertSame($company->id, $row->company_id);
        $this->assertSame($user->id, $row->user_id);
        $this->assertSame('company.updated', $row->action);
        $this->assertSame(['name' => 'Old'], $row->before);
        $this->assertSame(['name' => 'New'], $row->after);
        $this->assertSame(Company::class, $row->subject_type);
    }

    public function test_rows_are_immutable(): void
    {
        app(CompanyContext::class)->set(Company::factory()->create());
        $row = app(AuditLogger::class)->log('x');
        $this->expectException(\LogicException::class);
        $row->update(['action' => 'tampered']);
    }

    public function test_rows_cannot_be_deleted(): void
    {
        app(CompanyContext::class)->set(Company::factory()->create());
        $row = app(AuditLogger::class)->log('x');
        $this->expectException(\LogicException::class);
        $row->delete();
    }
}
```

- [ ] **Step 2: Run** → FAIL.
- [ ] **Step 3: Implement**

```php
<?php // migration
Schema::create('activity_log', function (Blueprint $t) {
    $t->id();
    $t->foreignId('company_id')->constrained()->restrictOnDelete();
    $t->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
    $t->string('action', 100)->index();
    $t->nullableMorphs('subject');
    $t->json('before')->nullable();
    $t->json('after')->nullable();
    $t->string('ip', 45)->nullable();
    $t->timestamp('created_at')->useCurrent();
});
```

```php
<?php // app/Models/ActivityLog.php
namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;

class ActivityLog extends Model
{
    use BelongsToCompany;

    public const UPDATED_AT = null;
    protected $table = 'activity_log';
    protected $guarded = [];

    protected function casts(): array
    {
        return ['before' => 'array', 'after' => 'array'];
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new \LogicException('Audit log rows are immutable.'));
        static::deleting(fn () => throw new \LogicException('Audit log rows are immutable.'));
    }
}
```

```php
<?php // app/Support/AuditLogger.php
namespace App\Support;

use App\Models\ActivityLog;
use Illuminate\Database\Eloquent\Model;

class AuditLogger
{
    public function log(string $action, ?Model $subject = null, ?array $before = null, ?array $after = null): ActivityLog
    {
        return ActivityLog::query()->create([
            'user_id' => auth()->id(),
            'action' => $action,
            'subject_type' => $subject?->getMorphClass(),
            'subject_id' => $subject?->getKey(),
            'before' => $before,
            'after' => $after,
            'ip' => request()?->ip(),
        ]);
    }
}
```

- [ ] **Step 4: Run** → PASS.
- [ ] **Step 5: Commit** — `git commit -m "feat: append-only audit log with AuditLogger service"`

---

### Task 7: Idempotency plumbing (§17.2 layers 2–3 groundwork)

**Files:**
- Create: `database/migrations/xxxx_create_idempotency_keys_table.php`, `app/Models/IdempotencyKey.php`, `app/Support/IdempotencyService.php`
- Test: `tests/Feature/IdempotencyTest.php`

**Interfaces:**
- Produces: `IdempotencyService::execute(string $key, string $action, Closure $operation): mixed` — runs `$operation` inside a DB transaction exactly once per (company, key); replays the stored array/scalar snapshot on repeats. Every posting service in later phases wraps its work in this.

- [ ] **Step 1: Write failing tests**

```php
<?php // tests/Feature/IdempotencyTest.php
namespace Tests\Feature;

use App\Models\Company;
use App\Support\CompanyContext;
use App\Support\IdempotencyService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class IdempotencyTest extends TestCase
{
    use RefreshDatabase;

    public function test_operation_runs_once_and_replays_snapshot(): void
    {
        app(CompanyContext::class)->set(Company::factory()->create());
        $service = app(IdempotencyService::class);
        $key = (string) Str::uuid();
        $runs = 0;

        $first = $service->execute($key, 'invoice.send', function () use (&$runs) {
            $runs++;
            return ['invoice_id' => 42];
        });
        $second = $service->execute($key, 'invoice.send', function () use (&$runs) {
            $runs++;
            return ['invoice_id' => 99];
        });

        $this->assertSame(1, $runs);
        $this->assertSame(['invoice_id' => 42], $first);
        $this->assertSame(['invoice_id' => 42], $second);
    }

    public function test_failed_operation_releases_the_key(): void
    {
        app(CompanyContext::class)->set(Company::factory()->create());
        $service = app(IdempotencyService::class);
        $key = (string) Str::uuid();

        try {
            $service->execute($key, 'invoice.send', fn () => throw new \RuntimeException('boom'));
        } catch (\RuntimeException) {
        }

        $result = $service->execute($key, 'invoice.send', fn () => ['ok' => true]);
        $this->assertSame(['ok' => true], $result);
    }

    public function test_same_key_in_different_companies_is_independent(): void
    {
        [$a, $b] = Company::factory()->count(2)->create();
        $service = app(IdempotencyService::class);
        $ctx = app(CompanyContext::class);
        $key = (string) Str::uuid();

        $ctx->set($a);
        $ra = $service->execute($key, 'x', fn () => 'from-a');
        $rb = $ctx->runFor($b, fn () => $service->execute($key, 'x', fn () => 'from-b'));

        $this->assertSame('from-a', $ra);
        $this->assertSame('from-b', $rb);
    }
}
```

- [ ] **Step 2: Run** → FAIL.
- [ ] **Step 3: Implement**

```php
<?php // migration
Schema::create('idempotency_keys', function (Blueprint $t) {
    $t->id();
    $t->foreignId('company_id')->constrained()->restrictOnDelete();
    $t->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
    $t->uuid('key');
    $t->string('action', 100);
    $t->json('response_snapshot')->nullable();
    $t->timestamp('created_at')->useCurrent();
    $t->unique(['company_id', 'key']);
});
```

```php
<?php // app/Models/IdempotencyKey.php
namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;

class IdempotencyKey extends Model
{
    use BelongsToCompany;

    public const UPDATED_AT = null;
    protected $guarded = [];

    protected function casts(): array
    {
        return ['response_snapshot' => 'array'];
    }
}
```

```php
<?php // app/Support/IdempotencyService.php
namespace App\Support;

use App\Models\IdempotencyKey;
use Closure;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

class IdempotencyService
{
    public function execute(string $key, string $action, Closure $operation): mixed
    {
        $existing = IdempotencyKey::query()->where('key', $key)->first();
        if ($existing) {
            return $existing->response_snapshot;
        }

        try {
            return DB::transaction(function () use ($key, $action, $operation) {
                $record = IdempotencyKey::query()->create([
                    'key' => $key,
                    'action' => $action,
                    'user_id' => auth()->id(),
                ]);
                $result = $operation();
                $record->forceFill(['response_snapshot' => $result])->save();
                return $result;
            });
        } catch (UniqueConstraintViolationException) {
            // Lost a race with a concurrent identical request — replay its result.
            return IdempotencyKey::query()->where('key', $key)->firstOrFail()->response_snapshot;
        }
    }
}
```

- [ ] **Step 4: Run** → PASS.
- [ ] **Step 5: Commit** — `git commit -m "feat: idempotency key service — exactly-once posting operations"`

---

### Task 8: Onboarding — company creation after registration

**Files:**
- Create: `app/Http/Controllers/OnboardingController.php`, `app/Services/CompanySetupService.php`, `app/Http/Requests/StoreCompanyRequest.php`, `app/Support/Currencies.php`, `resources/js/Pages/Onboarding/CreateCompany.tsx`, `app/Http/Middleware/RequireCompany.php`
- Modify: `routes/web.php`
- Test: `tests/Feature/OnboardingTest.php`

**Interfaces:**
- Produces: `CompanySetupService::create(User $user, array $data): Company` — creates company, attaches Owner, sets `current_company_id`, audit-logs `company.created`, and calls `$this->seedDefaults($company)` (empty hook; Phase 1 fills it with the §5 chart of accounts). Routes: `GET/POST /onboarding/company` (`onboarding.company`). `RequireCompany` middleware alias `company` — redirects to onboarding when the user has no company (protects dashboard + all tenant screens). `Currencies::COMMON` — `['PKR','USD','EUR','GBP','AED','SAR','INR','CAD','AUD']`.

- [ ] **Step 1: Write failing tests**

```php
<?php // tests/Feature/OnboardingTest.php
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
}
```

- [ ] **Step 2: Run** → FAIL.
- [ ] **Step 3: Implement**

```php
<?php // app/Support/Currencies.php
namespace App\Support;

class Currencies
{
    public const COMMON = ['PKR', 'USD', 'EUR', 'GBP', 'AED', 'SAR', 'INR', 'CAD', 'AUD'];
}
```

```php
<?php // app/Http/Requests/StoreCompanyRequest.php
namespace App\Http\Requests;

use App\Support\Currencies;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreCompanyRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:120'],
            'base_currency' => ['required', Rule::in(Currencies::COMMON)],
            'fiscal_year_start_month' => ['required', 'integer', 'between:1,12'],
        ];
    }
}
```

```php
<?php // app/Services/CompanySetupService.php
namespace App\Services;

use App\Enums\Role;
use App\Models\Company;
use App\Models\User;
use App\Support\AuditLogger;
use App\Support\CompanyContext;
use Illuminate\Support\Facades\DB;

class CompanySetupService
{
    public function __construct(
        private CompanyContext $context,
        private AuditLogger $audit,
    ) {}

    public function create(User $user, array $data): Company
    {
        return DB::transaction(function () use ($user, $data) {
            $company = Company::query()->create($data);
            $user->companies()->attach($company, ['role' => Role::Owner->value]);
            $user->forceFill(['current_company_id' => $company->id])->save();

            return $this->context->runFor($company, function () use ($company) {
                $this->seedDefaults($company);
                $this->audit->log('company.created', $company, after: ['name' => $company->name]);
                return $company;
            });
        });
    }

    protected function seedDefaults(Company $company): void
    {
        // Phase 1 seeds the §5 chart of accounts here.
    }
}
```

```php
<?php // app/Http/Middleware/RequireCompany.php
namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

class RequireCompany
{
    public function handle(Request $request, Closure $next)
    {
        if ($request->user() && ! $request->user()->companies()->exists()) {
            return redirect()->route('onboarding.company');
        }
        return $next($request);
    }
}
```

```php
<?php // app/Http/Controllers/OnboardingController.php
namespace App\Http\Controllers;

use App\Http\Requests\StoreCompanyRequest;
use App\Services\CompanySetupService;
use App\Support\Currencies;
use Inertia\Inertia;

class OnboardingController extends Controller
{
    public function create()
    {
        return Inertia::render('Onboarding/CreateCompany', ['currencies' => Currencies::COMMON]);
    }

    public function store(StoreCompanyRequest $request, CompanySetupService $setup)
    {
        $setup->create($request->user(), $request->validated());
        return redirect()->route('dashboard');
    }
}
```

Routes: onboarding under `auth` only; dashboard (and all future tenant routes) under `['auth', RequireCompany::class]`. Register the alias `company` in `bootstrap/app.php`. `CreateCompany.tsx`: minimal Inertia form (name, currency select, fiscal month select) posting to `onboarding.company` — functional Breeze-style markup; the real visual design system arrives with Phase 1's app shell.

- [ ] **Step 4: Run** → PASS.
- [ ] **Step 5: Commit** — `git commit -m "feat: onboarding — company creation, owner attach, seeding hook"`

---

### Task 9: Monitoring hook, CI workflow, dev scripts

**Files:**
- Create: `.github/workflows/ci.yml`
- Modify: `composer.json` (scripts), `bootstrap/app.php` (Sentry integration), `.env.example`
- Test: full suite + `vendor/bin/pint --test`

**Interfaces:**
- Produces: `composer test` (artisan test), `composer lint` (pint), Sentry reporting active when `SENTRY_LARAVEL_DSN` is set (empty locally = disabled; DSN wired at Phase 6 per §17.9), CI workflow (php 8.3 + mysql service) ready for when a remote exists.

- [ ] **Step 1: Install and wire Sentry (env-gated)**

```bash
composer require sentry/sentry-laravel
php artisan sentry:publish --without-dsn 2>/dev/null || true
```
`bootstrap/app.php` exceptions callback: `->withExceptions(fn ($e) => \Sentry\Laravel\Integration::handles($e))`. Add `SENTRY_LARAVEL_DSN=` to `.env.example` and `.env` (empty).

- [ ] **Step 2: composer scripts** — add to `composer.json`: `"test": "php artisan test"`, `"lint": "vendor/bin/pint"`, `"lint:check": "vendor/bin/pint --test"`.

- [ ] **Step 3: CI workflow**

```yaml
# .github/workflows/ci.yml
name: CI
on: [push, pull_request]
jobs:
  tests:
    runs-on: ubuntu-latest
    services:
      mysql:
        image: mysql:8.4
        env: { MYSQL_ALLOW_EMPTY_PASSWORD: "yes", MYSQL_DATABASE: sparkbooks_test }
        ports: ["3306:3306"]
        options: --health-cmd="mysqladmin ping" --health-interval=10s --health-timeout=5s --health-retries=5
    steps:
      - uses: actions/checkout@v4
      - uses: shivammathur/setup-php@v2
        with: { php-version: "8.3", extensions: "mbstring, pdo_mysql" }
      - run: composer install --prefer-dist --no-progress
      - run: cp .env.example .env && php artisan key:generate
      - uses: actions/setup-node@v4
        with: { node-version: "22" }
      - run: npm ci && npm run build
      - run: php artisan test
        env: { DB_CONNECTION: mysql, DB_HOST: 127.0.0.1, DB_DATABASE: sparkbooks_test, DB_USERNAME: root, DB_PASSWORD: "" }
      - run: vendor/bin/pint --test
```

- [ ] **Step 4: Verify** — `composer test` → all green; `composer lint:check` → clean (run `composer lint` once to normalize the scaffold).
- [ ] **Step 5: Commit** — `git commit -m "chore: sentry hook, CI workflow, composer scripts"`

---

### Task 10: Phase 0 exit verification (browser + suite)

**Files:** none (verification only; fix anything found, then commit fixes)

- [ ] **Step 1:** `php artisan test` — entire suite green, zero risky/skipped.
- [ ] **Step 2:** Browser-drive with preview tools against `php artisan serve` (or Laragon vhost): register a fresh user → land on onboarding → create "SparkingAsia" (PKR, fiscal month July) → reach dashboard. Register a second user + company; verify the first user cannot see anything of the second (there are no shared screens yet — assert the switcher rejects the foreign company).
- [ ] **Step 3:** Confirm Phase 0 exit criteria from spec §13: tests green ✓, two companies fully isolated ✓ (BelongsToCompanyTest + CompanyContextTest), permission-matrix tests assert §7.14 ✓, constraint + idempotency tests green ✓.
- [ ] **Step 4:** Update the plan file checkboxes, commit any fixes: `git commit -m "test: phase 0 exit verification"`.

---

## Self-Review Notes

- **Spec coverage (Phase 0 row, §13):** scaffold ✓ (T1), auth ✓ (Breeze, T1), company setup + seeding hook ✓ (T8), switcher/context ✓ (T3), roles/permissions table ✓ (T5), audit-log plumbing ✓ (T6), idempotency ✓ (T7), DB constraints on Phase-0 tables ✓ (unique membership, unique idempotency key, FKs restrictOnDelete — T2/T6/T7; ledger CHECK constraints belong to Phase 1's tables), error monitoring wired ✓ (T9), CI ✓ (T9). Exit criteria mapped in T10.
- **Type consistency:** `CompanyContext` API used identically in T3–T8; `Role` enum from T2 used in T5/T8; `AuditLogger::log()` signature consistent T6/T8.
- **Deliberately deferred:** §7.14 Admin no-escalation rule ships with the user-management screens (later phase, where invites/role-assignment exist); §17.2 `entry_role` unique index ships with `journal_entries` in Phase 1; §12.4 automated per-endpoint isolation sweep starts when resource endpoints exist (Phase 1+) — the mechanism (scoped binding → 404) is proven in T4.
