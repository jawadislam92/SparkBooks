<?php

namespace App\Http\Controllers;

use App\Enums\ExpenseStatus;
use App\Exceptions\AttachmentRejected;
use App\Exceptions\Documents\DocumentPostException;
use App\Exceptions\Documents\DocumentVoidException;
use App\Exceptions\IneligibleCategoryAccount;
use App\Exceptions\Ledger\LedgerException;
use App\Http\Requests\StoreAttachmentRequest;
use App\Http\Requests\StoreExpenseRequest;
use App\Http\Requests\UpdateExpenseRequest;
use App\Models\Account;
use App\Models\Attachment;
use App\Models\BankAccount;
use App\Models\Contact;
use App\Models\Expense;
use App\Models\ExpenseLine;
use App\Models\TaxRate;
use App\Services\AttachmentService;
use App\Services\DocumentLineCalculator;
use App\Services\ExpenseService;
use App\Support\AuditLogger;
use App\Support\CategoryAccountGuard;
use App\Support\CsvExport;
use App\Support\Money;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Draft-only expense CRUD (Phase 4 Task 2) — the AP-side mirror of
 * InvoiceController's own draft CRUD (Phase 3 Task 2). No ledger posting: an
 * expense created/edited here always has status=draft. Only a draft expense
 * is editable/deletable — ExpenseLine::booted() enforces the same rule at
 * the model layer as a defense-in-depth backstop below the checks in
 * update()/destroy() here. "Record" (posting P4/P5) is a later Phase 4 task.
 */
class ExpenseController extends Controller
{
    private const AUDITED_FIELDS = [
        'contact_id',
        'expense_date',
        'bank_account_id',
        'notes',
        'subtotal',
        'tax_total',
        'total',
    ];

    public function index(): Response
    {
        $expenses = Expense::query()
            // lines.categoryAccount is only for the list row's "categories"
            // summary (Phase 4 Task 9) — a read-only display enrichment, not
            // a posting-logic change.
            ->with(['contact', 'lines.categoryAccount:id,name'])
            ->orderByDesc('id')
            ->get()
            ->map(fn (Expense $expense) => $this->expenseListPayload($expense))
            ->values();

        return Inertia::render('Expenses/Index', [
            'expenses' => $expenses,
        ]);
    }

    /**
     * List-screen CSV export (Phase 6 Task 3, spec §11/§17.8) — mirrors
     * InvoiceController::exportCsv/BillController::exportCsv exactly: same
     * scope as Expenses/Index (every expense for the current company),
     * optionally narrowed by the same `status` values the index's
     * client-side filter offers. Gated `exports.run` in addition to this
     * controller's own `expenses.draft` group (routes/web.php). Audit-logged
     * via the shared CsvExport helper.
     */
    public function exportCsv(Request $request, AuditLogger $audit): StreamedResponse
    {
        $validated = $request->validate([
            'status' => ['nullable', 'string', Rule::enum(ExpenseStatus::class)],
        ]);
        $status = $validated['status'] ?? null;

        $expenses = Expense::query()
            ->with('contact')
            ->when($status !== null, fn ($query) => $query->where('status', $status))
            ->orderByDesc('id')
            ->get();

        $rows = $expenses->map(fn (Expense $expense) => [
            $expense->contact?->name,
            $expense->expense_date?->toDateString(),
            $expense->status->value,
            Money::minorToDecimalString($expense->total),
        ]);

        return CsvExport::stream(
            'expenses.csv',
            ['Payee', 'Date', 'Status', 'Total'],
            $rows,
            $audit,
            'expenses.exported',
            ['status' => $status ?? 'all'],
        );
    }

    public function create(): Response
    {
        return Inertia::render('Expenses/Edit', array_merge(
            ['expense' => null],
            $this->formOptions(),
        ));
    }

    public function store(StoreExpenseRequest $request, AuditLogger $audit): RedirectResponse
    {
        $data = $request->validated();

        $expense = DB::transaction(function () use ($data, $audit) {
            $computed = DocumentLineCalculator::compute($this->calculatorLines($data['lines']));

            $expense = Expense::query()->create([
                'contact_id' => $data['contact_id'] ?? null,
                'status' => ExpenseStatus::Draft->value,
                'expense_date' => $data['expense_date'] ?? null,
                'bank_account_id' => $data['bank_account_id'] ?? null,
                'subtotal' => $computed['subtotal'],
                'tax_total' => $computed['tax_total'],
                'total' => $computed['total'],
                'notes' => $data['notes'] ?? null,
                'created_by' => auth()->id(),
            ]);

            $this->createLines($expense, $data['lines'], $computed['lines']);

            $audit->log('expense.draft_created', $expense, after: $expense->only(self::AUDITED_FIELDS));

            return $expense;
        });

        return redirect()->route('expenses.edit', $expense->id);
    }

    /**
     * $expense is deliberately a plain route parameter, not an implicit
     * Eloquent binding — SubstituteBindings runs before SetCompanyContext
     * (see InvoiceController for the full reasoning, mirrored throughout
     * this controller).
     */
    public function edit(string $expense): Response
    {
        $model = Expense::query()->with(['lines', 'attachments' => fn ($query) => $query->orderByDesc('id')])->findOrFail($expense);

        return Inertia::render('Expenses/Edit', array_merge(
            ['expense' => $this->expenseEditPayload($model)],
            $this->formOptions(),
        ));
    }

    /**
     * The expense detail screen (Phase 4 Task 9) — the canonical landing
     * page for a RECORDED expense (record()/void() below both redirect back
     * here) and also viewable for a draft (Edit remains the draft EDITOR;
     * Show is the read-only detail + action-bar view for every status).
     * Mirrors InvoiceController::show exactly, including the gate reasoning:
     * every role that can see an expense at all (Owner/Admin/Accountant/
     * Staff hold expenses.draft) may view this page; the narrower actions it
     * renders (Record/Void) are gated client-side on expenses.record/
     * expenses.void (shared via HandleInertiaRequests::NAV_ABILITIES), with
     * both routes independently gated server-side too. $expense is a plain
     * route parameter, same SubstituteBindings-ordering reasoning as every
     * other action in this controller.
     */
    public function show(string $expense): Response
    {
        $model = Expense::query()
            ->with([
                'contact:id,name',
                'bankAccount:id,name',
                'lines' => fn ($query) => $query->orderBy('id'),
                'lines.categoryAccount:id,code,name',
                'lines.taxRate:id,name',
                'attachments' => fn ($query) => $query->orderByDesc('id'),
            ])
            ->findOrFail($expense);

        return Inertia::render('Expenses/Show', [
            'expense' => $this->expenseShowPayload($model),
        ]);
    }

    public function update(UpdateExpenseRequest $request, string $expense, AuditLogger $audit): RedirectResponse
    {
        $model = Expense::query()->findOrFail($expense);

        if (! $model->status->canEdit()) {
            throw ValidationException::withMessages([
                'status' => 'Only draft expenses can be edited. Void and re-enter a recorded expense instead.',
            ]);
        }

        $data = $request->validated();
        $before = $model->only(self::AUDITED_FIELDS);

        DB::transaction(function () use ($model, $data) {
            $computed = DocumentLineCalculator::compute($this->calculatorLines($data['lines']));

            $model->update([
                'contact_id' => $data['contact_id'] ?? null,
                'expense_date' => $data['expense_date'] ?? null,
                'bank_account_id' => $data['bank_account_id'] ?? null,
                'subtotal' => $computed['subtotal'],
                'tax_total' => $computed['tax_total'],
                'total' => $computed['total'],
                'notes' => $data['notes'] ?? null,
            ]);

            // Replace lines wholesale: iterate + delete individually (rather
            // than a bulk query-builder delete) so ExpenseLine::booted()'s
            // freeze guard actually fires per row — defense in depth in case
            // this method is ever reached for a non-draft expense despite
            // the canEdit() check above. Mirrors InvoiceController::update.
            $model->lines()->get()->each(fn (ExpenseLine $line) => $line->delete());

            $this->createLines($model, $data['lines'], $computed['lines']);
        });

        $audit->log('expense.draft_updated', $model, before: $before, after: $model->only(self::AUDITED_FIELDS));

        return redirect()->route('expenses.edit', $model->id);
    }

    /**
     * Records a draft expense — posting rule P4/P5 (Dr category per line, Dr
     * ONE combined 2100 Tax Payable line if any tax, Cr the paid-from bank/
     * cash account for the gross total) via ExpenseService, flipping the
     * draft to Recorded. $expense is a plain route parameter (not implicit
     * binding), same reasoning as edit()/update()/destroy() above —
     * SubstituteBindings runs before SetCompanyContext.
     *
     * The idempotency key travels in a header (like every posting action in
     * this app — mirrors InvoiceController::send). Every guard failure
     * (IneligibleCategoryAccount, this service's own DocumentPostException
     * family, or a LedgerException such as a locked period) surfaces as a
     * 422 on a generic `status` field — there's no single natural form field
     * for a whole-document action like this one.
     */
    public function record(Request $request, string $expense, ExpenseService $service): RedirectResponse
    {
        $model = Expense::query()->findOrFail($expense);

        $idempotencyKey = $request->header('X-Idempotency-Key');
        if (! is_string($idempotencyKey) || ! Str::isUuid($idempotencyKey)) {
            throw ValidationException::withMessages([
                'idempotency_key' => 'A valid X-Idempotency-Key header (UUID) is required.',
            ]);
        }

        try {
            $service->record($idempotencyKey, $model);
        } catch (IneligibleCategoryAccount|DocumentPostException|LedgerException $e) {
            throw ValidationException::withMessages(['status' => $e->getMessage()]);
        }

        // Phase 4 Task 9: Show is now the canonical landing page for a
        // recorded expense — mirrors InvoiceController::send redirecting to
        // invoices.show, not invoices.edit.
        return redirect()->route('expenses.show', $model->id);
    }

    public function destroy(string $expense, AuditLogger $audit, AttachmentService $attachments): RedirectResponse
    {
        $model = Expense::query()->findOrFail($expense);

        if (! $model->status->canEdit()) {
            throw ValidationException::withMessages([
                'status' => 'Only draft expenses can be deleted. Void a recorded expense instead.',
            ]);
        }

        $before = $model->only(self::AUDITED_FIELDS);

        DB::transaction(function () use ($model, $attachments) {
            $model->lines()->get()->each(fn (ExpenseLine $line) => $line->delete());
            // Attachments cascade too (Task 8): rows now, disk files once
            // this transaction commits — otherwise deleting a draft that
            // had a receipt would orphan the Attachment row AND leak the
            // private file. The single expense.draft_deleted audit row
            // below covers the cascade; deleteAllFor deliberately writes
            // no per-receipt audit rows (see its docblock).
            $attachments->deleteAllFor($model);
            $model->delete();
        });

        $audit->log('expense.draft_deleted', $model, before: $before);

        return redirect()->route('expenses.index');
    }

    /**
     * Voids a recorded expense — posting rule P9 (spec §6): a mirror
     * reversal of the expense's own P4/P5 entry, via ExpenseService::void.
     * $expense is a plain route parameter (not implicit binding), same
     * reasoning as edit()/update()/destroy()/record() above.
     *
     * Three failure shapes surface as a 422 on the generic `status` field,
     * mirroring InvoiceController::void: DocumentVoidException (this
     * service's own domain guards — not voidable, already voided), a
     * LedgerException (e.g. a locked period on today's reversal date), and —
     * as defense in depth for a genuine concurrent double-void race,
     * mirroring InvoiceController::void's identical backstop — a
     * UniqueConstraintViolationException on the journal_entries
     * source-unique index (in practice unreachable in a single-process
     * request because the service's own lockForUpdate()+status-guard
     * already serializes this, but kept as a backstop rather than trusting
     * that guard alone).
     */
    public function void(Request $request, string $expense, ExpenseService $service): RedirectResponse
    {
        $model = Expense::query()->findOrFail($expense);

        $idempotencyKey = $request->header('X-Idempotency-Key');
        if (! is_string($idempotencyKey) || ! Str::isUuid($idempotencyKey)) {
            throw ValidationException::withMessages([
                'idempotency_key' => 'A valid X-Idempotency-Key header (UUID) is required.',
            ]);
        }

        try {
            $service->void($idempotencyKey, $model);
        } catch (DocumentVoidException|LedgerException $e) {
            throw ValidationException::withMessages(['status' => $e->getMessage()]);
        } catch (UniqueConstraintViolationException $e) {
            if (! $this->isDuplicateReversal($e)) {
                throw $e;
            }

            throw ValidationException::withMessages(['status' => 'This expense has already been voided.']);
        }

        // Phase 4 Task 9: mirrors record() above — Show is the canonical
        // landing page, mirrors InvoiceController::void -> invoices.show.
        return redirect()->route('expenses.show', $model->id);
    }

    /**
     * Uploads a receipt/bill-scan attachment onto an expense (spec §17.8,
     * Phase 4 Task 8) — gated by expenses.draft, the SAME (broad, all-four-
     * roles) ability as the rest of this route group: attachments are
     * addable anytime, even once the expense has left draft status
     * (receipts genuinely do arrive late) — a deliberate exception to the
     * "only a draft is editable" rule the other actions above enforce, not
     * an oversight. $expense is a plain route parameter, same
     * SubstituteBindings-ordering reasoning as every other action here.
     * StoreAttachmentRequest is the friendly-422 half of the hardening
     * (size + content-sniff); AttachmentRejected is AttachmentService's own
     * caller-independent backstop for the same two guards.
     */
    public function storeAttachment(StoreAttachmentRequest $request, string $expense, AttachmentService $service): RedirectResponse
    {
        $model = Expense::query()->findOrFail($expense);

        try {
            $service->store($model, $request->file('file'));
        } catch (AttachmentRejected $e) {
            throw ValidationException::withMessages(['file' => $e->getMessage()]);
        }

        // Phase 4 Task 9: AttachmentSection now renders from BOTH Edit (the
        // draft editor) and Show (the canonical detail page for every
        // status), so this returns to whichever one the browser's own
        // Referer header names (back()) rather than a single fixed route —
        // a same-origin fetch/XHR (what Inertia's router issues) always
        // carries that header. The fallback (used when no Referer is
        // present, e.g. a plain HTTP test request) is expenses.show, since
        // that's the canonical destination for every OTHER whole-document
        // action in this controller.
        return redirect()->back(fallback: route('expenses.show', $model->id));
    }

    /**
     * Streams an attachment's original bytes back — the ONLY way one is
     * ever read (spec §17.8: authenticated, tenant-scoped, gated route).
     * Gated by expenses.draft, same group as storeAttachment above (anyone
     * who can see the expense can pull its receipts). $attachment is
     * resolved off the EXPENSE's own relation (not Attachment::findOrFail
     * directly), so an attachment belonging to a DIFFERENT expense — or a
     * Bill's attachment reached via this route — 404s here too, not just a
     * foreign company's. Mirrors BillController::voidPayment's nested
     * scoped findOrFail exactly.
     */
    public function downloadAttachment(string $expense, string $attachment, AttachmentService $service): StreamedResponse
    {
        $model = Expense::query()->findOrFail($expense);
        $attachmentModel = $model->attachments()->findOrFail($attachment);

        return $service->download($attachmentModel);
    }

    /**
     * Deletes an attachment — gated by expenses.void (Owner/Admin/
     * Accountant only, spec §17.8's decision), a narrower ability than the
     * expenses.draft upload/download gate above. $attachment resolved off
     * the expense's own relation, same nested-scoped reasoning as
     * downloadAttachment above.
     */
    public function destroyAttachment(string $expense, string $attachment, AttachmentService $service): RedirectResponse
    {
        $model = Expense::query()->findOrFail($expense);
        $attachmentModel = $model->attachments()->findOrFail($attachment);

        $service->delete($attachmentModel);

        // Mirrors storeAttachment's own back()-with-fallback reasoning above.
        return redirect()->back(fallback: route('expenses.show', $model->id));
    }

    /**
     * Whether the violated unique index is the journal_entries source-unique
     * index (one primary + one reversal max per source) — mirrors
     * InvoiceController::isDuplicateReversal/ManualJournalController::
     * isDuplicateReversal. Prefers the driver's populated index name,
     * falling back to a message substring for drivers/situations where
     * ->index isn't set (MySQL always includes the violated key name in the
     * duplicate-entry message).
     */
    private function isDuplicateReversal(UniqueConstraintViolationException $e): bool
    {
        if ($e->index !== null) {
            return $e->index === 'journal_entries_source_unique';
        }

        return str_contains($e->getMessage(), 'journal_entries_source_unique');
    }

    /**
     * Shapes each line for DocumentLineCalculator — resolves the line's
     * CURRENT tax rate percent (fresh from TaxRate, not a snapshot;
     * tax_rate_snapshot is only ever written at record time, a later task —
     * mirrors InvoiceController::calculatorLines exactly).
     *
     * @param  array<int, array<string, mixed>>  $lines
     * @return array<int, array{amount: int, tax_rate: string|null}>
     */
    private function calculatorLines(array $lines): array
    {
        $taxRateIds = collect($lines)->pluck('tax_rate_id')->filter()->unique()->values();
        $taxRates = TaxRate::query()->whereIn('id', $taxRateIds)->get()->keyBy('id');

        return collect($lines)->map(function (array $line) use ($taxRates) {
            $taxRateId = $line['tax_rate_id'] ?? null;
            /** @var TaxRate|null $taxRate */
            $taxRate = $taxRateId !== null ? $taxRates->get($taxRateId) : null;

            return [
                'amount' => (int) $line['amount'],
                'tax_rate' => $taxRate !== null ? (string) $taxRate->rate : null,
            ];
        })->all();
    }

    /**
     * Creates each ExpenseLine, re-checking category-account eligibility
     * here too — the CALLER-INDEPENDENT half of the brief's "dual-layer like
     * OtherReceipt" requirement. StoreExpenseRequest/UpdateExpenseRequest
     * already reject an ineligible category with a friendly 422 before this
     * ever runs on the HTTP path, but this guard stands on its own so a
     * future direct caller (e.g. ExpenseService::record(), a CSV import)
     * can never persist a line against an ineligible account either.
     *
     * @param  array<int, array<string, mixed>>  $inputLines
     * @param  array<int, array{amount: int, line_tax: int}>  $computedLines
     *
     * @throws IneligibleCategoryAccount
     */
    private function createLines(Expense $expense, array $inputLines, array $computedLines): void
    {
        foreach (array_values($inputLines) as $index => $line) {
            $categoryAccount = Account::query()->findOrFail($line['category_account_id']);
            CategoryAccountGuard::guard($categoryAccount);

            ExpenseLine::query()->create([
                'expense_id' => $expense->id,
                'description' => $line['description'],
                'category_account_id' => $categoryAccount->id,
                'tax_rate_id' => $line['tax_rate_id'] ?? null,
                // Only ever written at record time (a later task) — a
                // draft's tax reflects the tax rate's CURRENT value, not a
                // frozen one. Mirrors InvoiceController::createLines.
                'tax_rate_snapshot' => null,
                'amount' => $computedLines[$index]['amount'],
                'line_tax' => $computedLines[$index]['line_tax'],
            ]);
        }
    }

    /** @return array<string, mixed> */
    private function formOptions(): array
    {
        return [
            // No type restriction — an expense's payee is any tenant
            // contact (spec §7.8), unlike Bill which requires a vendor.
            'contacts' => Contact::query()
                ->active()
                ->orderBy('name')
                ->get(['id', 'name', 'type'])
                ->map(fn (Contact $contact) => [
                    'id' => $contact->id,
                    'name' => $contact->name,
                    'type' => $contact->type->value,
                ])
                ->values(),
            'bankAccounts' => BankAccount::query()
                ->active()
                ->orderBy('name')
                ->get(['id', 'name'])
                ->map(fn (BankAccount $bankAccount) => [
                    'id' => $bankAccount->id,
                    'name' => $bankAccount->name,
                ])
                ->values(),
            'categoryAccounts' => CategoryAccountGuard::eligibleQuery()
                ->orderBy('code')
                ->get(['id', 'code', 'name'])
                ->map(fn (Account $account) => [
                    'id' => $account->id,
                    'code' => $account->code,
                    'name' => $account->name,
                ])
                ->values(),
            'taxRates' => TaxRate::query()
                ->where('is_archived', false)
                ->orderBy('name')
                ->get(['id', 'name', 'rate'])
                ->map(fn (TaxRate $taxRate) => [
                    'id' => $taxRate->id,
                    'name' => $taxRate->name,
                    'rate' => (string) $taxRate->rate,
                ])
                ->values(),
        ];
    }

    /** @return array<string, mixed> */
    private function expenseListPayload(Expense $expense): array
    {
        return [
            'id' => $expense->id,
            'status' => $expense->status->value,
            'contact_name' => $expense->contact?->name,
            'expense_date' => $expense->expense_date?->toDateString(),
            'notes' => $expense->notes,
            // Distinct category account names across this expense's lines,
            // in line order — Expenses/Index's "categories" summary column
            // (Phase 4 Task 9), a read-only display enrichment. Relies on
            // index()'s own lines.categoryAccount eager load.
            'categories' => $expense->lines
                ->pluck('categoryAccount.name')
                ->filter()
                ->unique()
                ->values()
                ->all(),
            'total' => $expense->total,
        ];
    }

    /**
     * The full detail payload for Expenses/Show (Phase 4 Task 9) — every
     * field the header, line table, and action-bar gating need. Unlike
     * expenseEditPayload (draft-editor only, IDs for the editable dropdowns),
     * this covers every status and enriches each line with its category
     * account/tax rate DISPLAY info (name, not just id) since Show never
     * re-renders the editable DocumentLineEditor. Mirrors
     * InvoiceController::invoiceShowPayload's own shape/reasoning.
     *
     * @return array<string, mixed>
     */
    private function expenseShowPayload(Expense $expense): array
    {
        return [
            'id' => $expense->id,
            'status' => $expense->status->value,
            'contact' => $expense->contact !== null
                ? ['id' => $expense->contact->id, 'name' => $expense->contact->name]
                : null,
            'expense_date' => $expense->expense_date?->toDateString(),
            'bank_account' => $expense->bankAccount !== null
                ? ['id' => $expense->bankAccount->id, 'name' => $expense->bankAccount->name]
                : null,
            'notes' => $expense->notes,
            'subtotal' => $expense->subtotal,
            'tax_total' => $expense->tax_total,
            'total' => $expense->total,
            'recorded_at' => $expense->recorded_at?->toIso8601String(),
            'voided_at' => $expense->voided_at?->toIso8601String(),
            'lines' => $this->showLinesPayload($expense->lines),
            'attachments' => $this->attachmentsPayload($expense->attachments),
        ];
    }

    /**
     * @param  Collection<int, ExpenseLine>  $lines
     * @return array<int, array<string, mixed>>
     */
    private function showLinesPayload(Collection $lines): array
    {
        return $lines->map(fn (ExpenseLine $line) => [
            'id' => $line->id,
            'description' => $line->description,
            'category_account' => [
                'id' => $line->categoryAccount->id,
                'code' => $line->categoryAccount->code,
                'name' => $line->categoryAccount->name,
            ],
            'tax_rate' => $line->taxRate !== null
                ? ['id' => $line->taxRate->id, 'name' => $line->taxRate->name]
                : null,
            'amount' => $line->amount,
            'line_tax' => $line->line_tax,
        ])->values()->all();
    }

    /** @return array<string, mixed> */
    private function expenseEditPayload(Expense $expense): array
    {
        return [
            'id' => $expense->id,
            'status' => $expense->status->value,
            'contact_id' => $expense->contact_id,
            'expense_date' => $expense->expense_date?->toDateString(),
            'bank_account_id' => $expense->bank_account_id,
            'notes' => $expense->notes,
            'subtotal' => $expense->subtotal,
            'tax_total' => $expense->tax_total,
            'total' => $expense->total,
            'lines' => $this->linesPayload($expense->lines),
            'attachments' => $this->attachmentsPayload($expense->attachments),
        ];
    }

    /**
     * @param  Collection<int, ExpenseLine>  $lines
     * @return array<int, array<string, mixed>>
     */
    private function linesPayload(Collection $lines): array
    {
        return $lines->map(fn (ExpenseLine $line) => [
            'id' => $line->id,
            'description' => $line->description,
            'category_account_id' => $line->category_account_id,
            'tax_rate_id' => $line->tax_rate_id,
            'amount' => $line->amount,
            'line_tax' => $line->line_tax,
        ])->values()->all();
    }

    /**
     * @param  Collection<int, Attachment>  $attachments
     * @return array<int, array<string, mixed>>
     */
    private function attachmentsPayload(Collection $attachments): array
    {
        return $attachments->map(fn (Attachment $attachment) => [
            'id' => $attachment->id,
            'original_name' => $attachment->original_name,
            'mime' => $attachment->mime,
            'size' => $attachment->size,
            'created_at' => $attachment->created_at?->toIso8601String(),
        ])->values()->all();
    }
}
