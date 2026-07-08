<?php

namespace App\Console\Commands;

use App\Models\Account;
use App\Models\Company;
use App\Services\ChartOfAccountsSeeder;
use App\Support\AuditLogger;
use App\Support\CompanyContext;
use Illuminate\Console\Command;

/**
 * One-off migration helper for Task 4 (spec §17.1 Path 2 FX-lite): companies
 * created before the FX Gain (4950) / FX Loss (5950) accounts existed don't
 * have them. Adds whichever of the two are missing, per company, and leaves
 * companies that already have both untouched. Safe to run repeatedly —
 * existence is checked before each create, so a re-run creates nothing new.
 *
 * NOT run automatically anywhere; the exit-verification task runs this once
 * against the dev DB by hand.
 */
class BackfillFxAccounts extends Command
{
    protected $signature = 'sparkbooks:backfill-fx-accounts';

    protected $description = 'Add the FX Gain (4950) / FX Loss (5950) accounts to companies seeded before Task 4 (idempotent)';

    public function handle(CompanyContext $context, AuditLogger $audit, ChartOfAccountsSeeder $seeder): int
    {
        $fxAccounts = $seeder->fxAccounts();
        $companies = Company::query()->get();

        foreach ($companies as $company) {
            $context->runFor($company, function () use ($company, $audit, $fxAccounts) {
                $added = [];

                foreach ($fxAccounts as $account) {
                    if (Account::query()->where('code', $account['code'])->exists()) {
                        continue;
                    }

                    Account::query()->create([
                        'code' => $account['code'],
                        'name' => $account['name'],
                        'type' => $account['subtype']->type()->value,
                        'subtype' => $account['subtype']->value,
                        'is_system' => $account['is_system'],
                    ]);

                    $added[] = $account['code'];
                }

                if ($added !== []) {
                    $audit->log('fx_accounts.backfilled', $company, after: ['codes' => $added]);
                }

                $alreadyPresent = count($fxAccounts) - count($added);
                $this->info("Company {$company->id} ({$company->name}): added ".count($added).' / already present '.$alreadyPresent);
            });
        }

        return self::SUCCESS;
    }
}
