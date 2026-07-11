<?php

namespace App\Http\Controllers;

use App\Exceptions\Reconciliation\ReconciliationException;
use App\Http\Requests\CompleteReconciliationRequest;
use App\Models\BankAccount;
use App\Models\Reconciliation;
use App\Services\AccountBalances;
use App\Services\ReconciliationService;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class ReconciliationController extends Controller
{
    /** Pick-a-bank-account landing page (spec §7.9) — mirrors Banking/Index's account list. */
    public function index(AccountBalances $balances): Response
    {
        $balancesByAccount = $balances->asOf();

        $bankAccounts = BankAccount::query()
            ->where('is_archived', false)
            ->orderBy('name')
            ->get()
            ->map(fn (BankAccount $account) => [
                'id' => $account->id,
                'name' => $account->name,
                'currency_code' => $account->currency_code,
                'balance' => $account->balance($balancesByAccount),
            ])
            ->values();

        return Inertia::render('Reconciliation/Index', [
            'bankAccounts' => $bankAccounts,
        ]);
    }

    /**
     * One bank account's reconciliation workspace: its completed/reopened
     * history (Reopen only ever eligible on the newest completed row) plus,
     * when a statement date + ending balance are given as query params, a
     * READ-only preview of the unreconciled lines and the opening balance to
     * compute the live difference against (ReconciliationService::start()).
     * $bankAccount is a plain route parameter (not implicit binding) — same
     * reasoning as every other controller in this app (PROJECT_MAP §9 rule
     * 7): SubstituteBindings runs before SetCompanyContext.
     */
    public function show(Request $request, string $bankAccount, ReconciliationService $service): Response
    {
        $account = BankAccount::query()->findOrFail($bankAccount);

        $statementDate = $request->query('statement_date');
        $statementEndingBalance = $request->query('statement_ending_balance');

        $preview = null;
        if (is_string($statementDate) && $statementDate !== '' && is_numeric($statementEndingBalance)) {
            $preview = $service->start(
                $account,
                CarbonImmutable::parse($statementDate),
                (int) $statementEndingBalance,
            );
        }

        return Inertia::render('Reconciliation/Show', [
            'bankAccount' => [
                'id' => $account->id,
                'name' => $account->name,
                'currency_code' => $account->currency_code,
            ],
            'history' => $this->history($account),
            'preview' => $preview,
            'statementDate' => is_string($statementDate) ? $statementDate : null,
        ]);
    }

    /**
     * Completes a reconciliation (spec §7.9). ReconciliationOutOfBalance
     * (difference != 0) and ReconciliationLineIneligible (a ticked line
     * fails one of its three eligibility checks) both surface as a 422 on a
     * generic `status` field — mirrors InvoiceController::recordPayment,
     * there's no single natural form field for a whole-statement guard like
     * this.
     */
    public function complete(CompleteReconciliationRequest $request, string $bankAccount, ReconciliationService $service): RedirectResponse
    {
        $account = BankAccount::query()->findOrFail($bankAccount);
        $data = $request->validated();

        try {
            $service->complete(
                $data['idempotency_key'],
                $account,
                CarbonImmutable::parse($data['statement_date']),
                (int) $data['statement_ending_balance'],
                array_map('intval', $data['ticked_journal_line_ids']),
            );
        } catch (ReconciliationException $e) {
            throw ValidationException::withMessages(['status' => $e->getMessage()]);
        }

        return redirect()->route('reconciliations.show', $account->id);
    }

    /**
     * Reopens a reconciliation — reconciliation.manage (route middleware),
     * newest-first enforced by the service. $reconciliation is a plain route
     * parameter, same reasoning as $bankAccount above.
     */
    public function reopen(Request $request, string $reconciliation, ReconciliationService $service): RedirectResponse
    {
        $model = Reconciliation::query()->findOrFail($reconciliation);
        $idempotencyKey = $this->requireIdempotencyKey($request);

        try {
            $service->reopen($idempotencyKey, $model);
        } catch (ReconciliationException $e) {
            throw ValidationException::withMessages(['status' => $e->getMessage()]);
        }

        return redirect()->route('reconciliations.show', $model->bank_account_id);
    }

    /**
     * @return list<array{
     *     id: int, statement_date: string, statement_ending_balance: int,
     *     opening_reconciled_balance: int, status: string, completed_at: string,
     *     can_reopen: bool,
     * }>
     */
    private function history(BankAccount $account): array
    {
        $reconciliations = Reconciliation::query()
            ->where('bank_account_id', $account->id)
            ->orderByDesc('statement_date')
            ->orderByDesc('id')
            ->get();

        // The only reconciliation eligible for Reopen is the account's
        // newest COMPLETED one (spec §7.9 newest-first) — mirrors
        // ReconciliationService::latestCompleted()'s own ordering exactly,
        // so the UI's affordance and the service's guard never disagree.
        $newestCompletedId = $reconciliations->firstWhere('status', 'completed')?->id;

        return $reconciliations->map(fn (Reconciliation $r) => [
            'id' => $r->id,
            'statement_date' => $r->statement_date->toDateString(),
            'statement_ending_balance' => $r->statement_ending_balance,
            'opening_reconciled_balance' => $r->opening_reconciled_balance,
            'status' => $r->status,
            'completed_at' => $r->completed_at->toIso8601String(),
            'can_reopen' => $r->status === 'completed' && $r->id === $newestCompletedId,
        ])->values()->all();
    }

    /**
     * Extracts and validates the X-Idempotency-Key header (a UUID) — mirrors
     * InvoiceController's own identical helper.
     */
    private function requireIdempotencyKey(Request $request): string
    {
        $idempotencyKey = $request->header('X-Idempotency-Key');
        if (! is_string($idempotencyKey) || ! Str::isUuid($idempotencyKey)) {
            throw ValidationException::withMessages([
                'idempotency_key' => 'A valid X-Idempotency-Key header (UUID) is required.',
            ]);
        }

        return $idempotencyKey;
    }
}
