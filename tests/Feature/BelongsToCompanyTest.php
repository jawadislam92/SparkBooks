<?php

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
