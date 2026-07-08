<?php

namespace App\Services;

use App\Models\Account;
use App\Models\Company;
use App\Models\JournalEntry;
use App\Models\JournalLine;
use App\Models\ManualJournal;
use App\Support\CompanyContext;
use Illuminate\Support\Facades\DB;

/**
 * §9 correctness watchdog: verifies one company's ledger is internally
 * consistent. Every check runs inside a single CompanyContext::runFor +
 * DB::transaction so all four checks see one consistent snapshot of the
 * company's data, rather than four separate reads that could race against
 * concurrent posting.
 *
 * This class must never pass vacuously: an empty findings array means the
 * checks actually ran and found nothing wrong, not that the checks were
 * skipped or scoped to nothing. The caller (VerifyLedgerIntegrity) is
 * responsible for guarding against the latter — see its vacuous-scope check.
 */
class IntegrityChecker
{
    /** @return list<array{check:string, detail:string}> findings (empty = clean) */
    public function checkCompany(Company $company): array
    {
        return app(CompanyContext::class)->runFor($company, function () {
            return DB::transaction(function () {
                $findings = [];

                // (a) every journal entry balances: debits must equal credits
                // per entry, not just in aggregate.
                $unbalanced = JournalLine::query()
                    ->selectRaw('journal_entry_id, SUM(debit) AS d, SUM(credit) AS c')
                    ->groupBy('journal_entry_id')
                    ->havingRaw('SUM(debit) <> SUM(credit)')
                    ->get();
                foreach ($unbalanced as $row) {
                    $findings[] = ['check' => 'entry_balance', 'detail' => "Entry {$row->journal_entry_id} off by ".($row->d - $row->c)];
                }

                // (b) trial balance: the whole ledger must net to zero.
                $net = (int) JournalLine::query()->selectRaw('COALESCE(SUM(debit) - SUM(credit),0) AS n')->value('n');
                if ($net !== 0) {
                    $findings[] = ['check' => 'trial_balance', 'detail' => "Ledger net {$net} (should be 0)"];
                }

                // (f) every line posted to a receivable/payable control account
                // must carry a contact_id — otherwise AR/AP subledger reports
                // can't attribute the balance to anyone. Phase 2 added a real
                // FK on journal_lines.contact_id (composite on
                // (contact_id, company_id) -> contacts.(id, company_id)), so a
                // non-null contact_id is now guaranteed to reference a real,
                // same-company contact. That FK can't enforce NON-null,
                // though — a contact_id-less control-account line is still
                // valid at the DB level — so this check remains the only
                // guard against that specific gap.
                $missing = JournalLine::query()
                    ->whereIn('account_id', Account::query()->whereIn('subtype', ['receivable', 'payable'])->pluck('id'))
                    ->whereNull('contact_id')->count();
                if ($missing > 0) {
                    $findings[] = ['check' => 'control_contact', 'detail' => "{$missing} control-account line(s) missing contact"];
                }

                // (e-lite) every manual_journal-sourced ledger entry must
                // reference a manual journal document that still exists.
                $orphaned = JournalEntry::query()->where('source_type', 'manual_journal')
                    ->whereNotIn('source_id', ManualJournal::query()->pluck('id'))->count();
                if ($orphaned > 0) {
                    $findings[] = ['check' => 'orphan_entries', 'detail' => "{$orphaned} ledger entr(ies) reference missing manual journals"];
                }

                return $findings;
            });
        });
    }
}
