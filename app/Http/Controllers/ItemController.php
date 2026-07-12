<?php

namespace App\Http\Controllers;

use App\Enums\AccountType;
use App\Http\Requests\StoreItemRequest;
use App\Http\Requests\UpdateItemRequest;
use App\Models\Account;
use App\Models\Item;
use App\Models\TaxRate;
use App\Support\AuditLogger;
use App\Support\CsvExport;
use App\Support\Money;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ItemController extends Controller
{
    private const AUDITED_FIELDS = [
        'name',
        'description',
        'unit_price',
        'default_income_account_id',
        'default_tax_rate_id',
    ];

    public function index(): Response
    {
        $items = Item::query()
            ->with(['incomeAccount', 'taxRate'])
            ->orderBy('name')
            ->get()
            ->map(fn (Item $item) => $this->itemPayload($item))
            ->values();

        return Inertia::render('Items/Index', [
            'items' => $items,
            'incomeAccounts' => $this->incomeAccountOptions(),
            'taxRates' => $this->taxRateOptions(),
        ]);
    }

    /**
     * List-screen CSV export (Phase 6 Task 3, spec §11/§17.8) — the SAME
     * scope Items/Index reads (every item for the current company, archived
     * included — mirrors the index's own full load, which the client itself
     * filters). No status/type filter here — unlike Invoice/Bill/Expense,
     * an item has no such dimension. Gated `exports.run` in addition to this
     * controller's own `items.manage` group (routes/web.php). Audit-logged
     * via the shared CsvExport helper.
     */
    public function exportCsv(AuditLogger $audit): StreamedResponse
    {
        $items = Item::query()
            ->with(['incomeAccount', 'taxRate'])
            ->orderBy('name')
            ->get();

        $rows = $items->map(fn (Item $item) => [
            $item->name,
            $item->description,
            Money::minorToDecimalString($item->unit_price),
            $item->incomeAccount?->name,
            $item->taxRate?->name,
            $item->is_archived ? 'Yes' : 'No',
        ]);

        return CsvExport::stream(
            'items.csv',
            ['Name', 'Description', 'Default Price', 'Income Account', 'Tax Rate', 'Archived'],
            $rows,
            $audit,
            'items.exported',
        );
    }

    public function store(StoreItemRequest $request, AuditLogger $audit): RedirectResponse
    {
        $data = $request->validated();

        $item = Item::query()->create([
            'name' => $data['name'],
            'description' => $data['description'] ?? null,
            'unit_price' => $data['unit_price'] ?? 0,
            'default_income_account_id' => $data['default_income_account_id'] ?? null,
            'default_tax_rate_id' => $data['default_tax_rate_id'] ?? null,
        ]);

        $audit->log('item.created', $item, after: $item->only(self::AUDITED_FIELDS));

        return redirect()->route('items.index');
    }

    /**
     * $item is deliberately a plain route parameter, not an implicit
     * Eloquent binding: Item's BelongsToCompany global scope requires
     * CompanyContext to already be set, but SubstituteBindings (which
     * resolves implicit bindings) runs as part of the framework's "web"
     * middleware group — earlier than this route's own auth/company/can
     * middleware, and earlier than SetCompanyContext. Doing the lookup here,
     * inside the controller action, guarantees the company context is
     * already in place. Mirrors ContactController/TaxRateController.
     */
    public function update(UpdateItemRequest $request, string $item, AuditLogger $audit): RedirectResponse
    {
        $model = Item::query()->findOrFail($item);
        $before = $model->only(self::AUDITED_FIELDS);
        $data = $request->validated();

        $model->update([
            'name' => $data['name'],
            'description' => $data['description'] ?? null,
            'unit_price' => $data['unit_price'] ?? 0,
            'default_income_account_id' => $data['default_income_account_id'] ?? null,
            'default_tax_rate_id' => $data['default_tax_rate_id'] ?? null,
        ]);

        $audit->log('item.updated', $model, before: $before, after: $model->only(self::AUDITED_FIELDS));

        return redirect()->route('items.index');
    }

    public function archive(string $item, AuditLogger $audit): RedirectResponse
    {
        $model = Item::query()->findOrFail($item);
        $model->update(['is_archived' => true]);
        $audit->log('item.archived', $model);

        return redirect()->route('items.index');
    }

    public function unarchive(string $item, AuditLogger $audit): RedirectResponse
    {
        $model = Item::query()->findOrFail($item);
        $model->update(['is_archived' => false]);
        $audit->log('item.unarchived', $model);

        return redirect()->route('items.index');
    }

    /**
     * Income-account picker options: income-type, non-archived only. Returned
     * on every index load (rather than a separate endpoint) since the page
     * always needs them for the create/edit modal.
     *
     * @return array<int, array<string, mixed>>
     */
    private function incomeAccountOptions(): array
    {
        return Account::query()
            ->where('type', AccountType::Income->value)
            ->active()
            ->orderBy('code')
            ->get(['id', 'code', 'name'])
            ->map(fn (Account $account) => [
                'id' => $account->id,
                'code' => $account->code,
                'name' => $account->name,
            ])
            ->values()
            ->all();
    }

    /** @return array<int, array<string, mixed>> */
    private function taxRateOptions(): array
    {
        return TaxRate::query()
            ->where('is_archived', false)
            ->orderBy('name')
            ->get(['id', 'name', 'rate'])
            ->map(fn (TaxRate $taxRate) => [
                'id' => $taxRate->id,
                'name' => $taxRate->name,
                'rate' => $taxRate->rate,
            ])
            ->values()
            ->all();
    }

    /** @return array<string, mixed> */
    private function itemPayload(Item $item): array
    {
        return [
            'id' => $item->id,
            'name' => $item->name,
            'description' => $item->description,
            'unit_price' => $item->unit_price,
            'default_income_account_id' => $item->default_income_account_id,
            'default_income_account_name' => $item->incomeAccount?->name,
            'default_tax_rate_id' => $item->default_tax_rate_id,
            'default_tax_rate_name' => $item->taxRate?->name,
            'is_archived' => $item->is_archived,
        ];
    }
}
