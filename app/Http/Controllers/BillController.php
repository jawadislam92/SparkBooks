<?php

namespace App\Http\Controllers;

use App\Enums\BillStatus;
use App\Enums\ContactType;
use App\Exceptions\Documents\DocumentPostException;
use App\Exceptions\IneligibleCategoryAccount;
use App\Exceptions\Ledger\LedgerException;
use App\Http\Requests\StoreBillRequest;
use App\Http\Requests\UpdateBillRequest;
use App\Models\Account;
use App\Models\Bill;
use App\Models\BillLine;
use App\Models\Contact;
use App\Models\TaxRate;
use App\Services\BillService;
use App\Services\DocumentLineCalculator;
use App\Support\AuditLogger;
use App\Support\CategoryAccountGuard;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Draft-only bill CRUD (Phase 4 Task 2) — the AP-side mirror of
 * ExpenseController's own draft CRUD (this same task), which itself mirrors
 * InvoiceController (Phase 3 Task 2). No ledger posting: a bill created/
 * edited here always has status=draft. Only a draft bill is editable/
 * deletable — BillLine::booted() enforces the same rule at the model layer
 * as a defense-in-depth backstop below the checks in update()/destroy()
 * here. "Receive" (posting P6/P6t) and payments (P7) are later Phase 4
 * tasks.
 *
 * Unlike Expense: `contact_id` is REQUIRED and must be a vendor/both contact
 * (spec §7.8 — a bill always names a vendor); there is no `bank_account_id`
 * (a bill is on credit — the paying bank account is chosen per BillPayment,
 * a later task); `reference` carries the vendor's own bill number (no
 * internal numbering, per Task 1).
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
            ->with('contact')
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
        $model = Bill::query()->with('lines')->findOrFail($bill);

        return Inertia::render('Bills/Edit', array_merge(
            ['bill' => $this->billEditPayload($model)],
            $this->formOptions(),
        ));
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

        return redirect()->route('bills.edit', $model->id);
    }

    public function destroy(string $bill, AuditLogger $audit): RedirectResponse
    {
        $model = Bill::query()->findOrFail($bill);

        if (! $model->status->canEdit()) {
            throw ValidationException::withMessages([
                'status' => 'Only draft bills can be deleted. Void a received bill instead.',
            ]);
        }

        $before = $model->only(self::AUDITED_FIELDS);

        DB::transaction(function () use ($model) {
            $model->lines()->get()->each(fn (BillLine $line) => $line->delete());
            $model->delete();
        });

        $audit->log('bill.draft_deleted', $model, before: $before);

        return redirect()->route('bills.index');
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
            'total' => $bill->total,
            'balance' => $bill->balance,
        ];
    }

    /** @return array<string, mixed> */
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
        ];
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
