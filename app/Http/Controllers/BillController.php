<?php

namespace App\Http\Controllers;

use App\Enums\BillStatus;
use App\Enums\ContactType;
use App\Exceptions\AttachmentRejected;
use App\Exceptions\Documents\BillPaymentException;
use App\Exceptions\Documents\BillPaymentVoidException;
use App\Exceptions\Documents\DocumentPostException;
use App\Exceptions\Documents\DocumentVoidException;
use App\Exceptions\IneligibleCategoryAccount;
use App\Exceptions\Ledger\LedgerException;
use App\Http\Requests\StoreAttachmentRequest;
use App\Http\Requests\StoreBillPaymentRequest;
use App\Http\Requests\StoreBillRequest;
use App\Http\Requests\UpdateBillRequest;
use App\Models\Account;
use App\Models\Attachment;
use App\Models\BankAccount;
use App\Models\Bill;
use App\Models\BillLine;
use App\Models\BillPayment;
use App\Models\Contact;
use App\Models\TaxRate;
use App\Services\AttachmentService;
use App\Services\BillPaymentService;
use App\Services\BillService;
use App\Services\DocumentLineCalculator;
use App\Support\AuditLogger;
use App\Support\CategoryAccountGuard;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Draft-only bill CRUD (Phase 4 Task 2) — the AP-side mirror of
 * ExpenseController's own draft CRUD (this same task), which itself mirrors
 * InvoiceController (Phase 3 Task 2). No ledger posting: a bill created/
 * edited here always has status=draft. Only a draft bill is editable/
 * deletable — BillLine::booted() enforces the same rule at the model layer
 * as a defense-in-depth backstop below the checks in update()/destroy()
 * here. "Receive" (posting P6/P6t, Task 4), "pay" (posting P7, Task 5), and
 * "void"/"void a payment" (posting rule P9 reversals, Task 6) all live in
 * this same controller too.
 *
 * Unlike Expense: `contact_id` is REQUIRED and must be a vendor/both contact
 * (spec §7.8 — a bill always names a vendor); there is no `bank_account_id`
 * on the bill itself (a bill is on credit — the paying bank account is
 * chosen per BillPayment, at pay time); `reference` carries the vendor's own
 * bill number (no internal numbering, per Task 1).
 */
class BillController extends Controller
{
    private const AUDITED_FIELDS = [
        'contact_id',
        'reference',
        'bill_date',
        'due_date',
        'notes',
        'subtotal',
        'tax_total',
        'total',
    ];

    public function index(): Response
    {
        $bills = Bill::query()
            // lines.categoryAccount is only for the list row's "categories"
            // summary (Phase 4 Task 9) — mirrors ExpenseController::index's
            // own identical enrichment, a read-only display addition, not a
            // posting-logic change.
            ->with(['contact', 'lines.categoryAccount:id,name'])
            ->orderByDesc('id')
            ->get()
            ->map(fn (Bill $bill) => $this->billListPayload($bill))
            ->values();

        return Inertia::render('Bills/Index', [
            'bills' => $bills,
        ]);
    }

    public function create(): Response
    {
        return Inertia::render('Bills/Edit', array_merge(
            ['bill' => null],
            $this->formOptions(),
        ));
    }

    public function store(StoreBillRequest $request, AuditLogger $audit): RedirectResponse
    {
        $data = $request->validated();

        $bill = DB::transaction(function () use ($data, $audit) {
            $computed = DocumentLineCalculator::compute($this->calculatorLines($data['lines']));

            $bill = Bill::query()->create([
                'contact_id' => $data['contact_id'],
                'reference' => $data['reference'] ?? null,
                'status' => BillStatus::Draft->value,
                'bill_date' => $data['bill_date'] ?? null,
                'due_date' => $data['due_date'] ?? null,
                'subtotal' => $computed['subtotal'],
                'tax_total' => $computed['tax_total'],
                'total' => $computed['total'],
                'amount_paid' => 0,
                'balance' => $computed['total'],
                'notes' => $data['notes'] ?? null,
                'created_by' => auth()->id(),
            ]);

            $this->createLines($bill, $data['lines'], $computed['lines']);

            $audit->log('bill.draft_created', $bill, after: $bill->only(self::AUDITED_FIELDS));

            return $bill;
        });

        return redirect()->route('bills.edit', $bill->id);
    }

    /**
     * $bill is deliberately a plain route parameter, not an implicit
     * Eloquent binding — SubstituteBindings runs before SetCompanyContext
     * (see InvoiceController for the full reasoning, mirrored throughout
     * this controller).
     */
    public function edit(string $bill): Response
    {
        // No `payments` eager load here (unlike show() below) — Phase 4 Task
        // 9 moved the payments list + Receive/Void/Record-payment actions to
        // Bills/Show; Edit is purely the draft field/line editor now, so it
        // has nothing to show a payments table for.
        $model = Bill::query()
            ->with([
                'lines',
                'attachments' => fn ($query) => $query->orderByDesc('id'),
            ])
            ->findOrFail($bill);

        return Inertia::render('Bills/Edit', array_merge(
            ['bill' => $this->billEditPayload($model)],
            $this->formOptions(),
        ));
    }

    /**
     * The bill detail screen (Phase 4 Task 9) — the canonical landing page
     * for a RECEIVED bill (receive()/recordPayment()/void()/voidPayment()
     * below all redirect back here) and also viewable for a draft (Edit
     * remains the draft EDITOR; Show is the read-only detail + action-bar +
     * payments-list view for every status, including the relocated
     * opening-bill banner). Mirrors ExpenseController::show/
     * InvoiceController::show exactly. $bill is a plain route parameter,
     * same SubstituteBindings-ordering reasoning as every other action here.
     */
    public function show(string $bill): Response
    {
        $model = Bill::query()
            ->with([
                'contact:id,name',
                'lines' => fn ($query) => $query->orderBy('id'),
                'lines.categoryAccount:id,code,name',
                'lines.taxRate:id,name',
                'payments' => fn ($query) => $query->orderBy('payment_date')->orderBy('id'),
                'payments.bankAccount:id,name',
                'attachments' => fn ($query) => $query->orderByDesc('id'),
            ])
            ->findOrFail($bill);

        return Inertia::render('Bills/Show', [
            'bill' => $this->billShowPayload($model),
            'bankAccounts' => BankAccount::query()
                ->where('is_archived', false)
                ->orderBy('name')
                ->get(['id', 'name'])
                ->values(),
        ]);
    }

    public function update(UpdateBillRequest $request, string $bill, AuditLogger $audit): RedirectResponse
    {
        $model = Bill::query()->findOrFail($bill);

        if (! $model->status->canEdit()) {
            throw ValidationException::withMessages([
                'status' => 'Only draft bills can be edited. Void and reissue a received bill instead.',
            ]);
        }

        $data = $request->validated();
        $before = $model->only(self::AUDITED_FIELDS);

        DB::transaction(function () use ($model, $data) {
            $computed = DocumentLineCalculator::compute($this->calculatorLines($data['lines']));

            $model->update([
                'contact_id' => $data['contact_id'],
                'reference' => $data['reference'] ?? null,
                'bill_date' => $data['bill_date'] ?? null,
                'due_date' => $data['due_date'] ?? null,
                'subtotal' => $computed['subtotal'],
                'tax_total' => $computed['tax_total'],
                'total' => $computed['total'],
                'balance' => $computed['total'],
                'notes' => $data['notes'] ?? null,
            ]);

            // Replace lines wholesale: iterate + delete individually (rather
            // than a bulk query-builder delete) so BillLine::booted()'s
            // freeze guard actually fires per row — defense in depth in case
            // this method is ever reached for a non-draft bill despite the
            // canEdit() check above. Mirrors ExpenseController::update.
            $model->lines()->get()->each(fn (BillLine $line) => $line->delete());

            $this->createLines($model, $data['lines'], $computed['lines']);
        });

        $audit->log('bill.draft_updated', $model, before: $before, after: $model->only(self::AUDITED_FIELDS));

        return redirect()->route('bills.edit', $model->id);
    }

    /**
     * Receives a draft bill — posting rule P6/P6t (Dr category per line, Dr
     * ONE combined 2100 Tax Payable line if any tax, Cr 2000 Accounts
     * Payable for the gross total with the vendor's contact_id) via
     * BillService, flipping the draft to Received. $bill is a plain route
     * parameter (not implicit binding), same reasoning as
     * edit()/update()/destroy() above. Mirrors ExpenseController::record
     * exactly.
     */
    public function receive(Request $request, string $bill, BillService $service): RedirectResponse
    {
        $model = Bill::query()->findOrFail($bill);

        $idempotencyKey = $request->header('X-Idempotency-Key');
        if (! is_string($idempotencyKey) || ! Str::isUuid($idempotencyKey)) {
            throw ValidationException::withMessages([
                'idempotency_key' => 'A valid X-Idempotency-Key header (UUID) is required.',
            ]);
        }

        try {
            $service->receive($idempotencyKey, $model);
        } catch (IneligibleCategoryAccount|DocumentPostException|LedgerException $e) {
            throw ValidationException::withMessages(['status' => $e->getMessage()]);
        }

        // Phase 4 Task 9: Show is now the canonical landing page for a
        // received bill — mirrors InvoiceController::send -> invoices.show.
        return redirect()->route('bills.show', $model->id);
    }

    /**
     * Records a payment (posting rule P7, spec §6) against a received/
     * partially-paid bill via BillPaymentService. $bill is a plain route
     * parameter (not implicit binding), same reasoning as
     * edit()/update()/destroy()/receive() above. Both BillPaymentException
     * (this action's own domain guards: not payable, invalid amount,
     * overpayment, ineligible bank account) and LedgerException (e.g. a
     * locked period) surface as a 422 on a generic `status` field, mirroring
     * receive()/InvoiceController::recordPayment — there's no single natural
     * form field for a whole-bill guard like "already paid" or "locked
     * period".
     */
    public function recordPayment(StoreBillPaymentRequest $request, string $bill, BillPaymentService $service): RedirectResponse
    {
        $model = Bill::query()->findOrFail($bill);
        $data = $request->validated();

        try {
            $service->record($data['idempotency_key'], $model, $data);
        } catch (BillPaymentException|LedgerException $e) {
            throw ValidationException::withMessages(['status' => $e->getMessage()]);
        }

        // Phase 4 Task 9: mirrors receive() above.
        return redirect()->route('bills.show', $model->id);
    }

    public function destroy(string $bill, AuditLogger $audit, AttachmentService $attachments): RedirectResponse
    {
        $model = Bill::query()->findOrFail($bill);

        if (! $model->status->canEdit()) {
            throw ValidationException::withMessages([
                'status' => 'Only draft bills can be deleted. Void a received bill instead.',
            ]);
        }

        $before = $model->only(self::AUDITED_FIELDS);

        DB::transaction(function () use ($model, $attachments) {
            $model->lines()->get()->each(fn (BillLine $line) => $line->delete());
            // Attachments cascade too (Task 8): rows now, disk files once
            // this transaction commits — mirrors ExpenseController::destroy.
            // The single bill.draft_deleted audit row below covers the
            // cascade; deleteAllFor deliberately writes no per-receipt
            // audit rows (see its docblock).
            $attachments->deleteAllFor($model);
            $model->delete();
        });

        $audit->log('bill.draft_deleted', $model, before: $before);

        return redirect()->route('bills.index');
    }

    /**
     * Voids a received/partially-paid/paid bill — posting rule P9 (spec §6):
     * a mirror reversal of the bill's own P6/P6t entry, via BillService::void.
     * $bill is a plain route parameter (not implicit binding), same
     * reasoning as edit()/update()/destroy()/receive() above.
     *
     * Three failure shapes surface as a 422 on the generic `status` field,
     * mirroring InvoiceController::void: DocumentVoidException (this
     * service's own domain guards — not voidable, live payments remain,
     * is_opening), a LedgerException (e.g. a locked period on today's
     * reversal date), and — as defense in depth for a genuine concurrent
     * double-void race, mirroring InvoiceController::void's identical
     * backstop — a UniqueConstraintViolationException on the journal_entries
     * source-unique index.
     */
    public function void(Request $request, string $bill, BillService $service): RedirectResponse
    {
        $model = Bill::query()->findOrFail($bill);
        $idempotencyKey = $this->requireIdempotencyKey($request);

        try {
            $service->void($idempotencyKey, $model);
        } catch (DocumentVoidException|LedgerException $e) {
            throw ValidationException::withMessages(['status' => $e->getMessage()]);
        } catch (UniqueConstraintViolationException $e) {
            if (! $this->isDuplicateReversal($e)) {
                throw $e;
            }

            throw ValidationException::withMessages(['status' => 'This bill has already been voided.']);
        }

        // Phase 4 Task 9: mirrors receive() above.
        return redirect()->route('bills.show', $model->id);
    }

    /**
     * Reverses a single posted bill payment — posting rule P7's mirror, via
     * BillPaymentService::void. $payment is resolved off the bill's own
     * relation (not BillPayment::findOrFail directly) so a payment that
     * belongs to a DIFFERENT bill in the same company still 404s here, not
     * just a payment in a different company — mirrors
     * InvoiceController::voidPayment exactly.
     *
     * Same three-way failure classification as void() above: BillPaymentVoidException
     * (already reversed), LedgerException (locked period), and the same
     * narrow UniqueConstraintViolationException backstop.
     */
    public function voidPayment(Request $request, string $bill, string $payment, BillPaymentService $service): RedirectResponse
    {
        $model = Bill::query()->findOrFail($bill);
        $paymentModel = $model->payments()->findOrFail($payment);

        $idempotencyKey = $this->requireIdempotencyKey($request);

        try {
            $service->void($idempotencyKey, $paymentModel);
        } catch (BillPaymentVoidException|LedgerException $e) {
            throw ValidationException::withMessages(['status' => $e->getMessage()]);
        } catch (UniqueConstraintViolationException $e) {
            if (! $this->isDuplicateReversal($e)) {
                throw $e;
            }

            throw ValidationException::withMessages(['status' => 'This payment has already been reversed.']);
        }

        // Phase 4 Task 9: mirrors receive() above.
        return redirect()->route('bills.show', $model->id);
    }

    /**
     * Uploads a receipt/bill-scan attachment onto a bill (spec §17.8, Phase
     * 4 Task 8) — gated by bills.draft, the SAME (broad, all-four-roles)
     * ability as the rest of this route group: attachments are addable
     * anytime, even once the bill has left draft status (vendor scans
     * genuinely do arrive late) — a deliberate exception to the "only a
     * draft is editable" rule the other actions above enforce, not an
     * oversight. Mirrors ExpenseController::storeAttachment exactly (both
     * controllers share AttachmentService, the actual hardening lives
     * there). $bill is a plain route parameter, same reasoning as every
     * other action here.
     */
    public function storeAttachment(StoreAttachmentRequest $request, string $bill, AttachmentService $service): RedirectResponse
    {
        $model = Bill::query()->findOrFail($bill);

        try {
            $service->store($model, $request->file('file'));
        } catch (AttachmentRejected $e) {
            throw ValidationException::withMessages(['file' => $e->getMessage()]);
        }

        // Phase 4 Task 9: mirrors ExpenseController::storeAttachment's own
        // back()-with-fallback reasoning — AttachmentSection now renders
        // from both Edit and Show.
        return redirect()->back(fallback: route('bills.show', $model->id));
    }

    /**
     * Streams an attachment's original bytes back — the ONLY way one is
     * ever read (spec §17.8: authenticated, tenant-scoped, gated route).
     * Gated by bills.draft, same group as storeAttachment above. $attachment
     * is resolved off the BILL's own relation (not Attachment::findOrFail
     * directly), so an attachment belonging to a DIFFERENT bill — or an
     * Expense's attachment reached via this route — 404s here too, not just
     * a foreign company's. Mirrors voidPayment's nested scoped findOrFail
     * exactly.
     */
    public function downloadAttachment(string $bill, string $attachment, AttachmentService $service): StreamedResponse
    {
        $model = Bill::query()->findOrFail($bill);
        $attachmentModel = $model->attachments()->findOrFail($attachment);

        return $service->download($attachmentModel);
    }

    /**
     * Deletes an attachment — gated by bills.void (Owner/Admin/Accountant
     * only, spec §17.8's decision), a narrower ability than the bills.draft
     * upload/download gate above. $attachment resolved off the bill's own
     * relation, same nested-scoped reasoning as downloadAttachment above.
     */
    public function destroyAttachment(string $bill, string $attachment, AttachmentService $service): RedirectResponse
    {
        $model = Bill::query()->findOrFail($bill);
        $attachmentModel = $model->attachments()->findOrFail($attachment);

        $service->delete($attachmentModel);

        // Mirrors storeAttachment's own back()-with-fallback reasoning above.
        return redirect()->back(fallback: route('bills.show', $model->id));
    }

    /**
     * Extracts and validates the X-Idempotency-Key header (a UUID) — mirrors
     * InvoiceController::requireIdempotencyKey. Both void()/voidPayment()
     * above need the identical guard.
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

    /**
     * Whether the violated unique index is the journal_entries source-unique
     * index (one primary + one reversal max per source) — mirrors
     * InvoiceController::isDuplicateReversal. Prefers the driver's populated
     * index name, falling back to a message substring for drivers/
     * situations where ->index isn't set (MySQL always includes the
     * violated key name in the duplicate-entry message).
     */
    private function isDuplicateReversal(UniqueConstraintViolationException $e): bool
    {
        if ($e->index !== null) {
            return $e->index === 'journal_entries_source_unique';
        }

        return str_contains($e->getMessage(), 'journal_entries_source_unique');
    }

    /**
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
     * Creates each BillLine, re-checking category-account eligibility here
     * too — the CALLER-INDEPENDENT half of the brief's "dual-layer like
     * OtherReceipt" requirement, mirroring ExpenseController::createLines.
     *
     * @param  array<int, array<string, mixed>>  $inputLines
     * @param  array<int, array{amount: int, line_tax: int}>  $computedLines
     *
     * @throws IneligibleCategoryAccount
     */
    private function createLines(Bill $bill, array $inputLines, array $computedLines): void
    {
        foreach (array_values($inputLines) as $index => $line) {
            $categoryAccount = Account::query()->findOrFail($line['category_account_id']);
            CategoryAccountGuard::guard($categoryAccount);

            BillLine::query()->create([
                'bill_id' => $bill->id,
                'description' => $line['description'],
                'category_account_id' => $categoryAccount->id,
                'tax_rate_id' => $line['tax_rate_id'] ?? null,
                // Only ever written at receive time (a later task) — a
                // draft's tax reflects the tax rate's CURRENT value, not a
                // frozen one. Mirrors ExpenseController::createLines.
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
            'contacts' => Contact::query()
                ->where('is_archived', false)
                ->whereIn('type', [ContactType::Vendor->value, ContactType::Both->value])
                ->orderBy('name')
                ->get(['id', 'name', 'type'])
                ->map(fn (Contact $contact) => [
                    'id' => $contact->id,
                    'name' => $contact->name,
                    'type' => $contact->type->value,
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
    private function billListPayload(Bill $bill): array
    {
        return [
            'id' => $bill->id,
            'reference' => $bill->reference,
            'status' => $bill->status->value,
            'contact_name' => $bill->contact->name,
            'bill_date' => $bill->bill_date?->toDateString(),
            'due_date' => $bill->due_date?->toDateString(),
            'notes' => $bill->notes,
            // Distinct category account names across this bill's lines, in
            // line order — Bills/Index's "categories" summary column (Phase
            // 4 Task 9). Mirrors ExpenseController::expenseListPayload's own
            // identical field; relies on index()'s own lines.categoryAccount
            // eager load. due_date + status above are what the client
            // derives its own "Overdue" filter/highlight from (mirrors how
            // Invoices/Index computes overdue purely from those same two
            // fields, already present before this task).
            'categories' => $bill->lines
                ->pluck('categoryAccount.name')
                ->filter()
                ->unique()
                ->values()
                ->all(),
            'total' => $bill->total,
            'balance' => $bill->balance,
        ];
    }

    /**
     * The full detail payload for Bills/Show (Phase 4 Task 9) — every field
     * the header, line table, payments list, and action-bar gating
     * (is_opening, overdue) need. Unlike billEditPayload (draft-editor only,
     * IDs for the editable dropdowns), this enriches each line with its
     * category account/tax rate DISPLAY info (name, not just id) since Show
     * never re-renders the editable DocumentLineEditor. Mirrors
     * InvoiceController::invoiceShowPayload's own shape/reasoning; reuses
     * paymentsPayload/attachmentsPayload as-is (both already display-shaped).
     *
     * @return array<string, mixed>
     */
    private function billShowPayload(Bill $bill): array
    {
        return [
            'id' => $bill->id,
            'status' => $bill->status->value,
            'is_opening' => $bill->is_opening,
            'contact' => ['id' => $bill->contact->id, 'name' => $bill->contact->name],
            'reference' => $bill->reference,
            'bill_date' => $bill->bill_date?->toDateString(),
            'due_date' => $bill->due_date?->toDateString(),
            'notes' => $bill->notes,
            'subtotal' => $bill->subtotal,
            'tax_total' => $bill->tax_total,
            'total' => $bill->total,
            'amount_paid' => $bill->amount_paid,
            'balance' => $bill->balance,
            'received_at' => $bill->received_at?->toIso8601String(),
            'voided_at' => $bill->voided_at?->toIso8601String(),
            'lines' => $this->showLinesPayload($bill->lines),
            'payments' => $this->paymentsPayload($bill->payments),
            'attachments' => $this->attachmentsPayload($bill->attachments),
        ];
    }

    /**
     * @param  Collection<int, BillLine>  $lines
     * @return array<int, array<string, mixed>>
     */
    private function showLinesPayload(Collection $lines): array
    {
        return $lines->map(fn (BillLine $line) => [
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

    /**
     * The draft-editor payload for Bills/Edit. No `is_opening`/`payments`
     * (unlike billShowPayload) — an is_opening bill is never draft in the
     * first place, and the payments list moved to Show this same task, so
     * Edit's own BillData type on the client doesn't carry either field.
     *
     * @return array<string, mixed>
     */
    private function billEditPayload(Bill $bill): array
    {
        return [
            'id' => $bill->id,
            'status' => $bill->status->value,
            'contact_id' => $bill->contact_id,
            'reference' => $bill->reference,
            'bill_date' => $bill->bill_date?->toDateString(),
            'due_date' => $bill->due_date?->toDateString(),
            'notes' => $bill->notes,
            'subtotal' => $bill->subtotal,
            'tax_total' => $bill->tax_total,
            'total' => $bill->total,
            'lines' => $this->linesPayload($bill->lines),
            'attachments' => $this->attachmentsPayload($bill->attachments),
        ];
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

    /**
     * @param  Collection<int, BillPayment>  $payments
     * @return array<int, array<string, mixed>>
     */
    private function paymentsPayload(Collection $payments): array
    {
        return $payments->map(fn (BillPayment $payment) => [
            'id' => $payment->id,
            'payment_date' => $payment->payment_date->toDateString(),
            'amount' => $payment->amount,
            'status' => $payment->status,
            'memo' => $payment->memo,
            'bank_account' => $payment->bankAccount !== null
                ? ['id' => $payment->bankAccount->id, 'name' => $payment->bankAccount->name]
                : null,
        ])->values()->all();
    }

    /**
     * @param  Collection<int, BillLine>  $lines
     * @return array<int, array<string, mixed>>
     */
    private function linesPayload(Collection $lines): array
    {
        return $lines->map(fn (BillLine $line) => [
            'id' => $line->id,
            'description' => $line->description,
            'category_account_id' => $line->category_account_id,
            'tax_rate_id' => $line->tax_rate_id,
            'amount' => $line->amount,
            'line_tax' => $line->line_tax,
        ])->values()->all();
    }
}
