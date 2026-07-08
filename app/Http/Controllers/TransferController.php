<?php

namespace App\Http\Controllers;

use App\Exceptions\Ledger\LedgerException;
use App\Http\Requests\StoreTransferRequest;
use App\Models\BankAccount;
use App\Services\AccountBalances;
use App\Services\TransferService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class TransferController extends Controller
{
    public function create(AccountBalances $balances): Response
    {
        $balancesByAccount = $balances->asOf();

        $bankAccounts = BankAccount::query()
            ->where('is_archived', false)
            ->orderBy('name')
            ->get()
            ->map(fn (BankAccount $bankAccount) => [
                'id' => $bankAccount->id,
                'name' => $bankAccount->name,
                'currency_code' => $bankAccount->currency_code,
                'balance' => $bankAccount->balance($balancesByAccount),
            ])
            ->values();

        return Inertia::render('Banking/Transfer', [
            'bankAccounts' => $bankAccounts,
        ]);
    }

    public function store(StoreTransferRequest $request, TransferService $service): RedirectResponse
    {
        try {
            $service->transfer($request->header('X-Idempotency-Key'), $request->validated());
        } catch (LedgerException $e) {
            // The only LedgerException a transfer can realistically hit is a
            // locked period (both ledger accounts are never archived/control
            // accounts by construction, and the entry always balances) — the
            // date field is the most natural place to surface it.
            throw ValidationException::withMessages(['transfer_date' => $e->getMessage()]);
        }

        return redirect()->route('bank-accounts.index');
    }
}
