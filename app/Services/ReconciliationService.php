<?php

namespace App\Services;

use App\Exceptions\Reconciliation\ReconciliationAlreadyReopened;
use App\Exceptions\Reconciliation\ReconciliationLineIneligible;
use App\Exceptions\Reconciliation\ReconciliationNotNewest;
use App\Exceptions\Reconciliation\ReconciliationOutOfBalance;
use App\Models\BankAccount;
use App\Models\JournalLine;
use App\Models\Reconciliation;
use App\Models\ReconciliationLine;
use App\Support\AuditLogger;
use App\Support\IdempotencyService;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Bank reconciliation (spec §7.9). CRITICAL: this service writes NO ledger
 * entries — LedgerService is never referenced anywhere in this class. A
 * reconciliation only records which journal_lines on the bank account's
 * linked ledger account cleared a statement (via the reconciliation_lines
 * pivot); the lines themselves were already posted by whatever produced them
 * (a payment, transfer, other receipt, expense, …).
 *
 * Two invariants carry the whole feature's integrity:
 *   1. complete() REFUSES unless the live difference is exactly 0 — no
 *      reconciliation is ever stored "close enough".
 *   2. reopen() only ever allows the account's NEWEST completed
 *      reconciliation to be reopened — this is what keeps
 *      opening_reconciled_balance (carried from the PRIOR reconciliation's
 *      own statement_ending_balance) a consistent chain: reopening anything
 *      but the newest would leave a later reconciliation's opening balance
 *      pointing at a statement that's no longer settled.
 *
 * Shape mirrors InvoicePaymentService/TransferService: IdempotencyService::
 * execute -> DB::transaction -> lockForUpdate -> guards -> mutation ->
 * AuditLogger.
 */
class ReconciliationService
{
    public function __construct(
        private IdempotencyService $idempotency,
        private AuditLogger $audit,
    ) {}

    /**
     * READ-only preview: every journal_line posted to the bank account's
     * linked ledger account, dated on or before the statement date, that
     * isn't already reconciled by a live reconciliation — each with its
     * signed effect (debit − credit) on the account — plus the
     * opening_reconciled_balance the live difference is computed against.
     * Takes no lock and stores nothing; ticking lines and watching the
     * difference move is a client-side, purely additive computation over
     * this same data (see Reconciliation/Show.tsx).
     *
     * @return array{
     *     lines: list<array{id: int, entry_date: string, memo: ?string, amount: int}>,
     *     opening_reconciled_balance: int,
     *     statement_ending_balance: int,
     * }
     */
    public function start(BankAccount $account, CarbonImmutable $statementDate, int $statementEndingBalanceMinor): array
    {
        $lines = $this->unreconciledLines($account, $statementDate);

        return [
            'lines' => $lines->map(fn (JournalLine $line) => [
                'id' => $line->id,
                'entry_date' => $line->journalEntry->entry_date->toDateString(),
                'memo' => $line->memo ?? $line->journalEntry->memo,
                'amount' => $line->debit - $line->credit,
            ])->values()->all(),
            'opening_reconciled_balance' => $this->openingReconciledBalance($account),
            'statement_ending_balance' => $statementEndingBalanceMinor,
        ];
    }

    /**
     * Completes a reconciliation (spec §7.9): validates every ticked line,
     * REFUSES unless the difference is exactly 0 (ReconciliationOutOfBalance
     * — nothing is stored), then stores the reconciliation + one
     * reconciliation_lines row per ticked line. No ledger write of any kind.
     *
     * lockForUpdate on the bank account row (§10) serializes two concurrent
     * completions against the same account — the second blocks until the
     * first commits, then re-reads opening_reconciled_balance (which the
     * first completion may have just changed) and re-validates against
     * current reality rather than a stale read.
     *
     * @param  list<int>  $tickedJournalLineIds
     */
    public function complete(
        string $idempotencyKey,
        BankAccount $account,
        CarbonImmutable $statementDate,
        int $statementEndingBalanceMinor,
        array $tickedJournalLineIds,
    ): Reconciliation {
        $result = $this->idempotency->execute($idempotencyKey, 'reconciliation.completed', function () use ($account, $statementDate, $statementEndingBalanceMinor, $tickedJournalLineIds) {
            return DB::transaction(function () use ($account, $statementDate, $statementEndingBalanceMinor, $tickedJournalLineIds) {
                $lockedAccount = BankAccount::query()->where('id', $account->id)->lockForUpdate()->firstOrFail();

                $tickedLines = $this->validateTickedLines($lockedAccount, $statementDate, $tickedJournalLineIds);

                $openingBalance = $this->openingReconciledBalance($lockedAccount);
                $tickedTotal = $tickedLines->sum(fn (JournalLine $line) => $line->debit - $line->credit);
                $difference = $statementEndingBalanceMinor - ($openingBalance + $tickedTotal);

                if ($difference !== 0) {
                    throw new ReconciliationOutOfBalance(
                        "The statement doesn't balance yet — off by {$this->formatMinor($difference)}. ".
                        'Tick lines until the difference is exactly 0 before finishing.'
                    );
                }

                $reconciliation = Reconciliation::query()->create([
                    'bank_account_id' => $lockedAccount->id,
                    'statement_date' => $statementDate->toDateString(),
                    'statement_ending_balance' => $statementEndingBalanceMinor,
                    'opening_reconciled_balance' => $openingBalance,
                    'status' => 'completed',
                    'completed_by' => auth()->id(),
                    'completed_at' => now(),
                ]);

                foreach ($tickedLines as $line) {
                    ReconciliationLine::query()->create([
                        'reconciliation_id' => $reconciliation->id,
                        'journal_line_id' => $line->id,
                        'amount' => $line->debit - $line->credit,
                    ]);
                }

                $this->audit->log('reconciliation.completed', $reconciliation, after: [
                    'bank_account_id' => $lockedAccount->id,
                    'statement_date' => $reconciliation->statement_date->toDateString(),
                    'statement_ending_balance' => $statementEndingBalanceMinor,
                    'opening_reconciled_balance' => $openingBalance,
                    'lines_count' => $tickedLines->count(),
                ]);

                return ['id' => $reconciliation->id];
            });
        });

        return Reconciliation::query()->findOrFail($result['id']);
    }

    /**
     * Reopens a reconciliation (spec §7.9): unlocks its lines by deleting
     * their reconciliation_lines rows (the "reconciled" marker) and flips its
     * own status to 'reopened'. NEWEST-FIRST ONLY — refuses
     * (ReconciliationNotNewest) if a newer completed reconciliation exists
     * for the same account, naming it, so opening_reconciled_balance always
     * stays a consistent chain (see class docblock). Refuses
     * (ReconciliationAlreadyReopened) if this reconciliation was already
     * reopened by an earlier call under a different idempotency key.
     *
     * Both the bank account row AND the reconciliation row are locked —
     * mirrors InvoicePaymentService::voidPayment locking both the invoice
     * and the payment: a concurrent complete() against the same account
     * blocks on the account lock, and a concurrent second reopen() of the
     * same reconciliation blocks on the reconciliation lock.
     */
    public function reopen(string $idempotencyKey, Reconciliation $reconciliation): Reconciliation
    {
        $result = $this->idempotency->execute($idempotencyKey, 'reconciliation.reopened', function () use ($reconciliation) {
            return DB::transaction(function () use ($reconciliation) {
                $account = BankAccount::query()->where('id', $reconciliation->bank_account_id)->lockForUpdate()->firstOrFail();
                $locked = Reconciliation::query()->where('id', $reconciliation->id)->lockForUpdate()->firstOrFail();

                if ($locked->status !== 'completed') {
                    throw new ReconciliationAlreadyReopened('This reconciliation has already been reopened.');
                }

                $newest = $this->latestCompleted($account);

                if ($newest !== null && $newest->id !== $locked->id) {
                    throw new ReconciliationNotNewest(
                        "Reopen the more recent reconciliation dated {$newest->statement_date->toDateString()} first — ".
                        'reconciliations must be reopened newest-first.'
                    );
                }

                $before = ['status' => $locked->status];

                ReconciliationLine::query()->where('reconciliation_id', $locked->id)->delete();

                $locked->forceFill(['status' => 'reopened'])->save();

                $this->audit->log('reconciliation.reopened', $locked, before: $before, after: [
                    'status' => 'reopened',
                    'bank_account_id' => $account->id,
                ]);

                return ['id' => $locked->id];
            });
        });

        return Reconciliation::query()->findOrFail($result['id']);
    }

    /**
     * Every journal_line posted to the account's linked ledger account,
     * dated on/before the statement date, that has no LIVE
     * reconciliation_lines row (an unreconciled line whose OWN
     * reconciliation was later reopened is correctly included again — the
     * unique index only ever has rows for currently-reconciled lines,
     * reopen() deletes them).
     */
    private function unreconciledLines(BankAccount $account, CarbonImmutable $statementDate): Collection
    {
        return JournalLine::query()
            ->where('account_id', $account->ledger_account_id)
            ->whereHas('journalEntry', fn ($e) => $e->where('entry_date', '<=', $statementDate->toDateString()))
            ->whereNotIn('id', ReconciliationLine::query()->select('journal_line_id'))
            ->with('journalEntry')
            ->orderBy('id')
            ->get();
    }

    /**
     * Validates every ticked id belongs to this account's ledger account, is
     * dated on/before the statement date, and isn't already reconciled —
     * each a ReconciliationLineIneligible failure. An empty ticked list is
     * legal (a statement with no clearing activity this period still needs
     * to reconcile to its opening balance).
     *
     * @param  list<int>  $tickedJournalLineIds
     * @return Collection<int, JournalLine>
     */
    private function validateTickedLines(BankAccount $account, CarbonImmutable $statementDate, array $tickedJournalLineIds): Collection
    {
        if ($tickedJournalLineIds === []) {
            return new Collection;
        }

        $lines = JournalLine::query()
            ->whereIn('id', $tickedJournalLineIds)
            ->with('journalEntry')
            ->get()
            ->keyBy('id');

        foreach ($tickedJournalLineIds as $id) {
            $line = $lines->get($id);

            // A null lookup covers both "doesn't exist" and "belongs to a
            // different company" — JournalLine's BelongsToCompany global
            // scope makes those indistinguishable here, same reasoning as
            // every other scoped lookup in this app (PROJECT_MAP §9 rule 7).
            if ($line === null || $line->account_id !== $account->ledger_account_id) {
                throw new ReconciliationLineIneligible(
                    "Journal line #{$id} doesn't belong to this bank account."
                );
            }

            if ($line->journalEntry->entry_date->gt($statementDate)) {
                throw new ReconciliationLineIneligible(
                    "Journal line #{$id} is dated after the statement date and can't be ticked."
                );
            }
        }

        $alreadyReconciled = ReconciliationLine::query()
            ->whereIn('journal_line_id', $tickedJournalLineIds)
            ->exists();

        if ($alreadyReconciled) {
            throw new ReconciliationLineIneligible(
                'One or more of the ticked lines has already been reconciled in another reconciliation.'
            );
        }

        return $lines->values();
    }

    /**
     * The account's most recently COMPLETED reconciliation (by statement
     * date, then id as a tiebreak) — 0/none if it has never been reconciled,
     * or if its only reconciliation is currently reopened. This single
     * definition backs BOTH opening_reconciled_balance (complete()) and the
     * newest-first reopen guard (reopen()) — they must always agree on what
     * "the account's current reconciliation state" means.
     */
    private function latestCompleted(BankAccount $account): ?Reconciliation
    {
        return Reconciliation::query()
            ->where('bank_account_id', $account->id)
            ->where('status', 'completed')
            ->orderByDesc('statement_date')
            ->orderByDesc('id')
            ->first();
    }

    private function openingReconciledBalance(BankAccount $account): int
    {
        return $this->latestCompleted($account)?->statement_ending_balance ?? 0;
    }

    /**
     * Integer-only minor-to-decimal-string formatting for the out-of-balance
     * error message — never float math, mirrors
     * InvoicePaymentService::formatMinor / TrialBalanceController's own.
     */
    private function formatMinor(int $minor): string
    {
        $sign = $minor < 0 ? '-' : '';
        $abs = abs($minor);

        return $sign.intdiv($abs, 100).'.'.str_pad((string) ($abs % 100), 2, '0', STR_PAD_LEFT);
    }
}
