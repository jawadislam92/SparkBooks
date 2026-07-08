<?php

namespace App\Http\Controllers;

use App\Enums\AccountSubtype;
use App\Exceptions\Ledger\LedgerException;
use App\Http\Requests\StoreOtherReceiptRequest;
use App\Models\Account;
use App\Models\BankAccount;
use App\Services\AccountBalances;
use App\Services\OtherReceiptService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class OtherReceiptController extends Controller
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

        // Eligible category (credit-side) accounts: non-archived, and neither
        // a control account (AR/AP) nor a bank/cash account — those are
        // excluded because money-in that isn't an invoice must never fake a
        // receivable, and moving money into a bank/cash account is a transfer.
        $categoryAccounts = Account::query()
            ->where('is_archived', false)
            ->whereNotIn('subtype', [
                AccountSubtype::Receivable->value,
                AccountSubtype::Payable->value,
                AccountSubtype::Cash->value,
                AccountSubtype::Bank->value,
            ])
            ->orderBy('code')
            ->get(['id', 'code', 'name', 'type'])
            ->map(fn (Account $account) => [
                'id' => $account->id,
                'code' => $account->code,
                'name' => $account->name,
                'type' => $account->type->value,
            ])
            ->values();

        return Inertia::render('Banking/OtherReceipt', [
            'bankAccounts' => $bankAccounts,
            'categoryAccounts' => $categoryAccounts,
        ]);
    }

    public function store(StoreOtherReceiptRequest $request, OtherReceiptService $service): RedirectResponse
    {
        try {
            $service->record($request->header('X-Idempotency-Key'), $request->validated());
        } catch (LedgerException $e) {
            // The only LedgerException an other-receipt can realistically hit
            // is a locked period (the category account's eligibility is
            // already enforced by StoreOtherReceiptRequest, and the entry
            // always balances) — the date field is the most natural place to
            // surface it. Mirrors TransferController.
            throw ValidationException::withMessages(['receipt_date' => $e->getMessage()]);
        }

        return redirect()->route('bank-accounts.index');
    }
}
