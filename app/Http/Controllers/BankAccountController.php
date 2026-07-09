<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreBankAccountRequest;
use App\Http\Requests\UpdateBankAccountRequest;
use App\Models\BankAccount;
use App\Services\AccountBalances;
use App\Services\BankAccountService;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

class BankAccountController extends Controller
{
    public function index(AccountBalances $balances): Response
    {
        $balancesByAccount = $balances->asOf();

        $bankAccounts = BankAccount::query()
            ->with('ledgerAccount')
            ->orderBy('name')
            ->get()
            ->map(fn (BankAccount $bankAccount) => $this->payload($bankAccount, $balancesByAccount))
            ->values();

        return Inertia::render('Banking/Index', [
            'bankAccounts' => $bankAccounts,
        ]);
    }

    public function store(StoreBankAccountRequest $request, BankAccountService $service): RedirectResponse
    {
        $service->create($request->validated());

        return redirect()->route('bank-accounts.index');
    }

    /**
     * $bankAccount is deliberately a plain route parameter, not an implicit
     * Eloquent binding: BankAccount's BelongsToCompany global scope requires
     * CompanyContext to already be set, but SubstituteBindings (which
     * resolves implicit bindings) runs as part of the framework's "web"
     * middleware group — earlier than this route's own auth/company/can
     * middleware, and earlier than SetCompanyContext. Doing the lookup here,
     * inside the controller action, guarantees the company context is
     * already in place. Mirrors ContactController/AccountController.
     */
    public function update(UpdateBankAccountRequest $request, string $bankAccount, BankAccountService $service): RedirectResponse
    {
        $model = BankAccount::query()->findOrFail($bankAccount);
        $service->rename($model, $request->validated()['name']);

        return redirect()->route('bank-accounts.index');
    }

    public function archive(string $bankAccount, BankAccountService $service): RedirectResponse
    {
        $model = BankAccount::query()->findOrFail($bankAccount);
        $service->archive($model);

        return redirect()->route('bank-accounts.index');
    }

    public function unarchive(string $bankAccount, BankAccountService $service): RedirectResponse
    {
        $model = BankAccount::query()->findOrFail($bankAccount);
        $service->unarchive($model);

        return redirect()->route('bank-accounts.index');
    }

    /** @return array<string, mixed> */
    private function payload(BankAccount $bankAccount, array $balancesByAccount): array
    {
        return [
            'id' => $bankAccount->id,
            'name' => $bankAccount->name,
            'type' => $bankAccount->type,
            'currency_code' => $bankAccount->currency_code,
            'is_archived' => $bankAccount->is_archived,
            'balance' => $bankAccount->balance($balancesByAccount),
        ];
    }
}
