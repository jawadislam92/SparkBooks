<?php

namespace App\Http\Controllers;

use App\Enums\AccountSubtype;
use App\Exceptions\Ledger\LedgerException;
use App\Http\Requests\StoreManualJournalRequest;
use App\Models\Account;
use App\Models\ManualJournal;
use App\Services\ManualJournalService;
use Illuminate\Database\QueryException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class ManualJournalController extends Controller
{
    public function index(): Response
    {
        $journals = ManualJournal::query()
            ->with('journalEntry.lines')
            ->orderByDesc('entry_date')
            ->orderByDesc('id')
            ->paginate(20);

        return Inertia::render('Journal/Index', [
            'journals' => $journals,
        ]);
    }

    public function create(): Response
    {
        $accounts = Account::query()
            ->where('is_archived', false)
            ->whereNotIn('subtype', [AccountSubtype::Receivable->value, AccountSubtype::Payable->value])
            ->orderBy('code')
            ->get(['id', 'code', 'name', 'type'])
            ->map(fn (Account $account) => [
                'id' => $account->id,
                'code' => $account->code,
                'name' => $account->name,
                'type' => $account->type->value,
            ])
            ->values();

        return Inertia::render('Journal/New', [
            'accounts' => $accounts,
        ]);
    }

    public function store(StoreManualJournalRequest $request, ManualJournalService $service): RedirectResponse
    {
        try {
            $service->post($request->header('X-Idempotency-Key'), $request->validated());
        } catch (LedgerException $e) {
            throw ValidationException::withMessages(['lines' => $e->getMessage()]);
        }

        return redirect()->route('journal-entries.index');
    }

    /**
     * $manualJournal is deliberately a plain route parameter, NOT an
     * implicit Eloquent binding: ManualJournal's BelongsToCompany global
     * scope requires CompanyContext to already be set, but SubstituteBindings
     * (which resolves implicit bindings) runs as part of the framework's
     * "web" middleware group — earlier than this route's own
     * auth/company/can middleware, and earlier than SetCompanyContext. Doing
     * the lookup here, inside the controller action, guarantees the company
     * context is already in place.
     */
    public function reverse(Request $request, string $manualJournal, ManualJournalService $service): RedirectResponse
    {
        $journal = ManualJournal::query()->findOrFail($manualJournal);

        $validated = $request->validate([
            'date' => ['required', 'date'],
            'memo' => ['required', 'string', 'max:500'],
        ]);

        try {
            $service->reverse($journal, $validated['date'], $validated['memo']);
        } catch (LedgerException $e) {
            throw ValidationException::withMessages(['memo' => $e->getMessage()]);
        } catch (QueryException $e) {
            throw ValidationException::withMessages(['memo' => 'This entry has already been reversed.']);
        }

        return redirect()->route('journal-entries.index');
    }
}
