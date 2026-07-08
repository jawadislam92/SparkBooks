<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreTaxRateRequest;
use App\Http\Requests\UpdateTaxRateRequest;
use App\Models\TaxRate;
use App\Support\AuditLogger;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

class TaxRateController extends Controller
{
    private const AUDITED_FIELDS = ['name', 'rate'];

    public function index(): Response
    {
        $taxRates = TaxRate::query()
            ->orderBy('name')
            ->get()
            ->map(fn (TaxRate $taxRate) => $this->taxRatePayload($taxRate))
            ->values();

        return Inertia::render('Settings/TaxRates', [
            'taxRates' => $taxRates,
        ]);
    }

    public function store(StoreTaxRateRequest $request, AuditLogger $audit): RedirectResponse
    {
        $data = $request->validated();

        $taxRate = TaxRate::query()->create([
            'name' => $data['name'],
            'rate' => $data['rate'],
        ]);

        $audit->log('tax_rate.created', $taxRate, after: $taxRate->only(self::AUDITED_FIELDS));

        return redirect()->route('tax-rates.index');
    }

    /**
     * $taxRate is deliberately a plain route parameter, not an implicit
     * Eloquent binding: TaxRate's BelongsToCompany global scope requires
     * CompanyContext to already be set, but SubstituteBindings (which
     * resolves implicit bindings) runs as part of the framework's "web"
     * middleware group — earlier than this route's own auth/company/can
     * middleware, and earlier than SetCompanyContext. Doing the lookup here,
     * inside the controller action, guarantees the company context is
     * already in place. Mirrors ContactController.
     */
    public function update(UpdateTaxRateRequest $request, string $taxRate, AuditLogger $audit): RedirectResponse
    {
        $model = TaxRate::query()->findOrFail($taxRate);
        $before = $model->only(self::AUDITED_FIELDS);
        $data = $request->validated();

        $model->update([
            'name' => $data['name'],
            'rate' => $data['rate'],
        ]);

        $audit->log('tax_rate.updated', $model, before: $before, after: $model->only(self::AUDITED_FIELDS));

        return redirect()->route('tax-rates.index');
    }

    public function archive(string $taxRate, AuditLogger $audit): RedirectResponse
    {
        $model = TaxRate::query()->findOrFail($taxRate);
        $model->update(['is_archived' => true]);
        $audit->log('tax_rate.archived', $model);

        return redirect()->route('tax-rates.index');
    }

    public function unarchive(string $taxRate, AuditLogger $audit): RedirectResponse
    {
        $model = TaxRate::query()->findOrFail($taxRate);
        $model->update(['is_archived' => false]);
        $audit->log('tax_rate.unarchived', $model);

        return redirect()->route('tax-rates.index');
    }

    /** @return array<string, mixed> */
    private function taxRatePayload(TaxRate $taxRate): array
    {
        return [
            'id' => $taxRate->id,
            'name' => $taxRate->name,
            'rate' => $taxRate->rate,
            'is_archived' => $taxRate->is_archived,
        ];
    }
}
