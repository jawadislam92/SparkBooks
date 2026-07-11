<?php

namespace App\Console\Commands;

use App\Models\Company;
use App\Models\IntegrityRun;
use App\Models\JournalEntry;
use App\Services\IntegrityChecker;
use App\Support\AlertNotifier;
use App\Support\CompanyContext;
use App\Support\Heartbeat;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * §9 correctness watchdog, run daily (see routes/console.php) and on demand
 * from Settings > Health. Exits 0 only when every company was evaluated,
 * came back clean, and the vacuous-scope guard did not trip.
 *
 * The vacuous-scope guard is the whole point of this command: a checker
 * that reports "clean" because it silently queried nothing is worse than no
 * checker at all. For every company that has posted at least one manual
 * journal or ledger entry (determined by an UNSCOPED count against the raw
 * tables, independent of CompanyContext), we require that the SAME company,
 * queried through its own scoped context, also sees at least one journal
 * entry. If it doesn't, tenant scoping has silently failed for that company
 * and we fail loudly rather than report a false "clean".
 */
class VerifyLedgerIntegrity extends Command
{
    protected $signature = 'ledger:verify';

    protected $description = "Verify every company's ledger balances and is internally consistent (spec §9 correctness watchdog)";

    public function handle(IntegrityChecker $checker, CompanyContext $context, Heartbeat $heartbeat, AlertNotifier $alertNotifier): int
    {
        $companies = Company::query()->withoutGlobalScopes()->get();

        if ($companies->isEmpty()) {
            Log::error('ledger:verify: zero companies found — refusing to report success on zero evaluated companies (vacuous-check guard).');
            $this->error('No companies found. Nothing was evaluated — this is a failure, not a pass.');
            $alertNotifier->alert(
                'SparkBooks ledger:verify: zero companies evaluated',
                "ledger:verify ran and found zero companies to check — this is treated as a failure, not a vacuous pass.\nVerify the scheduled job is running against the expected database."
            );

            return self::FAILURE;
        }

        $anyFindings = false;
        $anyVacuous = false;
        $findingsSummary = [];

        foreach ($companies as $company) {
            // Ground truth, independent of CompanyContext: does this company
            // have any posted documents at all? A plain query-builder call
            // against the raw table, never scoped, so it can't be fooled by
            // whatever CompanyContext is doing.
            $hasDocuments = DB::table('manual_journals')->where('company_id', $company->id)->exists()
                || DB::table('journal_entries')->where('company_id', $company->id)->exists();

            $context->runFor($company, function () use ($company, $checker, $hasDocuments, &$anyFindings, &$anyVacuous, &$findingsSummary) {
                // What the checker's own scoping sees for this company.
                $entryCountInContext = JournalEntry::query()->count();

                $findings = $checker->checkCompany($company);

                if ($hasDocuments && $entryCountInContext === 0) {
                    $anyVacuous = true;
                    $findings[] = [
                        'check' => 'vacuous_scope',
                        'detail' => 'Company has posted documents but CompanyContext scoping returned zero journal entries — the check did not actually run against this company\'s data.',
                    ];
                    Log::error("ledger:verify: vacuous-scope guard tripped for company {$company->id} ({$company->name}).");
                    $this->error("Company {$company->id} ({$company->name}): VACUOUS SCOPE — has documents but zero visible journal entries.");
                }

                $clean = $findings === [];

                // company_id is deliberately NOT passed here — the
                // BelongsToCompany `creating` hook fills it from the
                // CompanyContext we're inside right now.
                IntegrityRun::query()->create([
                    'ran_at' => now(),
                    'findings' => $clean ? null : $findings,
                    'clean' => $clean,
                ]);

                if (! $clean) {
                    $anyFindings = true;
                    Log::error("ledger:verify: integrity findings for company {$company->id} ({$company->name}).", ['findings' => $findings]);
                    $this->error("Company {$company->id} ({$company->name}): FINDINGS");
                    foreach ($findings as $finding) {
                        $this->line("  - [{$finding['check']}] {$finding['detail']}");
                    }
                    $findingsSummary[] = "Company {$company->id} ({$company->name}): ".
                        collect($findings)->map(fn (array $f) => "[{$f['check']}] {$f['detail']}")->implode('; ');
                } else {
                    $this->info("Company {$company->id} ({$company->name}): clean");
                }
            });
        }

        if ($anyFindings || $anyVacuous) {
            $alertNotifier->alert(
                'SparkBooks ledger:verify: integrity findings detected',
                implode("\n", $findingsSummary) ?: 'ledger:verify found findings — see application logs for details.'
            );

            return self::FAILURE;
        }

        $heartbeat->ping('integrity');

        return self::SUCCESS;
    }
}
