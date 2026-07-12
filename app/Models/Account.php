<?php

namespace App\Models;

use App\Enums\AccountSubtype;
use App\Enums\AccountType;
use App\Enums\CashFlowClass;
use App\Exceptions\Accounts\AccountHasActiveChildren;
use App\Exceptions\Accounts\SystemAccountCannotBeArchived;
use App\Exceptions\Accounts\SystemAccountNotNestable;
use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use LogicException;

class Account extends Model
{
    use BelongsToCompany, HasFactory;

    /**
     * `parent_id`/`is_postable` are fillable (AccountService sets them via
     * ordinary create()/update() calls, the same convention `is_system` uses
     * — protection for structural columns lives in the `booted()` hooks
     * below, not in omitting them from $fillable). `depth`/`path` are
     * deliberately NEVER fillable: they are pure derivations of `parent_id`,
     * recomputed automatically by the `created` hook below for every new
     * account regardless of caller (ChartOfAccountsSeeder, AccountController,
     * AccountFactory, AccountService) — so they can never drift out of sync
     * with parent_id by construction.
     */
    protected $fillable = ['code', 'name', 'type', 'subtype', 'is_system', 'is_archived', 'description', 'parent_id', 'is_postable', 'cf_class'];

    protected function casts(): array
    {
        return [
            'type' => AccountType::class,
            'subtype' => AccountSubtype::class,
            'cf_class' => CashFlowClass::class,
            'is_system' => 'boolean',
            'is_archived' => 'boolean',
            'is_postable' => 'boolean',
            'depth' => 'integer',
        ];
    }

    public function isControlAccount(): bool
    {
        return in_array($this->subtype, [AccountSubtype::Receivable, AccountSubtype::Payable], true);
    }

    /** Explicit flag — never derived from "has children". See AccountService::makeParent(). */
    public function isPostable(): bool
    {
        return (bool) $this->is_postable;
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    /** Direct children only (one level down) — see descendants() for the whole subtree. */
    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id');
    }

    /**
     * Every descendant at any depth (children, grandchildren, ...), NOT
     * including this account itself — matched via the materialized `path`
     * (indexed), so this is a single prefix-scan query regardless of subtree
     * depth or size, never a recursive N+1 walk.
     */
    public function descendants()
    {
        return static::query()
            ->where('path', 'like', $this->path.'%')
            ->where('id', '!=', $this->id);
    }

    /** Every non-archived account for this company — the shared "picker" filter every form-options/eligibility query in this app applies. Mirrors Contact/Item/BankAccount's own scopeActive. */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_archived', false);
    }

    /** Every archived account for this company — the Chart of Accounts' own "Show archived" filter, applied client-side against the same full payload scopeActive's callers would otherwise narrow to. */
    public function scopeArchived(Builder $query): Builder
    {
        return $query->where('is_archived', true);
    }

    /**
     * Rollup helper for reports (TrialBalanceReport/BalanceSheetReport/
     * ProfitAndLossReport/GeneralLedgerReport): every descendant account id,
     * including this account's own id — the exact set a report needs to sum
     * over to get this account's subtotal (parents never carry direct
     * postings themselves, so including a non-leaf id in the sum is always
     * safe: its own contribution is always 0).
     *
     * @return list<int>
     */
    public function subtreeAccountIds(): array
    {
        return [$this->id, ...$this->descendants()->pluck('id')->all()];
    }

    protected static function booted(): void
    {
        static::updating(function (Account $account) {
            // Base protection on the ORIGINAL flag, not the (fillable, possibly
            // just-mutated) live attribute — otherwise update(['is_system' => false,
            // 'code' => 'X']) would demote then retype a built-in in one call.
            // Also forbid demoting a built-in account via is_system itself.
            if ($account->getOriginal('is_system')
                && $account->isDirty(['is_system', 'code', 'type', 'subtype'])) {
                throw new LogicException('System accounts cannot change is_system, code, type, or subtype.');
            }

            // A system account may never be flipped non-postable — becoming a
            // parent (header) account is the ONLY way is_postable goes false,
            // and system accounts (referenced by code in posting rules and
            // reports) must stay top-level postable leaves. This is the
            // no-bypass backstop for AccountService::makeParent()'s own
            // system-account guard (which fires first with a friendly
            // message); it also catches a raw makeParent-less flip.
            if ($account->getOriginal('is_system')
                && $account->isDirty('is_postable') && ! $account->is_postable) {
                throw new SystemAccountNotNestable("Account {$account->code} is a system account and cannot become a sub-account parent.");
            }

            // Archiving is blocked while ANY non-archived child exists (leaf
            // or another parent) — an archived header account whose children
            // are still active would orphan them from the "active" view of
            // the Chart of Accounts. Only checked when is_archived is
            // actually being flipped TO true, so renaming/re-describing an
            // already-archived account, or unarchiving, is never affected.
            if ($account->isDirty('is_archived') && $account->is_archived
                && $account->children()->where('is_archived', false)->exists()) {
                throw new AccountHasActiveChildren("Account {$account->code} has active sub-accounts and cannot be archived while they remain active.");
            }

            // Phase 7 Task 2: a system account can never be archived — see
            // SystemAccountCannotBeArchived's docblock for why. Based on the
            // ORIGINAL is_system flag (same "never trust the live, possibly
            // just-mutated attribute" reasoning as the is_system-change guard
            // above), and only checked when is_archived is actually being
            // flipped TO true, so unarchiving (a no-op for a system account,
            // which can never get here in the first place) is never blocked.
            if ($account->getOriginal('is_system')
                && $account->isDirty('is_archived') && $account->is_archived) {
                throw new SystemAccountCannotBeArchived("Account {$account->code} is a system account and cannot be archived.");
            }

            // Reparenting an EXISTING account must cascade path/depth to
            // every descendant in the same transaction (spec: "moving a
            // subtree updates path+depth for ALL descendants in one txn") —
            // a plain ->update(['parent_id' => ...]) cannot do that cascade,
            // so it is blocked outright. The only sanctioned path is
            // AccountService::moveSubtree(), which performs the reparent via
            // a raw DB::table() write (deliberately bypassing this guard,
            // the same "raw query for a structural bulk change" convention
            // already used elsewhere in this codebase), then fixes up every
            // descendant's path/depth in the same transaction.
            if ($account->isDirty('parent_id')) {
                throw new LogicException('Account parent cannot be changed directly — use AccountService::moveSubtree().');
            }
        });

        // No child may ever be created UNDER a system account — the no-bypass
        // backstop for AccountService::createChild()/makeParent()'s own
        // system-parent guards (which fire first, with friendly messages).
        // Guards against a raw Account::create(['parent_id' => <system id>])
        // that skips the service entirely.
        static::creating(function (Account $account) {
            if ($account->parent_id !== null
                && static::query()->whereKey($account->parent_id)->value('is_system')) {
                throw new SystemAccountNotNestable('A system account cannot have sub-accounts.');
            }
        });

        // Every new account's depth/path is a pure derivation of parent_id,
        // recomputed here unconditionally so it can never drift out of sync
        // regardless of which caller created the row (ChartOfAccountsSeeder,
        // AccountController::store, AccountFactory, AccountService::
        // createChild/makeParent all funnel through this one place). A
        // second, quiet UPDATE is unavoidable — the child's own id (the last
        // path segment) only exists after the INSERT.
        static::created(function (Account $account) {
            $parent = $account->parent_id !== null ? static::query()->find($account->parent_id) : null;

            $account->forceFill([
                'depth' => $parent !== null ? $parent->depth + 1 : 0,
                'path' => ($parent?->path ?? '/').$account->id.'/',
            ])->saveQuietly();
        });

        static::deleting(function () {
            throw new LogicException('Accounts are archived, never deleted.');
        });

        // Phase 7 Task 8 (cf_class fix): the structural invariant that makes
        // a NULL `cf_class` — and a cash/bank account silently falling out of
        // the Statement of Cash Flows' cash pool — impossible after a save,
        // for EVERY creation/update path (ChartOfAccountsSeeder,
        // BankAccountService::createOnce, AccountController::store/update,
        // AccountService::createChild, AccountFactory, future code), because
        // `saving` fires before BOTH `creating` and `updating` on every
        // single Model::save() call, regardless of caller.
        //
        // 1. A Cash/Bank subtype account is ALWAYS forced to cf_class=Cash,
        //    unconditionally — a real cash/bank account can never drift out
        //    of the cash pool, even via a direct mis-edit that tries to set
        //    it to something else (the "GUARD" the cf_class edit form's own
        //    UI mirrors by hiding/disabling the control for these accounts).
        // 2. Otherwise, a null cf_class is filled in from
        //    CashFlowClass::defaultFor() (the same mapping the migration
        //    backfill and ChartOfAccountsSeeder use) — this is what closes
        //    the original bug: an account created by a path that never used
        //    to set cf_class at all used to stay NULL forever.
        // 3. An explicitly-set, non-null cf_class on a non-cash/bank account
        //    is left untouched — that is the accountant-reclassification
        //    path (e.g. tagging a loan-interest expense as Financing), and
        //    AccountService::createChild's own parent-inheritance default
        //    (see that method) flows through this same "already non-null,
        //    leave it" branch.
        static::saving(function (Account $account) {
            if (in_array($account->subtype, [AccountSubtype::Cash, AccountSubtype::Bank], true)) {
                $account->cf_class = CashFlowClass::Cash;
            } elseif ($account->cf_class === null) {
                $account->cf_class = CashFlowClass::defaultFor($account->subtype, $account->code);
            }
        });
    }
}
