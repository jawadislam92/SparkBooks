<?php

namespace App\Services;

use App\Enums\AccountSubtype;
use App\Exceptions\Accounts\CrossTypeSubAccount;
use App\Exceptions\Accounts\InvalidSubtreeMove;
use App\Exceptions\Accounts\MaxDepthExceeded;
use App\Exceptions\Accounts\SystemAccountNotNestable;
use App\Models\Account;
use App\Models\JournalLine;
use App\Support\AuditLogger;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Phase 7 Task 1: the ONLY gateway an account ever becomes a sub-account
 * parent through, and the ONLY place a posted journal_line's account_id is
 * ever allowed to change after the fact (LedgerService's own ledger is
 * otherwise strictly append-only/immutable — see JournalLine's model guards).
 *
 * Every write here runs inside one DB transaction — either the caller's
 * (mirroring LedgerService::post's own convention) or one this class opens
 * itself for its own public entry points, so a hierarchy is never left
 * half-updated (e.g. a promoted leaf with only some of its lines moved).
 */
class AccountService
{
    /** A top-level account is depth 0; no account may sit deeper than this. */
    public const MAX_DEPTH = 5;

    public function __construct(private AuditLogger $audit) {}

    /**
     * Create a new leaf account as a child of $parent.
     *
     * Validates FIRST, before touching anything: the child's root type must
     * match $parent's (CrossTypeSubAccount), and the resulting depth must not
     * exceed MAX_DEPTH. Only then, if $parent is still a plain postable leaf,
     * it is promoted via makeParent() (see that method's docblock for why
     * this is unconditional and safe even when $parent has zero postings).
     *
     * @param  array{code:string, name:string, subtype:AccountSubtype|string, description?:?string}  $attributes
     */
    public function createChild(Account $parent, array $attributes): Account
    {
        return DB::transaction(function () use ($parent, $attributes) {
            // System accounts (Retained Earnings, AR/AP control, Tax Payable,
            // OBE, FX Gain/Loss, ...) are referenced by CODE in posting rules
            // and reports — they must never gain children (see
            // SystemAccountNotNestable's docblock for the exact corruption).
            if ($parent->is_system) {
                throw new SystemAccountNotNestable("{$parent->code} ({$parent->name}) is a system account and can't have sub-accounts.");
            }

            $subtype = $attributes['subtype'] instanceof AccountSubtype
                ? $attributes['subtype']
                : AccountSubtype::from($attributes['subtype']);

            if ($subtype->type() !== $parent->type) {
                throw new CrossTypeSubAccount("A sub-account of {$parent->code} must be a {$parent->type->value} account, not {$subtype->type()->value}.");
            }

            if ($parent->depth + 1 > self::MAX_DEPTH) {
                throw new MaxDepthExceeded('Sub-accounts cannot be nested more than '.self::MAX_DEPTH.' levels deep.');
            }

            if ($parent->isPostable()) {
                $this->makeParent($parent);
                $parent->refresh();
            }

            $child = Account::query()->create([
                'code' => $attributes['code'],
                'name' => $attributes['name'],
                'type' => $subtype->type()->value,
                'subtype' => $subtype->value,
                'is_system' => false,
                'description' => $attributes['description'] ?? null,
                'parent_id' => $parent->id,
                'is_postable' => true,
            ]);

            $this->audit->log('account.created', $child, after: $child->only(['code', 'name', 'type', 'subtype', 'parent_id', 'description']));

            return $child;
        });
    }

    /**
     * Promote a leaf into a parent (header) account, in ONE transaction. If
     * the leaf has ANY existing journal_lines, this creates "<name> — Other"
     * (same type/subtype, a fresh postable leaf) and MOVES every one of
     * those lines onto it (updates journal_lines.account_id on
     * already-posted lines — a deliberate ledger MOVE, allowed ONLY here in
     * the whole codebase, never a re-post) before flipping the leaf itself
     * to is_postable=false. A leaf with ZERO postings is simply flipped
     * straight to is_postable=false — no "<name> — Other" clutter is created
     * when there is nothing to preserve (once flipped, LedgerService/
     * IntegrityChecker refuse any FUTURE posting to it anyway, so there is
     * no window where it could ever need one after the fact).
     *
     * Calling this on an account that is ALREADY a parent (is_postable
     * already false) is a safe no-op — returns $leaf unchanged. That, plus
     * the "only creates an Other child when needed" behavior above, is what
     * lets createChild() call this UNCONDITIONALLY whenever a still-postable
     * account gains its first child, regardless of whether it has real
     * history to preserve: there is exactly ONE way an account ever becomes
     * non-postable in this codebase.
     *
     * Balances are unchanged by construction whenever a move does happen:
     * the moved lines still sum to the exact same per-account net they
     * always did, just attributed to a leaf one level deeper that rolls up
     * into the same parent (see Account::subtreeAccountIds() / AccountRollup).
     */
    public function makeParent(Account $leaf): Account
    {
        return DB::transaction(function () use ($leaf) {
            // A system account can never become a parent (nor non-postable) —
            // it stays a top-level postable leaf forever. This is the
            // load-bearing guard that keeps the Balance Sheet from being
            // driven out of balance via 3200 (Retained Earnings), and it
            // covers createChild() too (which calls makeParent() to promote
            // its parent before attaching the child).
            if ($leaf->is_system) {
                throw new SystemAccountNotNestable("{$leaf->code} ({$leaf->name}) is a system account and can't have sub-accounts.");
            }

            if (! $leaf->isPostable()) {
                return $leaf;
            }

            $before = $leaf->only(['is_postable']);
            $hasPostings = JournalLine::query()->where('account_id', $leaf->id)->exists();

            $otherChild = null;
            $movedLines = 0;

            if ($hasPostings) {
                $otherChild = Account::query()->create([
                    'code' => $this->otherChildCode($leaf),
                    'name' => "{$leaf->name} — Other",
                    'type' => $leaf->type->value,
                    'subtype' => $leaf->subtype->value,
                    'is_system' => false,
                    'description' => $leaf->description,
                    'parent_id' => $leaf->id,
                    'is_postable' => true,
                ]);

                $movedLines = JournalLine::query()
                    ->where('account_id', $leaf->id)
                    ->update(['account_id' => $otherChild->id]);
            }

            $leaf->update(['is_postable' => false]);

            $this->audit->log('account.promoted', $leaf, before: $before, after: [
                'is_postable' => false,
                'other_child_id' => $otherChild?->id,
                'other_child_code' => $otherChild?->code,
                'lines_moved' => $movedLines,
            ]);

            return $leaf->fresh();
        });
    }

    /**
     * Reparent an existing account (and its whole subtree, if any) under
     * $newParent (or to top-level, if null), updating path/depth for the
     * account AND every descendant in one transaction — a plain
     * `$account->update(['parent_id' => ...])` cannot do this cascade at all
     * (see Account::booted()'s updating guard, which blocks that outright).
     *
     * Guards: rejects moving an account into itself or one of its own
     * descendants (InvalidSubtreeMove — would create a cycle), rejects
     * moving across root account types (InvalidSubtreeMove — mirrors
     * createChild's CrossTypeSubAccount rule), and rejects a move that would
     * push the account or any of its existing descendants past MAX_DEPTH
     * (MaxDepthExceeded). If $newParent is currently a plain postable leaf,
     * it is promoted first (via makeParent(), same transaction) — otherwise
     * attaching a subtree under it would silently turn it into a
     * leaf-with-children, violating leaf-only posting for whatever balance
     * it already carries.
     */
    public function moveSubtree(Account $account, ?Account $newParent): Account
    {
        return DB::transaction(function () use ($account, $newParent) {
            // A system account may neither be nested under anything (it stays
            // top-level) nor serve as a parent for anyone — same reasoning as
            // makeParent()/createChild() above.
            if ($account->is_system && $newParent !== null) {
                throw new SystemAccountNotNestable("{$account->code} ({$account->name}) is a system account and can't be nested under another account.");
            }
            if ($newParent !== null && $newParent->is_system) {
                throw new SystemAccountNotNestable("{$newParent->code} ({$newParent->name}) is a system account and can't have sub-accounts.");
            }

            if ($newParent !== null) {
                if ($newParent->id === $account->id || str_starts_with($newParent->path, $account->path)) {
                    throw new InvalidSubtreeMove('An account cannot be moved into itself or one of its own descendants.');
                }
                if ($newParent->type !== $account->type) {
                    throw new InvalidSubtreeMove("A sub-account of {$newParent->code} must be a {$newParent->type->value} account.");
                }
            }

            $newDepth = $newParent !== null ? $newParent->depth + 1 : 0;
            $newPrefix = $newParent?->path ?? '/';

            $deepestDescendantDepth = (int) ($account->descendants()->max('depth') ?? $account->depth);
            $deepestRelativeDepth = $deepestDescendantDepth - $account->depth;

            if ($newDepth + $deepestRelativeDepth > self::MAX_DEPTH) {
                throw new MaxDepthExceeded('Moving this account here would nest a descendant more than '.self::MAX_DEPTH.' levels deep.');
            }

            if ($newParent !== null && $newParent->isPostable()) {
                $this->makeParent($newParent);
                $newParent->refresh();
                $newPrefix = $newParent->path;
            }

            $oldPath = $account->path;
            $depthDelta = $newDepth - $account->depth;
            $newPath = $newPrefix.$account->id.'/';

            // Every descendant (not $account itself) shifts its path prefix
            // and depth in one UPDATE — a raw, parameter-bound DB::statement,
            // deliberately bypassing Account's Eloquent guards (the same "raw
            // query for a structural bulk change" convention Account::
            // booted()'s own docblock calls out as this method's sanctioned
            // mechanism).
            if ($depthDelta !== 0 || $oldPath !== $newPath) {
                DB::statement(
                    'UPDATE accounts SET path = CONCAT(?, SUBSTRING(path, ?)), depth = depth + ? '
                    .'WHERE company_id = ? AND path LIKE ? AND id != ?',
                    [$newPath, mb_strlen($oldPath) + 1, $depthDelta, $account->company_id, $oldPath.'%', $account->id]
                );
            }

            DB::table('accounts')->where('id', $account->id)->update([
                'parent_id' => $newParent?->id,
                'path' => $newPath,
                'depth' => $newDepth,
            ]);

            $this->audit->log('account.moved', $account, before: [
                'parent_id' => $account->parent_id,
                'path' => $oldPath,
                'depth' => $account->depth,
            ], after: [
                'parent_id' => $newParent?->id,
                'path' => $newPath,
                'depth' => $newDepth,
            ]);

            return $account->fresh();
        });
    }

    /**
     * A short, guaranteed-unique code for makeParent()'s auto-created
     * "<name> — Other" child — never user-facing input, so it doesn't need
     * to follow StoreAccountRequest's user-facing 4-digit convention, only
     * the `code` column's own uniqueness constraint (unique per company).
     */
    private function otherChildCode(Account $leaf): string
    {
        $base = mb_substr($leaf->code, 0, 8).'-O';
        if (! Account::query()->where('code', $base)->exists()) {
            return $base;
        }

        for ($suffix = 2; $suffix <= 999; $suffix++) {
            $candidate = mb_substr($leaf->code, 0, 6)."-O{$suffix}";
            if (! Account::query()->where('code', $candidate)->exists()) {
                return $candidate;
            }
        }

        throw new RuntimeException('Could not generate a unique code for the "Other" sub-account.');
    }
}
