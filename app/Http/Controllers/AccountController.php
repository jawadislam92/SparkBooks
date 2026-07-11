<?php

namespace App\Http\Controllers;

use App\Enums\AccountSubtype;
use App\Exceptions\Accounts\AccountException;
use App\Http\Requests\StoreAccountRequest;
use App\Http\Requests\UpdateAccountRequest;
use App\Models\Account;
use App\Models\JournalLine;
use App\Services\AccountBalances;
use App\Services\AccountService;
use App\Services\Reports\AccountRollup;
use App\Support\AuditLogger;
use Illuminate\Http\RedirectResponse;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class AccountController extends Controller
{
    private const PER_PAGE = 20;

    /** Friendly, user-facing words for each subtype — debit/credit never appear here. */
    private const SUBTYPE_LABELS = [
        'cash' => 'Cash',
        'bank' => 'Bank',
        'receivable' => 'Money owed to you',
        'payable' => 'Money you owe',
        'tax' => 'Tax',
        'fixed_asset' => 'Fixed asset',
        'current_asset' => 'Current asset',
        'current_liability' => 'Current liability',
        'equity' => 'Equity',
        'operating_income' => 'Income',
        'other_income' => 'Other income',
        'operating_expense' => 'Expense',
    ];

    /**
     * Rows are depth-first ordered (AccountRollup — a parent immediately
     * followed by its descendants) rather than a flat `orderBy('code')`, so
     * the tree can be rendered directly from array order + `depth`/
     * `parent_id`. A parent (header) account's `balance` is the ROLLUP of
     * every descendant leaf's balance (it never carries a direct posting of
     * its own); a company with no sub-accounts degenerates to the exact same
     * flat, code-ordered list this page always showed.
     */
    public function index(AccountBalances $balances, AccountRollup $rollup): Response
    {
        $balancesByAccount = $balances->asOf();

        $accounts = Account::query()->orderBy('code')->get();
        $ordered = $rollup->order($accounts);
        $rolledBalances = $rollup->rollup($ordered, $balancesByAccount);

        $payload = collect($ordered)
            ->map(fn (Account $account) => $this->accountPayload(
                $account,
                $account->isPostable() ? ($balancesByAccount[$account->id] ?? 0) : ($rolledBalances[$account->id] ?? 0)
            ))
            ->values();

        return Inertia::render('Accounts/Index', [
            'accounts' => $payload,
        ]);
    }

    public function store(StoreAccountRequest $request, AuditLogger $audit): RedirectResponse
    {
        $data = $request->validated();
        $subtype = AccountSubtype::from($data['subtype']);

        $account = Account::query()->create([
            'code' => $data['code'],
            'name' => $data['name'],
            'type' => $subtype->type()->value,
            'subtype' => $subtype->value,
            // Never read from the request — see StoreAccountRequest doc-comment.
            'is_system' => false,
            'description' => $data['description'] ?? null,
            // Explicit, not relied on as a DB column default: a fresh
            // top-level account created here always starts as a postable
            // leaf, and the in-memory model instance returned below needs
            // this attribute actually SET (not merely defaulted at the DB
            // layer) so anything reading $account->is_postable/isPostable()
            // in the same request sees the correct value without a reload.
            'parent_id' => null,
            'is_postable' => true,
        ]);

        $audit->log('account.created', $account, after: $account->only(['code', 'name', 'type', 'subtype', 'description']));

        return redirect()->route('accounts.index');
    }

    /**
     * Phase 7 Task 1 (sub-accounts): "Add sub-account" — reuses
     * StoreAccountRequest's shape validation (code/name/subtype/description
     * are identical requirements either way), then delegates the actual
     * creation to AccountService::createChild(), which owns every structural
     * invariant (root-type match, depth cap, auto-promoting $parent if it's
     * still a plain postable leaf) and its own audit logging. Domain
     * rejections surface as a friendly field error, same convention
     * ManualJournalController uses for LedgerException.
     */
    public function storeChild(StoreAccountRequest $request, string $account, AccountService $service): RedirectResponse
    {
        $parent = Account::query()->findOrFail($account);
        $data = $request->validated();

        try {
            $service->createChild($parent, [
                'code' => $data['code'],
                'name' => $data['name'],
                'subtype' => $data['subtype'],
                'description' => $data['description'] ?? null,
            ]);
        } catch (AccountException $e) {
            throw ValidationException::withMessages(['subtype' => $e->getMessage()]);
        }

        return redirect()->route('accounts.index');
    }

    /**
     * $account is deliberately a plain route parameter, not an implicit
     * Eloquent binding: Account's BelongsToCompany global scope requires
     * CompanyContext to already be set, but SubstituteBindings (which
     * resolves implicit bindings) runs as part of the framework's "web"
     * middleware group — earlier than this route's own auth/company/can
     * middleware, and earlier than SetCompanyContext. Doing the lookup here,
     * inside the controller action, guarantees the company context is
     * already in place. Mirrors ManualJournalController.
     */
    public function update(UpdateAccountRequest $request, string $account, AuditLogger $audit): RedirectResponse
    {
        $model = Account::query()->findOrFail($account);
        $before = $model->only(['code', 'name', 'subtype', 'description']);
        $data = $request->validated();

        $update = [
            'name' => $data['name'],
            'description' => $data['description'] ?? null,
        ];

        if (! $model->is_system) {
            $subtype = AccountSubtype::from($data['subtype']);
            $update['code'] = $data['code'];
            $update['type'] = $subtype->type()->value;
            $update['subtype'] = $subtype->value;
        }

        $model->update($update);

        $audit->log('account.updated', $model, before: $before, after: $model->only(['code', 'name', 'subtype', 'description']));

        return redirect()->route('accounts.index');
    }

    /**
     * Phase 7 Task 1: a parent (header) account with any active (non-
     * archived) child throws AccountHasActiveChildren (Account::booted()'s
     * updating guard) — caught here and surfaced as a friendly field error
     * the same way ExpenseController/BillController convert a rejected
     * status transition, rather than letting a raw 500 reach the user.
     */
    public function archive(string $account, AuditLogger $audit): RedirectResponse
    {
        $model = Account::query()->findOrFail($account);

        try {
            $model->update(['is_archived' => true]);
        } catch (AccountException $e) {
            throw ValidationException::withMessages(['is_archived' => $e->getMessage()]);
        }

        $audit->log('account.archived', $model);

        return redirect()->route('accounts.index');
    }

    public function unarchive(string $account, AuditLogger $audit): RedirectResponse
    {
        $model = Account::query()->findOrFail($account);
        $model->update(['is_archived' => false]);
        $audit->log('account.unarchived', $model);

        return redirect()->route('accounts.index');
    }

    /**
     * Ledger drill-down. Running balance must reflect the account's whole
     * history, computed in chronological order (oldest first), then the page
     * is displayed newest-first. Chosen approach: load every line for this
     * one account (Phase 1 accounts have modest line counts — this is a
     * single-tenant, single-account query, not a whole-ledger scan), fold the
     * running balance across the full chronological list once, then reverse
     * and paginate the already-computed rows in memory. This is simpler and
     * provably correct; an opening-balance-offset-per-page approach would
     * only be worth the extra complexity if per-account line counts grew far
     * beyond what a single company can plausibly post in Phase 1.
     */
    public function show(string $account, AccountBalances $balances): Response
    {
        $model = Account::query()->findOrFail($account);
        $debitNormal = $model->type->isDebitNormal();

        $lines = JournalLine::query()
            ->where('account_id', $model->id)
            ->with('journalEntry')
            ->get()
            ->sort(fn (JournalLine $a, JournalLine $b) => [$a->journalEntry->entry_date->toDateString(), $a->id]
                <=> [$b->journalEntry->entry_date->toDateString(), $b->id])
            ->values();

        $running = 0;
        $chronological = $lines->map(function (JournalLine $line) use (&$running, $debitNormal) {
            $running += $debitNormal ? ($line->debit - $line->credit) : ($line->credit - $line->debit);

            return [
                'id' => $line->id,
                'entry_date' => $line->journalEntry->entry_date->toDateString(),
                'memo' => $line->memo ?? $line->journalEntry->memo,
                'debit' => $line->debit,
                'credit' => $line->credit,
                'running_balance' => $running,
            ];
        });

        $newestFirst = $chronological->reverse()->values();
        $page = max((int) request()->query('page', 1), 1);
        $ledger = new LengthAwarePaginator(
            $newestFirst->forPage($page, self::PER_PAGE)->values(),
            $newestFirst->count(),
            self::PER_PAGE,
            $page,
            ['path' => request()->url(), 'query' => request()->query()],
        );

        $balancesByAccount = $balances->asOf();
        $balance = $model->isPostable()
            ? ($balancesByAccount[$model->id] ?? 0)
            // Parent (header) account: rolled up from every descendant leaf
            // (subtreeAccountIds() includes $model's own id, which always
            // contributes 0 — parents never carry a direct posting).
            : array_sum(array_map(fn (int $id) => $balancesByAccount[$id] ?? 0, $model->subtreeAccountIds()));

        return Inertia::render('Accounts/Show', [
            'account' => $this->accountPayload($model, $balance),
            'ledger' => $ledger,
        ]);
    }

    /** @return array<string, mixed> */
    private function accountPayload(Account $account, int $balance): array
    {
        return [
            'id' => $account->id,
            'code' => $account->code,
            'name' => $account->name,
            'type' => $account->type->value,
            'subtype' => $account->subtype->value,
            'subtype_label' => self::SUBTYPE_LABELS[$account->subtype->value] ?? $account->subtype->value,
            'is_system' => $account->is_system,
            'is_archived' => $account->is_archived,
            'description' => $account->description,
            'balance' => $balance,
            'parent_id' => $account->parent_id,
            'is_postable' => $account->isPostable(),
            'depth' => $account->depth,
        ];
    }
}
