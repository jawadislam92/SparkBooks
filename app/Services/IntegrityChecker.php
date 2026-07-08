<?php

namespace App\Services;

use App\Models\Account;
use App\Models\Company;
use App\Models\JournalEntry;
use App\Models\JournalLine;
use App\Models\ManualJournal;
use App\Models\OpeningBalanceRun;
use App\Models\OtherReceipt;
use App\Models\Transfer;
use App\Support\CompanyContext;
use Illuminate\Support\Facades\DB;

/**
 * §9 correctness watchdog: verifies one company's ledger is internally
 * consistent. Every check runs inside a single CompanyContext::runFor +
 * DB::transaction so all checks see one consistent snapshot of the
 * company's data, rather than separate reads that could race against
 * concurrent posting.
 *
 * This class must never pass vacuously: an empty findings array means the
 * checks actually ran and found nothing wrong, not that the checks were
 * skipped or scoped to nothing. The caller (VerifyLedgerIntegrity) is
 * responsible for guarding against the latter — see its vacuous-scope check.
 */
class IntegrityChecker
{
    /**
     * Every ledger source_type this checker knows how to verify, mapped to
     * the model whose table holds the source document. Reversal entries are
     * NOT a source_type of their own — LedgerService::reverse() stamps a
     * reversal with the SAME source_type/source_id as the entry it reverses
     * (spec §4 iron rule), so they are covered by these same lookups without
     * any special-casing: the source row they point at is the original
     * document, which still exists.
     *
     * @var array<string, class-string<ManualJournal|Transfer|OtherReceipt|OpeningBalanceRun>>
     */
    private const SOURCE_TABLES = [
        'manual_journal' => ManualJournal::class,
        'transfer' => Transfer::class,
        'other_receipt' => OtherReceipt::class,
        'opening_balance_run' => OpeningBalanceRun::class,
    ];

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

                // (e) every ledger entry's source document must still exist.
                // Source-type-aware: for each source_type actually present on
                // this company's entries, look it up against the table
                // SOURCE_TABLES says it lives in. A source_type that ISN'T in
                // that map is not silently skipped — it is its own finding
                // (unrecognized_source), so a future source type someone
                // forgot to register here fails loud instead of quietly
                // passing every check.
                $sourceTypesPresent = JournalEntry::query()->distinct()->pluck('source_type');

                foreach ($sourceTypesPresent as $sourceType) {
                    if (! array_key_exists($sourceType, self::SOURCE_TABLES)) {
                        $count = JournalEntry::query()->where('source_type', $sourceType)->count();
                        $findings[] = ['check' => 'unrecognized_source', 'detail' => "{$count} ledger entr(ies) reference unrecognized source_type '{$sourceType}' (not registered in IntegrityChecker::SOURCE_TABLES)"];

                        continue;
                    }

                    $model = self::SOURCE_TABLES[$sourceType];
                    $orphaned = JournalEntry::query()->where('source_type', $sourceType)
                        ->whereNotIn('source_id', $model::query()->pluck('id'))->count();
                    if ($orphaned > 0) {
                        $findings[] = ['check' => 'orphan_entries', 'detail' => "{$orphaned} ledger entr(ies) reference missing {$sourceType}(s)"];
                    }
                }

                return $findings;
            });
        });
    }
}
