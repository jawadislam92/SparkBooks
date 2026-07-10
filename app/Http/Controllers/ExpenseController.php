<?php

namespace App\Http\Controllers;

use App\Enums\ExpenseStatus;
use App\Exceptions\IneligibleCategoryAccount;
use App\Http\Requests\StoreExpenseRequest;
use App\Http\Requests\UpdateExpenseRequest;
use App\Models\Account;
use App\Models\BankAccount;
use App\Models\Contact;
use App\Models\Expense;
use App\Models\ExpenseLine;
use App\Models\TaxRate;
use App\Services\DocumentLineCalculator;
use App\Support\AuditLogger;
use App\Support\CategoryAccountGuard;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

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
            ->with('contact')
            ->orderByDesc('id')
            ->get()
            ->map(fn (Expense $expense) => $this->expenseListPayload($expense))
            ->values();

        return Inertia::render('Expenses/Index', [
            'expenses' => $expenses,
        ]);
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
        $model = Expense::query()->with('lines')->findOrFail($expense);

        return Inertia::render('Expenses/Edit', array_merge(
            ['expense' => $this->expenseEditPayload($model)],
            $this->formOptions(),
        ));
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

    public function destroy(string $expense, AuditLogger $audit): RedirectResponse
    {
        $model = Expense::query()->findOrFail($expense);

        if (! $model->status->canEdit()) {
            throw ValidationException::withMessages([
                'status' => 'Only draft expenses can be deleted. Void a recorded expense instead.',
            ]);
        }

        $before = $model->only(self::AUDITED_FIELDS);

        DB::transaction(function () use ($model) {
            $model->lines()->get()->each(fn (ExpenseLine $line) => $line->delete());
            $model->delete();
        });

        $audit->log('expense.draft_deleted', $model, before: $before);

        return redirect()->route('expenses.index');
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
                ->where('is_archived', false)
                ->orderBy('name')
                ->get(['id', 'name', 'type'])
                ->map(fn (Contact $contact) => [
                    'id' => $contact->id,
                    'name' => $contact->name,
                    'type' => $contact->type->value,
                ])
                ->values(),
            'bankAccounts' => BankAccount::query()
                ->where('is_archived', false)
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
            'total' => $expense->total,
        ];
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
}
