<?php

namespace App\Http\Controllers;

use App\Enums\AccountSubtype;
use App\Exceptions\Ledger\LedgerException;
use App\Http\Requests\StoreManualJournalRequest;
use App\Models\Account;
use App\Models\ManualJournal;
use App\Services\ManualJournalService;
use App\Support\AuditLogger;
use App\Support\CsvExport;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ManualJournalController extends Controller
{
    public function index(): Response
    {
        $journals = ManualJournal::query()
            ->with(['journalEntry.lines.account', 'creator'])
            ->orderByDesc('entry_date')
            ->orderByDesc('id')
            ->paginate(20)
            ->through(fn (ManualJournal $journal) => [
                'id' => $journal->id,
                'entry_date' => $journal->entry_date->toDateString(),
                'memo' => $journal->memo,
                'status' => $journal->status,
                'created_by' => $journal->creator?->name,
                'journal_entry' => $journal->journalEntry === null ? null : [
                    'id' => $journal->journalEntry->id,
                    'lines' => $journal->journalEntry->lines->map(fn ($line) => [
                        'id' => $line->id,
                        'debit' => $line->debit,
                        'credit' => $line->credit,
                        'memo' => $line->memo,
                        'account' => [
                            'id' => $line->account->id,
                            'code' => $line->account->code,
                            'name' => $line->account->name,
                        ],
                    ])->values(),
                ],
            ]);

        return Inertia::render('Journal/Index', [
            'journals' => $journals,
        ]);
    }

    /**
     * List-screen CSV export (Phase 6 Task 3, spec §11/§17.8) — the SAME
     * scope Journal/Index reads (every manual journal for the current
     * company), optionally narrowed by `status` (posted/reversed) and an
     * `entry_date` range (`from`/`to`) — the one list export in this task
     * with a genuine period filter, since a manual journal (unlike
     * invoices/bills/expenses) has no other natural single-value filter.
     * Amount is the entry's debit total (== credit total by construction —
     * §12.9 — since LedgerService refuses an unbalanced post). Gated
     * `exports.run` in addition to this controller's own `ledger.post_manual`
     * group (routes/web.php). Audit-logged via the shared CsvExport helper.
     */
    public function exportCsv(Request $request, AuditLogger $audit): StreamedResponse
    {
        $validated = $request->validate([
            'status' => ['nullable', 'string', Rule::in(['posted', 'reversed'])],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date'],
        ]);
        $status = $validated['status'] ?? null;
        $from = isset($validated['from']) ? CarbonImmutable::parse($validated['from']) : null;
        $to = isset($validated['to']) ? CarbonImmutable::parse($validated['to']) : null;

        $journals = ManualJournal::query()
            ->with(['journalEntry.lines', 'creator'])
            ->when($status !== null, fn ($query) => $query->where('status', $status))
            ->when($from !== null, fn ($query) => $query->whereDate('entry_date', '>=', $from->toDateString()))
            ->when($to !== null, fn ($query) => $query->whereDate('entry_date', '<=', $to->toDateString()))
            ->orderByDesc('entry_date')
            ->orderByDesc('id')
            ->get();

        $rows = $journals->map(function (ManualJournal $journal) {
            $amount = (int) $journal->journalEntry?->lines->sum('debit');

            return [
                $journal->entry_date->toDateString(),
                $journal->memo,
                $journal->status,
                $journal->creator?->name,
                Money::minorToDecimalString($amount),
            ];
        });

        return CsvExport::stream(
            'journal-entries.csv',
            ['Date', 'Memo', 'Status', 'Created By', 'Amount'],
            $rows,
            $audit,
            'journal_entries.exported',
            [
                'status' => $status ?? 'all',
                'from' => $from?->toDateString(),
                'to' => $to?->toDateString(),
            ],
        );
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
        } catch (UniqueConstraintViolationException $e) {
            // ONLY a collision on the (company_id, source_type, source_id,
            // entry_role) index means "already reversed" — a second reversal
            // tries to insert a duplicate reversal JournalEntry for the same
            // source. Any OTHER unique-constraint failure (or, since we no
            // longer catch the broad QueryException, any deadlock / lock-wait
            // timeout / connection drop) must propagate untouched so the
            // global handler + Sentry see the real production failure in this
            // money path — spec §9 forbids swallowing it. Mirrors the narrow
            // classification in IdempotencyService::isIdempotencyKeyCollision.
            if (! $this->isDuplicateReversal($e)) {
                throw $e;
            }

            throw ValidationException::withMessages(['memo' => 'This entry has already been reversed.']);
        }

        return redirect()->route('journal-entries.index');
    }

    /**
     * Whether the violated unique index is the journal_entries source-unique
     * index (one primary + one reversal max per source). Prefers the driver's
     * populated index name, falling back to a message substring for
     * drivers/situations where ->index isn't set (MySQL always includes the
     * violated key name in the duplicate-entry message).
     */
    private function isDuplicateReversal(UniqueConstraintViolationException $e): bool
    {
        if ($e->index !== null) {
            return $e->index === 'journal_entries_source_unique';
        }

        return str_contains($e->getMessage(), 'journal_entries_source_unique');
    }
}
