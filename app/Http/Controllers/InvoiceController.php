<?php

namespace App\Http\Controllers;

use App\Enums\AccountType;
use App\Enums\ContactType;
use App\Enums\InvoiceStatus;
use App\Exceptions\Invoicing\InvoiceEmailException;
use App\Exceptions\Invoicing\InvoiceFxRateInvalid;
use App\Exceptions\Invoicing\InvoiceSendException;
use App\Exceptions\Invoicing\InvoiceVoidException;
use App\Exceptions\Invoicing\PaymentException;
use App\Exceptions\Invoicing\PaymentVoidException;
use App\Exceptions\Ledger\LedgerException;
use App\Http\Requests\StoreInvoiceRequest;
use App\Http\Requests\StorePaymentRequest;
use App\Http\Requests\UpdateInvoiceRequest;
use App\Models\Account;
use App\Models\BankAccount;
use App\Models\Contact;
use App\Models\Invoice;
use App\Models\InvoiceLine;
use App\Models\InvoicePayment;
use App\Models\Item;
use App\Models\TaxRate;
use App\Services\InvoiceCalculator;
use App\Services\InvoicePaymentService;
use App\Services\InvoicePdf;
use App\Services\InvoiceService;
use App\Support\AuditLogger;
use App\Support\CsvExport;
use App\Support\Currencies;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response as HttpResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Draft-only invoice CRUD (Phase 3 Task 2). No ledger posting, no document
 * numbering — an invoice created/edited here always has status=draft and a
 * null `number`; DocumentNumberService is only invoked once a "send" action
 * (Task 3) posts the invoice. Only a draft invoice is editable/deletable —
 * InvoiceLine::booted() enforces the same rule at the model layer as a
 * defense-in-depth backstop below the checks in update()/destroy() here.
 */
class InvoiceController extends Controller
{
    private const AUDITED_FIELDS = [
        'contact_id',
        'currency_code',
        'issue_date',
        'due_date',
        'notes',
        'subtotal',
        'tax_total',
        'total',
    ];

    public function index(): Response
    {
        $invoices = Invoice::query()
            ->with('contact')
            ->orderByDesc('id')
            ->get()
            ->map(fn (Invoice $invoice) => $this->invoiceListPayload($invoice))
            ->values();

        return Inertia::render('Invoices/Index', [
            'invoices' => $invoices,
        ]);
    }

    /**
     * List-screen CSV export (Phase 6 Task 3, spec §11/§17.8) — the SAME
     * scope Invoices/Index reads (every invoice for the current company),
     * optionally narrowed by the same `status` values the index's client-side
     * filter offers (e.g. ?status=paid exports only paid invoices), so the
     * downloaded file always matches what the caller is looking at. Gated
     * `exports.run` in addition to this controller's own `invoices.draft`
     * group (routes/web.php) — Staff holds invoices.draft but not
     * exports.run, so it can view/create invoices but not export them.
     * Audit-logged via the shared CsvExport helper, which can't produce a
     * response without also writing the `invoices.exported` row first.
     */
    public function exportCsv(Request $request, AuditLogger $audit): StreamedResponse
    {
        $validated = $request->validate([
            'status' => ['nullable', 'string', Rule::enum(InvoiceStatus::class)],
        ]);
        $status = $validated['status'] ?? null;

        $invoices = Invoice::query()
            ->with('contact')
            ->when($status !== null, fn ($query) => $query->where('status', $status))
            ->orderByDesc('id')
            ->get();

        $rows = $invoices->map(fn (Invoice $invoice) => [
            $invoice->number ?? 'Draft',
            $invoice->contact->name,
            $invoice->issue_date?->toDateString(),
            $invoice->due_date?->toDateString(),
            $invoice->currency_code,
            $invoice->status->value,
            Money::minorToDecimalString($invoice->total),
            Money::minorToDecimalString($invoice->balance),
        ]);

        return CsvExport::stream(
            'invoices.csv',
            ['Number', 'Customer', 'Issue Date', 'Due Date', 'Currency', 'Status', 'Total', 'Balance'],
            $rows,
            $audit,
            'invoices.exported',
            ['status' => $status ?? 'all'],
        );
    }

    public function create(): Response
    {
        return Inertia::render('Invoices/Edit', array_merge(
            ['invoice' => null],
            $this->formOptions(),
        ));
    }

    public function store(StoreInvoiceRequest $request, AuditLogger $audit): RedirectResponse
    {
        $data = $request->validated();

        $invoice = DB::transaction(function () use ($data, $audit) {
            $computed = InvoiceCalculator::compute($this->calculatorLines($data['lines']));

            $invoice = Invoice::query()->create([
                'contact_id' => $data['contact_id'],
                'status' => InvoiceStatus::Draft->value,
                'issue_date' => $data['issue_date'] ?? null,
                'due_date' => $data['due_date'] ?? null,
                'currency_code' => $data['currency_code'],
                'fx_rate' => 1,
                'subtotal' => $computed['subtotal'],
                'tax_total' => $computed['tax_total'],
                'total' => $computed['total'],
                'amount_paid' => 0,
                'balance' => $computed['total'],
                // Base mirrors the document totals for now — FX conversion at
                // send time is Task 7 (fx_rate stays 1.0 for every draft).
                'base_subtotal' => $computed['subtotal'],
                'base_tax_total' => $computed['tax_total'],
                'base_total' => $computed['total'],
                'notes' => $data['notes'] ?? null,
                'created_by' => auth()->id(),
            ]);

            $this->createLines($invoice, $data['lines'], $computed['lines']);

            $audit->log('invoice.draft_created', $invoice, after: $invoice->only(self::AUDITED_FIELDS));

            return $invoice;
        });

        return redirect()->route('invoices.edit', $invoice->id);
    }

    /**
     * $invoice is deliberately a plain route parameter, not an implicit
     * Eloquent binding — see ContactController/ItemController for why
     * (SubstituteBindings runs before SetCompanyContext).
     */
    public function edit(string $invoice): Response
    {
        $model = Invoice::query()->with('lines')->findOrFail($invoice);

        return Inertia::render('Invoices/Edit', array_merge(
            ['invoice' => $this->invoiceEditPayload($model)],
            $this->formOptions(),
        ));
    }

    /**
     * The invoice detail screen (Phase 3 Task 11) — the canonical landing
     * page for a POSTED invoice (every action's redirect below points back
     * here) and also viewable for a draft (Edit remains the draft EDITOR;
     * Show is the read-only detail + action-bar view for every status,
     * including draft). Same `can:invoices.draft` gate as index/create/edit —
     * see formOptions()'s reasoning: every role that can see an invoice at
     * all can view this page; the individual action buttons it renders are
     * gated client-side by the narrower invoices.send/payments.record/
     * invoices.void abilities (shared via HandleInertiaRequests::NAV_ABILITIES),
     * with every one of those routes independently gated server-side too.
     *
     * $invoice is a plain route parameter (not implicit binding) for the
     * same reason as every other action here — SubstituteBindings runs
     * before SetCompanyContext.
     */
    public function show(string $invoice): Response
    {
        $model = Invoice::query()
            ->with([
                'contact:id,name',
                'lines' => fn ($query) => $query->orderBy('id'),
                'payments' => fn ($query) => $query->orderBy('payment_date')->orderBy('id'),
                'payments.bankAccount:id,name',
            ])
            ->findOrFail($invoice);

        return Inertia::render('Invoices/Show', [
            'invoice' => $this->invoiceShowPayload($model),
            'bankAccounts' => BankAccount::query()
                ->where('is_archived', false)
                ->orderBy('name')
                ->get(['id', 'name', 'currency_code'])
                ->values(),
        ]);
    }

    /**
     * Streams the branded invoice PDF inline (Phase 3 Task 9). $invoice is a
     * plain route parameter (not implicit binding), same reasoning as every
     * other action here — SubstituteBindings runs before SetCompanyContext,
     * so an implicit binding would 404 before the tenant scope even exists.
     *
     * Gated by can:invoices.draft (same route group as index/create/store/
     * edit/update/destroy) rather than a narrower ability: every role that
     * can see an invoice at all (Owner/Admin/Accountant/Staff — see
     * config/permissions.php) can view/print it; there is no separate "view"
     * permission in the catalog.
     */
    public function pdf(string $invoice, InvoicePdf $pdfService): HttpResponse
    {
        $model = Invoice::query()->findOrFail($invoice);

        return $pdfService->render($model)->stream(($model->number ?? 'DRAFT').'.pdf');
    }

    public function update(UpdateInvoiceRequest $request, string $invoice, AuditLogger $audit): RedirectResponse
    {
        $model = Invoice::query()->findOrFail($invoice);

        if (! $model->status->canEdit()) {
            throw ValidationException::withMessages([
                'status' => 'Only draft invoices can be edited. Void and reissue a posted invoice instead.',
            ]);
        }

        $data = $request->validated();
        $before = $model->only(self::AUDITED_FIELDS);

        DB::transaction(function () use ($model, $data) {
            $computed = InvoiceCalculator::compute($this->calculatorLines($data['lines']));

            $model->update([
                'contact_id' => $data['contact_id'],
                'issue_date' => $data['issue_date'] ?? null,
                'due_date' => $data['due_date'] ?? null,
                'currency_code' => $data['currency_code'],
                'subtotal' => $computed['subtotal'],
                'tax_total' => $computed['tax_total'],
                'total' => $computed['total'],
                'balance' => $computed['total'],
                'base_subtotal' => $computed['subtotal'],
                'base_tax_total' => $computed['tax_total'],
                'base_total' => $computed['total'],
                'notes' => $data['notes'] ?? null,
            ]);

            // Replace lines wholesale: iterate + delete individually (rather
            // than a bulk query-builder delete) so InvoiceLine::booted()'s
            // freeze guard actually fires per row — defense in depth in case
            // this method is ever reached for a non-draft invoice despite the
            // canEdit() check above.
            $model->lines()->get()->each(fn (InvoiceLine $line) => $line->delete());

            $this->createLines($model, $data['lines'], $computed['lines']);
        });

        $audit->log('invoice.draft_updated', $model, before: $before, after: $model->only(self::AUDITED_FIELDS));

        return redirect()->route('invoices.edit', $model->id);
    }

    public function destroy(string $invoice, AuditLogger $audit): RedirectResponse
    {
        $model = Invoice::query()->findOrFail($invoice);

        if (! $model->status->canEdit()) {
            throw ValidationException::withMessages([
                'status' => 'Only draft invoices can be deleted. Void a posted invoice instead.',
            ]);
        }

        $before = $model->only(self::AUDITED_FIELDS);

        DB::transaction(function () use ($model) {
            $model->lines()->get()->each(fn (InvoiceLine $line) => $line->delete());
            $model->delete();
        });

        $audit->log('invoice.draft_deleted', $model, before: $before);

        return redirect()->route('invoices.index');
    }

    /**
     * Assigns the invoice's document number, posts posting rule P1/P1fx (Dr
     * AR / Cr income per line / Cr Tax Payable, in base currency) via
     * InvoiceService, and flips the draft to Sent. $invoice is a plain route
     * parameter (not implicit binding) for the same reason as
     * edit()/update()/destroy() above.
     *
     * The idempotency key travels in a header (like every other posting
     * action in this app) rather than the body. `fx_rate` (Phase 3 Task 7,
     * spec §17.1) is the one piece of real form data this action DOES take:
     * for a foreign-currency invoice it's manually entered/editable at send
     * time, so it travels in the body; it's simply ignored for a domestic
     * invoice (InvoiceService::send fixes fx_rate at 1.0 regardless). An
     * invalid fx_rate for a foreign invoice (missing/<=0/non-numeric/>8dp)
     * surfaces as a 422 on the `fx_rate` field (see the specific catch
     * below); every OTHER InvoiceSendException (this controller's own
     * whole-invoice guards: not-a-draft, no lines, ineligible contact) and
     * LedgerException (e.g. a locked period) surface as a 422 on the generic
     * `status` field, since there's no single natural form field for a
     * whole-invoice action like this one.
     */
    public function send(Request $request, string $invoice, InvoiceService $service): RedirectResponse
    {
        $model = Invoice::query()->findOrFail($invoice);

        $idempotencyKey = $request->header('X-Idempotency-Key');
        if (! is_string($idempotencyKey) || ! Str::isUuid($idempotencyKey)) {
            throw ValidationException::withMessages([
                'idempotency_key' => 'A valid X-Idempotency-Key header (UUID) is required.',
            ]);
        }

        $validated = $request->validate([
            'fx_rate' => ['nullable', 'string', 'max:32'],
        ]);

        try {
            $service->send($idempotencyKey, $model, $validated['fx_rate'] ?? null);
        } catch (InvoiceFxRateInvalid $e) {
            // A fx_rate-field-specific validation failure — its message lands
            // on the `fx_rate` field, not the generic `status` field. Caught
            // FIRST because it IS an InvoiceSendException (subclass); PHP
            // evaluates catch blocks top-down, so the broader catch below
            // would otherwise swallow it onto `status`.
            throw ValidationException::withMessages(['fx_rate' => $e->getMessage()]);
        } catch (InvoiceSendException|LedgerException $e) {
            throw ValidationException::withMessages(['status' => $e->getMessage()]);
        }

        return redirect()->route('invoices.show', $model->id);
    }

    /**
     * Queues the invoice email (spec §17.6) via InvoiceService::sendEmail —
     * the FIRST send. $invoice is a plain route parameter (not implicit
     * binding), same reasoning as edit()/update()/destroy()/send() above.
     *
     * Requires an X-Idempotency-Key UUID header exactly like send()/void()
     * (spec §17.6: "idempotency per §17.2 prevents double-dispatch of the same
     * attempt"): a double-click / network retry of ONE Email press carries the
     * SAME key and dispatches the job ONCE — the customer never gets two
     * copies of the email. A genuine later Resend is a separate press with a
     * fresh key. Guarded to a posted invoice only (draft/void -> 422 on
     * `status`, mirroring send()/void()'s classification).
     */
    public function email(Request $request, string $invoice, InvoiceService $service): RedirectResponse
    {
        $model = Invoice::query()->findOrFail($invoice);
        $idempotencyKey = $this->requireIdempotencyKey($request);

        try {
            $service->sendEmail($idempotencyKey, $model);
        } catch (InvoiceEmailException $e) {
            throw ValidationException::withMessages(['status' => $e->getMessage()]);
        }

        return redirect()->route('invoices.show', $model->id);
    }

    /**
     * Re-queues the invoice email (spec §17.6) via InvoiceService::resendEmail
     * — a Resend, available regardless of the invoice's current email_status.
     * Same routing/guard/idempotency shape as email() above: the key dedupes
     * an accidental double-click of THIS resend, while a further intentional
     * resend carries its own fresh key and dispatches again.
     */
    public function resendEmail(Request $request, string $invoice, InvoiceService $service): RedirectResponse
    {
        $model = Invoice::query()->findOrFail($invoice);
        $idempotencyKey = $this->requireIdempotencyKey($request);

        try {
            $service->resendEmail($idempotencyKey, $model);
        } catch (InvoiceEmailException $e) {
            throw ValidationException::withMessages(['status' => $e->getMessage()]);
        }

        return redirect()->route('invoices.show', $model->id);
    }

    /**
     * Extracts and validates the X-Idempotency-Key header (a UUID) — the same
     * check send()/void()/voidPayment() each inline. Factored out here since
     * email()/resendEmail() need the identical guard and there are now enough
     * call sites to justify one shared helper. Throws a 422 on the
     * `idempotency_key` field when the header is missing or not a UUID.
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
     * Records a payment (posting rules P2 full / P3 partial, spec §6)
     * against a sent/partially-paid invoice via InvoicePaymentService.
     * $invoice is a plain route parameter (not implicit binding) for the
     * same reason as edit()/update()/destroy()/send() above. Both
     * PaymentException (this action's own domain guards: not payable,
     * invalid amount, overpayment, ineligible bank account) and
     * LedgerException (e.g. a locked period) surface as a 422 on a generic
     * `status` field, mirroring send() — there's no single natural form
     * field for a whole-invoice guard like "already paid" or "locked period".
     */
    public function recordPayment(StorePaymentRequest $request, string $invoice, InvoicePaymentService $service): RedirectResponse
    {
        $model = Invoice::query()->findOrFail($invoice);
        $data = $request->validated();

        try {
            $service->recordPayment($data['idempotency_key'], $model, $data);
        } catch (PaymentException|LedgerException $e) {
            throw ValidationException::withMessages(['status' => $e->getMessage()]);
        }

        return redirect()->route('invoices.show', $model->id);
    }

    /**
     * Voids a sent/partially-paid/paid invoice — posting rule P9 (spec §6): a
     * mirror reversal of the invoice's own P1 entry, via InvoiceService::void.
     * $invoice is a plain route parameter (not implicit binding), same
     * reasoning as edit()/update()/destroy()/send()/recordPayment() above.
     *
     * `date` is optional and defaults to today here (not in the service,
     * which requires it) — spec §4 rule 4: a reversal must always be postable
     * in an open period even when the ORIGINAL entry's period has since been
     * locked, so "today" is the safe default a caller gets for free by
     * omitting the field. `memo` defaults to a generic note when omitted so a
     * quick void doesn't require typing one.
     *
     * Three failure shapes surface as a 422 on the generic `status` field,
     * mirroring send()/recordPayment(): InvoiceVoidException (this
     * controller's own domain guards — not voidable, live payments remain),
     * LedgerException (e.g. a locked reversal date), and — as defense in
     * depth for a genuine concurrent double-void race, mirroring
     * ManualJournalController::reverse's narrow catch — a
     * UniqueConstraintViolationException on the journal_entries source-unique
     * index (in practice unreachable in a single-process request because the
     * service's own lockForUpdate()+status-guard already serializes this, but
     * kept as a backstop rather than trusting that guard alone).
     */
    public function void(Request $request, string $invoice, InvoiceService $service): RedirectResponse
    {
        $model = Invoice::query()->findOrFail($invoice);

        $idempotencyKey = $request->header('X-Idempotency-Key');
        if (! is_string($idempotencyKey) || ! Str::isUuid($idempotencyKey)) {
            throw ValidationException::withMessages([
                'idempotency_key' => 'A valid X-Idempotency-Key header (UUID) is required.',
            ]);
        }

        $validated = $request->validate([
            'date' => ['nullable', 'date'],
            'memo' => ['nullable', 'string', 'max:500'],
        ]);

        $date = $validated['date'] ?? CarbonImmutable::today()->toDateString();
        $memo = $validated['memo'] ?? "Void: Invoice {$model->number}";

        try {
            $service->void($idempotencyKey, $model, $date, $memo);
        } catch (InvoiceVoidException|LedgerException $e) {
            throw ValidationException::withMessages(['status' => $e->getMessage()]);
        } catch (UniqueConstraintViolationException $e) {
            if (! $this->isDuplicateReversal($e)) {
                throw $e;
            }

            throw ValidationException::withMessages(['status' => 'This invoice has already been voided.']);
        }

        return redirect()->route('invoices.show', $model->id);
    }

    /**
     * Reverses a single posted payment — posting rules P2/P3's mirror, via
     * InvoicePaymentService::voidPayment. $payment is resolved off the
     * invoice's own relation (not InvoicePayment::findOrFail directly) so a
     * payment that belongs to a DIFFERENT invoice in the same company still
     * 404s here, not just a payment in a different company.
     *
     * Same three-way failure classification as void() above: PaymentVoidException
     * (already reversed), LedgerException (locked period — reversal date is
     * always today here, so only relevant if today itself is locked), and the
     * same narrow UniqueConstraintViolationException backstop.
     */
    public function voidPayment(Request $request, string $invoice, string $payment, InvoicePaymentService $service): RedirectResponse
    {
        $model = Invoice::query()->findOrFail($invoice);
        $paymentModel = $model->payments()->findOrFail($payment);

        $idempotencyKey = $request->header('X-Idempotency-Key');
        if (! is_string($idempotencyKey) || ! Str::isUuid($idempotencyKey)) {
            throw ValidationException::withMessages([
                'idempotency_key' => 'A valid X-Idempotency-Key header (UUID) is required.',
            ]);
        }

        try {
            $service->voidPayment($idempotencyKey, $paymentModel);
        } catch (PaymentVoidException|LedgerException $e) {
            throw ValidationException::withMessages(['status' => $e->getMessage()]);
        } catch (UniqueConstraintViolationException $e) {
            if (! $this->isDuplicateReversal($e)) {
                throw $e;
            }

            throw ValidationException::withMessages(['status' => 'This payment has already been reversed.']);
        }

        return redirect()->route('invoices.show', $model->id);
    }

    /**
     * Whether the violated unique index is the journal_entries source-unique
     * index (one primary + one reversal max per source) — mirrors
     * ManualJournalController::isDuplicateReversal. Prefers the driver's
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

    /**
     * Resolves each line's current tax rate percent (fresh from TaxRate, not
     * a snapshot — tax_rate_snapshot is only ever written at send time, Task
     * 3) and shapes the payload InvoiceCalculator expects.
     *
     * @param  array<int, array<string, mixed>>  $lines
     * @return array<int, array{quantity: string, unit_price: int, tax_rate: string|null}>
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
                'quantity' => (string) $line['quantity'],
                'unit_price' => (int) $line['unit_price'],
                'tax_rate' => $taxRate !== null ? (string) $taxRate->rate : null,
            ];
        })->all();
    }

    /**
     * @param  array<int, array<string, mixed>>  $inputLines
     * @param  array<int, array{line_subtotal: int, line_tax: int}>  $computedLines
     */
    private function createLines(Invoice $invoice, array $inputLines, array $computedLines): void
    {
        foreach (array_values($inputLines) as $index => $line) {
            InvoiceLine::query()->create([
                'invoice_id' => $invoice->id,
                'description' => $line['description'],
                'quantity' => $line['quantity'],
                'unit_price' => $line['unit_price'],
                'income_account_id' => $line['income_account_id'],
                'tax_rate_id' => $line['tax_rate_id'] ?? null,
                // Only ever written at send time (Task 3) — a draft's tax
                // reflects the tax rate's CURRENT value, not a frozen one.
                'tax_rate_snapshot' => null,
                'line_subtotal' => $computedLines[$index]['line_subtotal'],
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
                ->whereIn('type', [ContactType::Customer->value, ContactType::Both->value])
                ->orderBy('name')
                ->get(['id', 'name', 'type'])
                ->map(fn (Contact $contact) => [
                    'id' => $contact->id,
                    'name' => $contact->name,
                    'type' => $contact->type->value,
                ])
                ->values(),
            'items' => Item::query()
                ->where('is_archived', false)
                ->orderBy('name')
                ->get(['id', 'name', 'description', 'unit_price', 'default_income_account_id', 'default_tax_rate_id'])
                ->map(fn (Item $item) => [
                    'id' => $item->id,
                    'name' => $item->name,
                    'description' => $item->description,
                    'unit_price' => $item->unit_price,
                    'default_income_account_id' => $item->default_income_account_id,
                    'default_tax_rate_id' => $item->default_tax_rate_id,
                ])
                ->values(),
            'incomeAccounts' => Account::query()
                ->where('type', AccountType::Income->value)
                ->where('is_archived', false)
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
            'currencies' => Currencies::COMMON,
        ];
    }

    /** @return array<string, mixed> */
    private function invoiceListPayload(Invoice $invoice): array
    {
        return [
            'id' => $invoice->id,
            'number' => $invoice->number,
            'status' => $invoice->status->value,
            'contact_name' => $invoice->contact->name,
            'issue_date' => $invoice->issue_date?->toDateString(),
            'due_date' => $invoice->due_date?->toDateString(),
            'currency_code' => $invoice->currency_code,
            'total' => $invoice->total,
            'balance' => $invoice->balance,
        ];
    }

    /** @return array<string, mixed> */
    private function invoiceEditPayload(Invoice $invoice): array
    {
        return [
            'id' => $invoice->id,
            'number' => $invoice->number,
            'status' => $invoice->status->value,
            'contact_id' => $invoice->contact_id,
            'currency_code' => $invoice->currency_code,
            'issue_date' => $invoice->issue_date?->toDateString(),
            'due_date' => $invoice->due_date?->toDateString(),
            'notes' => $invoice->notes,
            'subtotal' => $invoice->subtotal,
            'tax_total' => $invoice->tax_total,
            'total' => $invoice->total,
            'lines' => $this->linesPayload($invoice->lines),
        ];
    }

    /** @param  Collection<int, InvoiceLine>  $lines
     * @return array<int, array<string, mixed>> */
    private function linesPayload(Collection $lines): array
    {
        return $lines->map(fn (InvoiceLine $line) => [
            'id' => $line->id,
            'description' => $line->description,
            'quantity' => (string) $line->quantity,
            'unit_price' => $line->unit_price,
            'income_account_id' => $line->income_account_id,
            'tax_rate_id' => $line->tax_rate_id,
            'line_subtotal' => $line->line_subtotal,
            'line_tax' => $line->line_tax,
        ])->values()->all();
    }

    /**
     * The full detail payload for Invoices/Show — every field the header,
     * line table, payments list, and action-bar gating (is_opening,
     * email_status) need. Unlike invoiceEditPayload (draft-editor only),
     * this covers every status, plus the BASE-currency mirrors and payments
     * a posted invoice carries.
     *
     * @return array<string, mixed>
     */
    private function invoiceShowPayload(Invoice $invoice): array
    {
        return [
            'id' => $invoice->id,
            'number' => $invoice->number,
            'status' => $invoice->status->value,
            'is_opening' => $invoice->is_opening,
            'contact' => [
                'id' => $invoice->contact->id,
                'name' => $invoice->contact->name,
            ],
            'currency_code' => $invoice->currency_code,
            'fx_rate' => (string) $invoice->fx_rate,
            'issue_date' => $invoice->issue_date?->toDateString(),
            'due_date' => $invoice->due_date?->toDateString(),
            'notes' => $invoice->notes,
            'subtotal' => $invoice->subtotal,
            'tax_total' => $invoice->tax_total,
            'total' => $invoice->total,
            'amount_paid' => $invoice->amount_paid,
            'balance' => $invoice->balance,
            'base_subtotal' => $invoice->base_subtotal,
            'base_tax_total' => $invoice->base_tax_total,
            'base_total' => $invoice->base_total,
            'email_status' => $invoice->email_status,
            'sent_at' => $invoice->sent_at?->toIso8601String(),
            'voided_at' => $invoice->voided_at?->toIso8601String(),
            'lines' => $this->linesPayload($invoice->lines),
            'payments' => $invoice->payments->map(fn (InvoicePayment $payment) => [
                'id' => $payment->id,
                'payment_date' => $payment->payment_date->toDateString(),
                'amount' => $payment->amount,
                'base_amount' => $payment->base_amount,
                'base_ar_relief' => $payment->base_ar_relief,
                'fx_rate' => $payment->fx_rate !== null ? (string) $payment->fx_rate : null,
                'status' => $payment->status,
                'memo' => $payment->memo,
                'bank_account' => $payment->bankAccount !== null ? [
                    'id' => $payment->bankAccount->id,
                    'name' => $payment->bankAccount->name,
                ] : null,
            ])->values()->all(),
        ];
    }
}
