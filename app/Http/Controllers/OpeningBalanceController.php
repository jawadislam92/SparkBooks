<?php

namespace App\Http\Controllers;

use App\Exceptions\Ledger\LedgerException;
use App\Exceptions\OpeningBalancesLocked;
use App\Http\Requests\StoreOpeningBalanceRequest;
use App\Models\BankAccount;
use App\Models\Contact;
use App\Models\OpeningBalanceRun;
use App\Services\OpeningBalanceService;
use App\Support\CompanyContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class OpeningBalanceController extends Controller
{
    public function create(CompanyContext $context): Response
    {
        $bankAccounts = BankAccount::query()
            ->where('is_archived', false)
            ->orderBy('name')
            ->get()
            ->map(fn (BankAccount $bankAccount) => [
                'id' => $bankAccount->id,
                'name' => $bankAccount->name,
                'currency_code' => $bankAccount->currency_code,
            ])
            ->values();

        $contacts = Contact::query()->where('is_archived', false)->orderBy('name')->get();

        $customers = $contacts->filter(fn (Contact $contact) => $contact->type->isCustomer())
            ->map(fn (Contact $contact) => ['id' => $contact->id, 'name' => $contact->name])
            ->values();

        $vendors = $contacts->filter(fn (Contact $contact) => $contact->type->isVendor())
            ->map(fn (Contact $contact) => ['id' => $contact->id, 'name' => $contact->name])
            ->values();

        $activeRun = OpeningBalanceRun::query()->where('status', 'active')->with('openingDocuments')->first();

        return Inertia::render('Setup/OpeningBalances', [
            'bankAccounts' => $bankAccounts,
            'customers' => $customers,
            'vendors' => $vendors,
            'booksStartDate' => $context->get()->books_start_date?->toDateString(),
            'activeRun' => $activeRun === null ? null : [
                'id' => $activeRun->id,
                'books_start_date' => $activeRun->books_start_date->toDateString(),
                'customer_count' => $activeRun->openingDocuments->where('kind', 'receivable')->count(),
                'vendor_count' => $activeRun->openingDocuments->where('kind', 'payable')->count(),
                'total_receivable' => (int) $activeRun->openingDocuments->where('kind', 'receivable')->sum('amount'),
                'total_payable' => (int) $activeRun->openingDocuments->where('kind', 'payable')->sum('amount'),
            ],
        ]);
    }

    public function store(StoreOpeningBalanceRequest $request, OpeningBalanceService $service): RedirectResponse
    {
        try {
            $service->apply($request->header('X-Idempotency-Key'), $request->validated());
        } catch (OpeningBalancesLocked $e) {
            // Setup-specific re-run guard (spec §7.1) — surfaced on the
            // books-start-date field, the natural home for a setup-wizard-wide
            // error since there's no single line item it belongs to.
            throw ValidationException::withMessages(['books_start_date' => $e->getMessage()]);
        } catch (LedgerException $e) {
            // The only LedgerException this can realistically hit is a locked
            // period (contact ids and account eligibility are already
            // enforced above, and the entry is built to always balance).
            throw ValidationException::withMessages(['books_start_date' => $e->getMessage()]);
        }

        return redirect()->route('setup.opening-balances');
    }
}
