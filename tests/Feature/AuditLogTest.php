<?php

namespace Tests\Feature;

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
