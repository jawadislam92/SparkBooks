<?php

namespace Tests\Feature;

use App\Domain\Ledger\EntryDraft;
use App\Domain\Ledger\LineDraft;
use App\Enums\AccountSubtype;
use App\Enums\AccountType;
use App\Exceptions\Accounts\AccountHasActiveChildren;
use App\Exceptions\Accounts\CrossTypeSubAccount;
use App\Exceptions\Accounts\InvalidSubtreeMove;
use App\Exceptions\Accounts\MaxDepthExceeded;
use App\Exceptions\Accounts\SystemAccountNotNestable;
use App\Exceptions\Ledger\NonPostableAccount;
use App\Models\Account;
use App\Models\ActivityLog;
use App\Models\Company;
use App\Models\JournalEntry;
use App\Models\JournalLine;
use App\Services\AccountService;
use App\Services\ChartOfAccountsSeeder;
use App\Services\LedgerService;
use App\Services\Reports\BalanceSheetReport;
use App\Services\Reports\TrialBalanceReport;
use App\Support\CompanyContext;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use LogicException;
use Tests\Support\AssertsLedgerBalanced;
use Tests\TestCase;

/**
 * Phase 7 Task 1 (sub-accounts / hierarchical chart of accounts).
 *
 * THE LOAD-BEARING INVARIANT: adding this feature is purely additive — it
 * must never change a posted balance. The "no sub-accounts" regression
 * block below and the "promotion preserves balances" assertions inside the
 * makeParent block are what prove that; every other block covers the new
 * structural behavior (creation, validation, subtree moves) on its own.
 */
class SubAccountsTest extends TestCase
{
    use AssertsLedgerBalanced;
    use RefreshDatabase;

    private function bankAccount(array $overrides = []): Account
    {
        return Account::factory()->create(array_merge([
            'code' => '1010',
            'type' => AccountType::Asset->value,
            'subtype' => AccountSubtype::Bank->value,
        ], $overrides));
    }

    private function incomeAccount(array $overrides = []): Account
    {
        return Account::factory()->create(array_merge([
            'code' => '4000',
            'type' => AccountType::Income->value,
            'subtype' => AccountSubtype::OperatingIncome->value,
        ], $overrides));
    }

    private function expenseAccount(array $overrides = []): Account
    {
        return Account::factory()->create(array_merge([
            'code' => '5000',
            'type' => AccountType::Expense->value,
            'subtype' => AccountSubtype::OperatingExpense->value,
        ], $overrides));
    }

    private function postEntry(Account $debitAccount, Account $creditAccount, int $amount, int $sourceId, string $date = '2026-07-01'): JournalEntry
    {
        return DB::transaction(fn () => app(LedgerService::class)->post(new EntryDraft(
            entryDate: CarbonImmutable::parse($date),
            memo: 'Fixture entry',
            sourceType: 'manual_journal',
            sourceId: $sourceId,
            lines: [
                new LineDraft(accountId: $debitAccount->id, debit: $amount, credit: 0),
                new LineDraft(accountId: $creditAccount->id, debit: 0, credit: $amount),
            ],
        )));
    }

    /** Plants the real seeded chart of accounts (with genuine is_system flags) into $company. */
    private function seedChart(Company $company): void
    {
        app(CompanyContext::class)->runFor($company, fn () => app(ChartOfAccountsSeeder::class)->seed($company));
    }

    // ---------------------------------------------------------------
    // Migration backfill invariant: every account a company has when it has
    // NEVER used sub-accounts is a top-level postable leaf, and the numbers
    // it reports are unaffected by the new columns existing at all.
    // ---------------------------------------------------------------

    public function test_seeded_chart_of_accounts_is_entirely_top_level_postable_leaves(): void
    {
        $company = Company::factory()->create();
        app(CompanyContext::class)->runFor($company, fn () => app(ChartOfAccountsSeeder::class)->seed($company));
        app(CompanyContext::class)->set($company);

        $accounts = Account::query()->get();
        $this->assertSame(25, $accounts->count());

        foreach ($accounts as $account) {
            $this->assertNull($account->parent_id, "expected {$account->code} to have no parent");
            $this->assertTrue($account->is_postable, "expected {$account->code} to be postable");
            $this->assertSame(0, $account->depth, "expected {$account->code} to be depth 0");
            $this->assertSame("/{$account->id}/", $account->path, "expected {$account->code}'s path to be its own top-level segment");
        }
    }

    public function test_a_company_with_no_sub_accounts_has_unchanged_trial_balance_totals(): void
    {
        $company = Company::factory()->create();
        app(CompanyContext::class)->set($company);
        $bank = $this->bankAccount();
        $income = $this->incomeAccount();
        $expense = $this->expenseAccount();

        $this->postEntry($bank, $income, 50000, 1);
        DB::transaction(fn () => app(LedgerService::class)->post(new EntryDraft(
            entryDate: CarbonImmutable::parse('2026-07-02'),
            memo: 'Expense paid from bank',
            sourceType: 'manual_journal',
            sourceId: 2,
            lines: [
                new LineDraft(accountId: $expense->id, debit: 20000, credit: 0),
                new LineDraft(accountId: $bank->id, debit: 0, credit: 20000),
            ],
        )));

        $tb = app(TrialBalanceReport::class)->build(CarbonImmutable::parse('2026-07-31'));

        // TrialBalanceReport totals are Σ of each account's NET presented
        // debit/credit (never raw transaction volume) — bank's net (50000 -
        // 20000 = 30000 debit) + expense's net (20000 debit) = 50000 total
        // debit; income's net (50000 credit) = 50000 total credit.
        $this->assertSame(50000, $tb['total_debit']);
        $this->assertSame(50000, $tb['total_credit']);

        $byCode = collect($tb['rows'])->keyBy('code');
        $this->assertSame(30000, $byCode[$bank->code]['debit']); // 50000 - 20000
        $this->assertSame(0, $byCode[$bank->code]['credit']);
        $this->assertSame(0, $byCode[$income->code]['debit']);
        $this->assertSame(50000, $byCode[$income->code]['credit']);
        $this->assertSame(20000, $byCode[$expense->code]['debit']);
        $this->assertSame(0, $byCode[$expense->code]['credit']);

        $bs = app(BalanceSheetReport::class)->build(CarbonImmutable::parse('2026-07-31'));
        $this->assertTrue($bs['is_balanced']);
        $this->assertSame(0, $bs['difference']);
        $this->assertLedgerBalances();
    }

    // ---------------------------------------------------------------
    // AccountService::createChild
    // ---------------------------------------------------------------

    public function test_create_child_under_a_never_posted_leaf_flips_parent_without_an_other_sibling(): void
    {
        $company = Company::factory()->create();
        app(CompanyContext::class)->set($company);
        $parent = $this->expenseAccount(['code' => '5400', 'name' => 'Software & Subscriptions']);

        $child = app(AccountService::class)->createChild($parent, [
            'code' => '5401', 'name' => 'Design Tools', 'subtype' => AccountSubtype::OperatingExpense,
        ]);

        $parent->refresh();
        $this->assertFalse($parent->is_postable);
        $this->assertSame(0, $parent->depth);

        $this->assertTrue($child->is_postable);
        $this->assertSame($parent->id, $child->parent_id);
        $this->assertSame(1, $child->depth);
        $this->assertSame($parent->path.$child->id.'/', $child->path);
        $this->assertSame(AccountType::Expense, $child->type);
        $this->assertSame(AccountSubtype::OperatingExpense, $child->subtype);

        // No auto "— Other" sibling: the parent had zero postings, nothing to preserve.
        $this->assertSame(1, $parent->children()->count());
    }

    public function test_create_child_under_a_posted_leaf_creates_other_sibling_and_moves_lines(): void
    {
        $company = Company::factory()->create();
        app(CompanyContext::class)->set($company);
        $bank = $this->bankAccount();
        $parent = $this->expenseAccount(['code' => '5400', 'name' => 'Software & Subscriptions']);

        $this->postEntry($parent, $bank, 15000, 1); // Dr Software 15000 / Cr Bank 15000
        $this->assertLedgerBalances();

        $child = app(AccountService::class)->createChild($parent, [
            'code' => '5401', 'name' => 'Design Tools', 'subtype' => AccountSubtype::OperatingExpense,
        ]);

        $parent->refresh();
        $this->assertFalse($parent->is_postable);
        $this->assertSame(2, $parent->children()->count());

        $otherChild = $parent->children()->where('name', 'Software & Subscriptions — Other')->sole();
        $this->assertTrue($otherChild->is_postable);
        $this->assertSame(15000, (int) JournalLine::query()->where('account_id', $otherChild->id)->sum('debit'));
        $this->assertSame(0, JournalLine::query()->where('account_id', $parent->id)->count());
        $this->assertSame($child->id, Account::query()->where('code', '5401')->sole()->id);

        $this->assertLedgerBalances();
    }

    public function test_create_child_rejects_cross_type_subtype(): void
    {
        $company = Company::factory()->create();
        app(CompanyContext::class)->set($company);
        $parent = $this->expenseAccount();

        $this->expectException(CrossTypeSubAccount::class);
        app(AccountService::class)->createChild($parent, [
            'code' => '1600', 'name' => 'Bad Child', 'subtype' => AccountSubtype::FixedAsset,
        ]);
    }

    public function test_create_child_rejects_past_max_depth(): void
    {
        $company = Company::factory()->create();
        app(CompanyContext::class)->set($company);
        $account = $this->expenseAccount(['code' => '5000']);
        $service = app(AccountService::class);

        // Build a chain 5 levels deep (depth 0 -> 1 -> 2 -> 3 -> 4 -> 5), each
        // step consuming the current deepest leaf's only child slot.
        for ($i = 1; $i <= AccountService::MAX_DEPTH; $i++) {
            $account = $service->createChild($account, [
                'code' => "50{$i}0", 'name' => "Level {$i}", 'subtype' => AccountSubtype::OperatingExpense,
            ]);
        }
        $this->assertSame(AccountService::MAX_DEPTH, $account->depth);

        $this->expectException(MaxDepthExceeded::class);
        $service->createChild($account, [
            'code' => '5999', 'name' => 'Too Deep', 'subtype' => AccountSubtype::OperatingExpense,
        ]);
    }

    public function test_create_child_under_an_already_promoted_parent_does_not_touch_existing_children(): void
    {
        $company = Company::factory()->create();
        app(CompanyContext::class)->set($company);
        $parent = $this->expenseAccount(['code' => '5400', 'name' => 'Software']);
        $service = app(AccountService::class);

        $first = $service->createChild($parent, ['code' => '5401', 'name' => 'First', 'subtype' => AccountSubtype::OperatingExpense]);
        $second = $service->createChild($parent, ['code' => '5402', 'name' => 'Second', 'subtype' => AccountSubtype::OperatingExpense]);

        $parent->refresh();
        $this->assertSame(2, $parent->children()->count());
        $this->assertSame(1, $first->fresh()->depth);
        $this->assertSame(1, $second->depth);
    }

    // ---------------------------------------------------------------
    // AccountService::makeParent
    // ---------------------------------------------------------------

    public function test_make_parent_on_posted_leaf_creates_other_child_moves_lines_and_audits(): void
    {
        $company = Company::factory()->create();
        app(CompanyContext::class)->set($company);
        $bank = $this->bankAccount();
        $leaf = $this->expenseAccount(['code' => '5900', 'name' => 'Professional Fees']);

        $this->postEntry($leaf, $bank, 42000, 1);
        $balanceBefore = (int) JournalLine::query()->where('account_id', $leaf->id)->sum('debit')
            - (int) JournalLine::query()->where('account_id', $leaf->id)->sum('credit');

        $promoted = app(AccountService::class)->makeParent($leaf);

        $this->assertFalse($promoted->is_postable);
        $other = $promoted->children()->sole();
        $this->assertSame('Professional Fees — Other', $other->name);
        $this->assertTrue($other->is_postable);
        $this->assertSame(AccountType::Expense, $other->type);
        $this->assertSame(AccountSubtype::OperatingExpense, $other->subtype);

        // Balances unchanged: nothing left on the old leaf, everything on the new child.
        $this->assertSame(0, JournalLine::query()->where('account_id', $leaf->id)->count());
        $balanceAfter = (int) JournalLine::query()->where('account_id', $other->id)->sum('debit')
            - (int) JournalLine::query()->where('account_id', $other->id)->sum('credit');
        $this->assertSame($balanceBefore, $balanceAfter);
        $this->assertSame(42000, $balanceAfter);
        $this->assertLedgerBalances();

        $audit = ActivityLog::query()->where('action', 'account.promoted')->sole();
        $this->assertSame($leaf->id, $audit->subject_id);
        $this->assertSame(1, $audit->after['lines_moved']);
        $this->assertSame($other->id, $audit->after['other_child_id']);
        $this->assertTrue($audit->before['is_postable']);
        $this->assertFalse($audit->after['is_postable']);
    }

    public function test_make_parent_on_never_posted_leaf_flips_flag_without_creating_a_child(): void
    {
        $company = Company::factory()->create();
        app(CompanyContext::class)->set($company);
        $leaf = $this->expenseAccount();

        $promoted = app(AccountService::class)->makeParent($leaf);

        $this->assertFalse($promoted->is_postable);
        $this->assertSame(0, $promoted->children()->count());

        $audit = ActivityLog::query()->where('action', 'account.promoted')->sole();
        $this->assertNull($audit->after['other_child_id']);
        $this->assertSame(0, $audit->after['lines_moved']);
    }

    public function test_make_parent_on_an_already_promoted_account_is_a_no_op(): void
    {
        $company = Company::factory()->create();
        app(CompanyContext::class)->set($company);
        $leaf = $this->expenseAccount();
        app(AccountService::class)->makeParent($leaf);
        $this->assertSame(1, ActivityLog::query()->where('action', 'account.promoted')->count());

        $result = app(AccountService::class)->makeParent($leaf->fresh());

        $this->assertFalse($result->is_postable);
        // No second promotion audit row, no second child.
        $this->assertSame(1, ActivityLog::query()->where('action', 'account.promoted')->count());
        $this->assertSame(0, $result->children()->count());
    }

    // ---------------------------------------------------------------
    // Leaf-only enforcement of postings that predate/bypass createChild —
    // opening balances and every other document flow all funnel through
    // LedgerService::post, which is covered end-to-end in
    // tests/Feature/Ledger/LedgerServiceTest.php. Confirmed here too, from
    // the sub-account angle: a promoted parent immediately rejects a new
    // manual posting attempt.
    // ---------------------------------------------------------------

    public function test_promoted_parent_rejects_new_postings(): void
    {
        $company = Company::factory()->create();
        app(CompanyContext::class)->set($company);
        $bank = $this->bankAccount();
        $leaf = $this->expenseAccount();
        app(AccountService::class)->makeParent($leaf);

        $this->expectException(NonPostableAccount::class);
        $this->postEntry($leaf->fresh(), $bank, 1000, 99);
    }

    // ---------------------------------------------------------------
    // Archiving: blocked while active (non-archived) children exist.
    // ---------------------------------------------------------------

    public function test_archiving_a_parent_with_an_active_child_is_blocked(): void
    {
        $company = Company::factory()->create();
        app(CompanyContext::class)->set($company);
        $parent = $this->expenseAccount();
        app(AccountService::class)->createChild($parent, [
            'code' => '5991', 'name' => 'Child', 'subtype' => AccountSubtype::OperatingExpense,
        ]);

        $this->expectException(AccountHasActiveChildren::class);
        $parent->fresh()->update(['is_archived' => true]);
    }

    public function test_archiving_a_parent_after_its_only_child_is_archived_succeeds(): void
    {
        $company = Company::factory()->create();
        app(CompanyContext::class)->set($company);
        $parent = $this->expenseAccount();
        $child = app(AccountService::class)->createChild($parent, [
            'code' => '5991', 'name' => 'Child', 'subtype' => AccountSubtype::OperatingExpense,
        ]);

        $child->update(['is_archived' => true]);
        $parent->fresh()->update(['is_archived' => true]);

        $this->assertTrue($parent->fresh()->is_archived);
    }

    // ---------------------------------------------------------------
    // AccountService::moveSubtree
    // ---------------------------------------------------------------

    public function test_move_subtree_updates_path_and_depth_for_every_descendant(): void
    {
        $company = Company::factory()->create();
        app(CompanyContext::class)->set($company);
        $service = app(AccountService::class);

        $rootA = $this->expenseAccount(['code' => '5100', 'name' => 'Root A']);
        $rootD = $this->expenseAccount(['code' => '5200', 'name' => 'Root D']);
        $d1 = $service->createChild($rootD, ['code' => '5201', 'name' => 'D1', 'subtype' => AccountSubtype::OperatingExpense]);
        $b = $service->createChild($rootA, ['code' => '5101', 'name' => 'B', 'subtype' => AccountSubtype::OperatingExpense]);
        $c = $service->createChild($b, ['code' => '5102', 'name' => 'C', 'subtype' => AccountSubtype::OperatingExpense]);

        // b starts at depth 1 (under rootA, depth 0); moving it under d1
        // (depth 1) lands it at depth 2 — a genuine +1 delta that must
        // cascade to c (depth 2 -> 3), not just a same-depth reparent.
        $this->assertSame(1, $b->depth);
        $moved = $service->moveSubtree($b->fresh(), $d1->fresh());

        $this->assertSame($d1->id, $moved->parent_id);
        $this->assertSame(2, $moved->depth);
        $this->assertSame($d1->fresh()->path.$b->id.'/', $moved->path);

        $cFresh = $c->fresh();
        $this->assertSame(3, $cFresh->depth);
        $this->assertSame($moved->path.$c->id.'/', $cFresh->path);
    }

    public function test_move_subtree_rejects_moving_into_own_descendant(): void
    {
        $company = Company::factory()->create();
        app(CompanyContext::class)->set($company);
        $service = app(AccountService::class);
        $root = $this->expenseAccount();
        $child = $service->createChild($root, ['code' => '5991', 'name' => 'Child', 'subtype' => AccountSubtype::OperatingExpense]);

        $this->expectException(InvalidSubtreeMove::class);
        $service->moveSubtree($root->fresh(), $child->fresh());
    }

    public function test_move_subtree_rejects_cross_type_destination(): void
    {
        $company = Company::factory()->create();
        app(CompanyContext::class)->set($company);
        $service = app(AccountService::class);
        $expenseRoot = $this->expenseAccount(['code' => '5100']);
        $assetRoot = $this->bankAccount(['code' => '1200']);

        $this->expectException(InvalidSubtreeMove::class);
        $service->moveSubtree($expenseRoot, $assetRoot);
    }

    public function test_move_subtree_rejects_a_move_that_would_exceed_max_depth(): void
    {
        $company = Company::factory()->create();
        app(CompanyContext::class)->set($company);
        $service = app(AccountService::class);

        // A 5-deep chain under $deepParent (depths 1..5).
        $deepParent = $this->expenseAccount(['code' => '5000']);
        $cursor = $deepParent;
        for ($i = 1; $i <= AccountService::MAX_DEPTH; $i++) {
            $cursor = $service->createChild($cursor, ['code' => "50{$i}0", 'name' => "L{$i}", 'subtype' => AccountSubtype::OperatingExpense]);
        }

        // Moving $deepParent's direct child (the depth-1 node, still carrying
        // its whole depth-5 subtree) under another already-nested account
        // would push its deepest descendant past MAX_DEPTH.
        $anotherParent = $this->expenseAccount(['code' => '5500']);
        $anotherChild = $service->createChild($anotherParent, ['code' => '5501', 'name' => 'Another child', 'subtype' => AccountSubtype::OperatingExpense]);

        $firstLevel = Account::query()->where('code', '5010')->sole();

        $this->expectException(MaxDepthExceeded::class);
        $service->moveSubtree($firstLevel, $anotherChild->fresh());
    }

    // ---------------------------------------------------------------
    // Direct model-level guard: parent_id cannot be changed outside moveSubtree.
    // ---------------------------------------------------------------

    public function test_updating_parent_id_directly_throws(): void
    {
        $company = Company::factory()->create();
        app(CompanyContext::class)->set($company);
        $a = $this->expenseAccount(['code' => '5100']);
        $b = $this->expenseAccount(['code' => '5200']);

        $this->expectException(LogicException::class);
        $a->update(['parent_id' => $b->id]);
    }

    // ---------------------------------------------------------------
    // Phase 7 Task 1 review fix: system accounts are referenced BY CODE in
    // posting rules + reports, so sub-accounting any of them corrupts those
    // lookups. They can never gain children nor be nested under anything.
    // ---------------------------------------------------------------

    /**
     * Every seeded system account, by code — Retained Earnings (3200), the
     * AR/AP control accounts (1100/2000), Tax Payable (2100), Opening Balance
     * Equity (3100), FX Gain/Loss (4950/5950). Each is referenced by code in
     * posting rules and/or reports, so none may ever be sub-accounted.
     *
     * @return list<string>
     */
    private const SYSTEM_CODES = ['3200', '1100', '2000', '2100', '3100', '4950', '5950'];

    public function test_create_child_under_any_system_account_is_rejected(): void
    {
        $company = Company::factory()->create();
        $this->seedChart($company);
        app(CompanyContext::class)->set($company);

        foreach (self::SYSTEM_CODES as $code) {
            $system = Account::query()->where('code', $code)->sole();

            try {
                app(AccountService::class)->createChild($system, [
                    'code' => "900{$code}", 'name' => 'Child', 'subtype' => $system->subtype,
                ]);
                $this->fail("Expected SystemAccountNotNestable for system account {$code}.");
            } catch (SystemAccountNotNestable) {
                // expected
            }

            // Untouched: still a top-level postable leaf, no child created.
            $fresh = $system->fresh();
            $this->assertTrue($fresh->is_postable, "{$code} should still be postable");
            $this->assertSame(0, $fresh->children()->count(), "{$code} should have no children");
        }
    }

    public function test_make_parent_on_any_system_account_is_rejected(): void
    {
        $company = Company::factory()->create();
        $this->seedChart($company);
        app(CompanyContext::class)->set($company);

        foreach (self::SYSTEM_CODES as $code) {
            $system = Account::query()->where('code', $code)->sole();

            try {
                app(AccountService::class)->makeParent($system);
                $this->fail("Expected SystemAccountNotNestable for system account {$code}.");
            } catch (SystemAccountNotNestable) {
                // expected
            }

            // Untouched: still a top-level postable leaf, no "— Other" child.
            $fresh = $system->fresh();
            $this->assertTrue($fresh->is_postable, "{$code} should still be postable");
            $this->assertNull($fresh->parent_id, "{$code} should still be top-level");
            $this->assertSame(0, $fresh->children()->count(), "{$code} should have no children");
        }
    }

    public function test_moving_a_non_system_account_under_a_system_account_is_rejected(): void
    {
        $company = Company::factory()->create();
        $this->seedChart($company);
        app(CompanyContext::class)->set($company);
        $system = Account::query()->where('code', '3200')->sole();
        $mine = $this->expenseAccount(['code' => '5111', 'name' => 'Mine']);

        $this->expectException(SystemAccountNotNestable::class);
        app(AccountService::class)->moveSubtree($mine->fresh(), $system);
    }

    public function test_moving_a_system_account_under_another_account_is_rejected(): void
    {
        $company = Company::factory()->create();
        $this->seedChart($company);
        app(CompanyContext::class)->set($company);
        // 3200 (Retained Earnings, equity, system) under 3000 (Owner's Equity,
        // equity, NON-system) — same root type, so ONLY the system-account
        // rule can be what rejects it.
        $system = Account::query()->where('code', '3200')->sole();
        $ownersEquity = Account::query()->where('code', '3000')->sole();

        $this->expectException(SystemAccountNotNestable::class);
        app(AccountService::class)->moveSubtree($system, $ownersEquity);
    }

    public function test_raw_create_of_a_child_under_a_system_account_is_blocked_by_the_model_guard(): void
    {
        // The no-bypass backstop: even skipping AccountService entirely and
        // creating the row directly must be refused by Account::creating().
        $company = Company::factory()->create();
        $this->seedChart($company);
        app(CompanyContext::class)->set($company);
        $system = Account::query()->where('code', '3200')->sole();

        $this->expectException(SystemAccountNotNestable::class);
        Account::query()->create([
            'code' => '9002', 'name' => 'Sneaky child',
            'type' => AccountType::Equity->value, 'subtype' => AccountSubtype::Equity->value,
            'is_system' => false, 'parent_id' => $system->id, 'is_postable' => true,
        ]);
    }

    public function test_raw_flip_of_a_system_account_to_non_postable_is_blocked_by_the_model_guard(): void
    {
        $company = Company::factory()->create();
        $this->seedChart($company);
        app(CompanyContext::class)->set($company);
        $system = Account::query()->where('code', '3200')->sole();

        $this->expectException(SystemAccountNotNestable::class);
        $system->update(['is_postable' => false]);
    }

    /**
     * THE regression the review demanded: the Balance Sheet cannot be driven
     * out of balance via sub-accounts. Retained Earnings (3200) carries a
     * real balance; attempting to sub-account it (the exact empirically-
     * reproduced corruption path) is rejected, and the Balance Sheet stays
     * is_balanced=true with 3200's balance intact.
     */
    public function test_balance_sheet_cannot_be_driven_out_of_balance_via_sub_accounts(): void
    {
        $company = Company::factory()->create(['books_start_date' => '2026-01-01']);
        $this->seedChart($company);
        app(CompanyContext::class)->set($company);

        $bank = Account::query()->where('code', '1010')->sole();
        $retainedEarnings = Account::query()->where('code', '3200')->sole();

        // Give 3200 a genuine posted balance (Dr Bank / Cr Retained Earnings).
        $this->postEntry($bank, $retainedEarnings, 50000, 1, '2026-02-01');

        $before = app(BalanceSheetReport::class)->build(CarbonImmutable::parse('2026-07-31'));
        $this->assertTrue($before['is_balanced']);
        $this->assertSame(0, $before['difference']);
        $this->assertSame(50000, $before['equity']['retained_earnings']);

        // The corruption path: sub-accounting 3200 must be refused.
        try {
            app(AccountService::class)->createChild($retainedEarnings, [
                'code' => '3201', 'name' => 'RE detail', 'subtype' => AccountSubtype::Equity,
            ]);
            $this->fail('Expected SystemAccountNotNestable — sub-accounting Retained Earnings must be blocked.');
        } catch (SystemAccountNotNestable) {
            // expected
        }

        // 3200 untouched, ledger untouched, Balance Sheet still balances with
        // its retained earnings intact.
        $this->assertTrue($retainedEarnings->fresh()->is_postable);
        $after = app(BalanceSheetReport::class)->build(CarbonImmutable::parse('2026-07-31'));
        $this->assertTrue($after['is_balanced']);
        $this->assertSame(0, $after['difference']);
        $this->assertSame(50000, $after['equity']['retained_earnings']);
        $this->assertLedgerBalances();
    }

    public function test_non_system_accounts_can_still_be_sub_accounted_freely(): void
    {
        // The other side of the guard: ordinary (non-system) accounts are
        // completely unaffected — this must keep working exactly as before.
        $company = Company::factory()->create();
        $this->seedChart($company);
        app(CompanyContext::class)->set($company);
        // 5400 Software & Subscriptions is NOT a system account.
        $software = Account::query()->where('code', '5400')->sole();
        $this->assertFalse($software->is_system);

        $child = app(AccountService::class)->createChild($software, [
            'code' => '5401', 'name' => 'Design Tools', 'subtype' => AccountSubtype::OperatingExpense,
        ]);

        $this->assertSame($software->id, $child->parent_id);
        $this->assertFalse($software->fresh()->is_postable);
    }
}
