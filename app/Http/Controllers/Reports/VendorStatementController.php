<?php

namespace App\Http\Controllers\Reports;

use App\Enums\ContactType;
use App\Http\Controllers\Controller;
use App\Models\Contact;
use App\Services\Reports\ContactLedgerReport;
use App\Services\Reports\ReportPeriod;
use App\Services\StatementPdf;
use App\Support\CompanyContext;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Http\Response as HttpResponse;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Vendor Statement — the AP-side mirror of CustomerStatementController.
 */
class VendorStatementController extends Controller
{
    public function show(Request $request, ContactLedgerReport $report, CompanyContext $context): Response
    {
        [$from, $to, $contactId] = $this->resolveParams($request, $context);

        return Inertia::render('Reports/VendorStatement', [
            'report' => $contactId !== null ? $report->vendorStatement($from, $to, $contactId) : null,
            'contacts' => $this->vendorOptions(),
            'from' => $from->toDateString(),
            'to' => $to->toDateString(),
        ]);
    }

    public function pdf(Request $request, StatementPdf $pdfService, CompanyContext $context): HttpResponse
    {
        $validated = $request->validate([
            'contact_id' => ['required', 'integer'],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date'],
        ]);

        [$from, $to] = $this->resolvePeriod($validated, $context);
        $contactId = (int) $validated['contact_id'];
        $contact = Contact::query()->findOrFail($contactId);

        return $pdfService->render('ap', $from, $to, $contactId)
            ->stream("Statement-{$contact->name}-{$to->toDateString()}.pdf");
    }

    /** @return list<array{id:int, name:string}> */
    private function vendorOptions(): array
    {
        return Contact::query()
            ->whereIn('type', [ContactType::Vendor->value, ContactType::Both->value])
            ->orderBy('name')
            ->get(['id', 'name'])
            ->map(fn (Contact $contact) => ['id' => $contact->id, 'name' => $contact->name])
            ->values()
            ->all();
    }

    /** @return array{0: CarbonImmutable, 1: CarbonImmutable, 2: ?int} */
    private function resolveParams(Request $request, CompanyContext $context): array
    {
        $validated = $request->validate([
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date'],
            'contact_id' => ['nullable', 'integer'],
        ]);

        [$from, $to] = $this->resolvePeriod($validated, $context);

        return [$from, $to, isset($validated['contact_id']) ? (int) $validated['contact_id'] : null];
    }

    /**
     * @param  array{from?: string, to?: string}  $validated
     * @return array{0: CarbonImmutable, 1: CarbonImmutable}
     */
    private function resolvePeriod(array $validated, CompanyContext $context): array
    {
        $today = CarbonImmutable::today();
        $period = new ReportPeriod($context->get()->fiscal_year_start_month);

        $from = isset($validated['from']) ? CarbonImmutable::parse($validated['from']) : $period->currentFiscalYearStart($today);
        $to = isset($validated['to']) ? CarbonImmutable::parse($validated['to']) : $today;

        return [$from, $to];
    }
}
