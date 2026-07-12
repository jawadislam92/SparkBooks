<?php

namespace App\Services;

use App\Models\Contact;
use App\Services\Reports\ContactLedgerReport;
use App\Support\CompanyContext;
use Barryvdh\DomPDF\Facade\Pdf;
use Barryvdh\DomPDF\PDF as DomPdf;
use Carbon\CarbonImmutable;
use RuntimeException;

/**
 * Renders the client-facing Customer/Vendor Statement PDF (Phase 7 Task 6),
 * mirroring InvoicePdf's shape exactly: a thin render() that hands
 * resources/views/pdf/statement.blade.php everything it needs, with the
 * shaping logic factored into viewData() so tests can render the Blade view
 * directly (dompdf's own output is binary, not assertable on content).
 *
 * One blade view serves BOTH directions ('ar'/'ap') — the statement shape
 * (opening/period transactions/closing) is identical either way; only the
 * heading ("Customer statement" vs "Vendor statement") and which
 * ContactLedgerReport method built the data differ.
 */
class StatementPdf
{
    public function __construct(private ContactLedgerReport $report, private CompanyContext $context) {}

    public function render(string $direction, CarbonImmutable $from, CarbonImmutable $to, int $contactId): DomPdf
    {
        return Pdf::loadView('pdf.statement', $this->viewData($direction, $from, $to, $contactId))->setPaper('a4');
    }

    /** @return array<string, mixed> */
    public function viewData(string $direction, CarbonImmutable $from, CarbonImmutable $to, int $contactId): array
    {
        $statement = match ($direction) {
            'ar' => $this->report->customerStatement($from, $to, $contactId),
            'ap' => $this->report->vendorStatement($from, $to, $contactId),
            default => throw new RuntimeException("Unknown statement direction: {$direction}"),
        };

        $contact = Contact::query()->findOrFail($contactId);

        return [
            'direction' => $direction,
            'heading' => $direction === 'ar' ? 'Customer statement' : 'Vendor statement',
            'statement' => $statement,
            'contact' => $contact,
            'company' => $this->context->get(),
        ];
    }
}
