<?php

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
        app(ChartOfAccountsSeeder::class)->seed($company);
    }
}
