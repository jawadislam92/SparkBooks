<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreBankAccountRequest;
use App\Http\Requests\UpdateBankAccountRequest;
use App\Models\BankAccount;
use App\Services\AccountBalances;
use App\Services\BankAccountService;
use App\Support\AuditLogger;
use App\Support\CsvExport;
use App\Support\Money;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

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

    /**
     * List-screen CSV export (Phase 6 Task 3, spec §11/§17.8) — the bank/
     * cash accounts list (Banking/Index's own scope: every bank account for
     * the current company, archived included, current live balance —
     * mirrors index() exactly, including its balancesByAccount batching so
     * this doesn't run one whole-ledger query per row). There is no separate
     * bank-TRANSACTIONS list screen in the app yet (transfers only have a
     * create form, no index) — this is the closest existing "Banking" list
     * screen, so it's the one this task's brief maps to. Gated `exports.run`
     * in addition to this controller's own `bank_accounts.manage` group
     * (routes/web.php). Audit-logged via the shared CsvExport helper.
     */
    public function exportCsv(AccountBalances $balances, AuditLogger $audit): StreamedResponse
    {
        $balancesByAccount = $balances->asOf();

        $bankAccounts = BankAccount::query()
            ->orderBy('name')
            ->get();

        $rows = $bankAccounts->map(fn (BankAccount $bankAccount) => [
            $bankAccount->name,
            $bankAccount->type,
            $bankAccount->currency_code,
            Money::minorToDecimalString($bankAccount->balance($balancesByAccount)),
            $bankAccount->is_archived ? 'Yes' : 'No',
        ]);

        return CsvExport::stream(
            'bank-accounts.csv',
            ['Name', 'Type', 'Currency', 'Balance', 'Archived'],
            $rows,
            $audit,
            'bank_accounts.exported',
        );
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
