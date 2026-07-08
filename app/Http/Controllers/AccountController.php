<?php

namespace App\Http\Controllers;

use App\Enums\AccountSubtype;
use App\Http\Requests\StoreAccountRequest;
use App\Http\Requests\UpdateAccountRequest;
use App\Models\Account;
use App\Models\JournalLine;
use App\Services\AccountBalances;
use App\Support\AuditLogger;
use Illuminate\Http\RedirectResponse;
use Illuminate\Pagination\LengthAwarePaginator;
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

    public function index(AccountBalances $balances): Response
    {
        $balancesByAccount = $balances->asOf();

        $accounts = Account::query()
            ->orderBy('code')
            ->get()
            ->map(fn (Account $account) => $this->accountPayload($account, $balancesByAccount[$account->id] ?? 0))
            ->values();

        return Inertia::render('Accounts/Index', [
            'accounts' => $accounts,
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
        ]);

        $audit->log('account.created', $account, after: $account->only(['code', 'name', 'type', 'subtype', 'description']));

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

    public function archive(string $account, AuditLogger $audit): RedirectResponse
    {
        $model = Account::query()->findOrFail($account);
        $model->update(['is_archived' => true]);
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

        return Inertia::render('Accounts/Show', [
            'account' => $this->accountPayload($model, $balances->asOf()[$model->id] ?? 0),
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
        ];
    }
}
