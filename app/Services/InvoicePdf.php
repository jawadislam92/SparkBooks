<?php

namespace App\Services;

use App\Models\Invoice;
use Barryvdh\DomPDF\Facade\Pdf;
use Barryvdh\DomPDF\PDF as DomPdf;

/**
 * Renders the branded invoice PDF (Phase 3 Task 9): loads an invoice with its
 * lines/contact/company, hands the data to resources/views/pdf/invoice.blade.php,
 * and returns the dompdf wrapper ready to ->stream()/->download(). dompdf
 * renders the Blade view's OWN inline CSS — it doesn't run Tailwind — so the
 * palette hex values are hardcoded directly in that view (PROJECT_MAP.md's
 * design tokens: ink #16323B / spark #0FA678 / paper).
 *
 * The money/rate formatting helpers below live here rather than on
 * app/Support/Money.php because they're pure DISPLAY concerns (currency-code-
 * prefixed strings, thousands separators, 2dp rate rounding) rather than the
 * ledger's own minor-unit arithmetic — but they follow the exact same
 * no-float discipline as every other money path in this app: every amount is
 * split into major/minor parts via integer division/modulo (never a float
 * division), and fx_rate — already a decimal STRING via Invoice's
 * `decimal:8` cast — is rounded through bcmath, never a float cast.
 */
class InvoicePdf
{
    public function render(Invoice $invoice): DomPdf
    {
        return Pdf::loadView('pdf.invoice', $this->viewData($invoice))->setPaper('a4');
    }

    /**
     * Shapes the data the Blade view needs — factored out from render() so
     * tests can render resources/views/pdf/invoice.blade.php directly and
     * assert on the HTML (dompdf's PDF output itself is binary, not
     * assertable on content).
     *
     * @return array<string, mixed>
     */
    public function viewData(Invoice $invoice): array
    {
        $invoice->loadMissing(['contact', 'company', 'lines' => fn ($query) => $query->orderBy('id')]);

        $company = $invoice->company;

        // No number yet (still draft) — the header/meta show "DRAFT" instead
        // of a burned document number (InvoiceService only assigns one at
        // send time).
        $isDraft = $invoice->number === null;

        return [
            'invoice' => $invoice,
            'company' => $company,
            'contact' => $invoice->contact,
            'lines' => $invoice->lines,
            'isDraft' => $isDraft,
            // FX invoice: document currency differs from the company's base
            // currency AND the invoice has been sent — so a real fx_rate has
            // been resolved and base_* hold genuine converted amounts. A
            // DRAFT foreign invoice is deliberately EXCLUDED even though its
            // currency differs from base: a draft's fx_rate is frozen at 1.0
            // and its base_* columns merely MIRROR the document totals (real
            // FX conversion only happens at send time — see
            // InvoiceService::resolveFxRate/convertToBase), so rendering the
            // dual-currency block for a draft would present a fabricated
            // "1 USD = 1.00 PKR" rate and a made-up "≈ base" amount as if
            // real. A foreign draft therefore shows ONLY its document-currency
            // amounts (no ≈ lines, no rate sentence); the block appears once
            // it's actually sent. A domestic invoice (currency === base) never
            // shows the block, draft or sent.
            'isForeign' => ! $isDraft && $invoice->currency_code !== $company->base_currency,
        ];
    }

    /**
     * Integer-only minor-units -> "CODE 1,500.50" formatting: split into
     * major/minor parts via intdiv/%, format each with plain integer-safe
     * helpers, then join — never number_format() on a float division of the
     * minor-unit amount. $minorUnits is always >= 0 for every real invoice
     * amount (every money column here is unsignedBigInteger), but the sign
     * handling below is defensive rather than assumed.
     */
    public static function formatMoney(int $minorUnits, string $currencyCode): string
    {
        $sign = $minorUnits < 0 ? '-' : '';
        $absoluteMinor = abs($minorUnits);
        $majorUnits = intdiv($absoluteMinor, 100);
        $minorRemainder = $absoluteMinor % 100;

        $formatted = $sign.number_format($majorUnits).'.'.str_pad((string) $minorRemainder, 2, '0', STR_PAD_LEFT);

        return trim($currencyCode).' '.$formatted;
    }

    /**
     * Formats an fx_rate decimal STRING (invoices.fx_rate is DECIMAL(16,8),
     * e.g. "280.12345678") down to 2dp for display, half-up rounded — via
     * bcmath (exact decimal-string arithmetic), never a float cast. Adding
     * half a cent then truncating at scale 2 is the standard float-free
     * half-up-rounding identity: round_half_up(x, 2) == truncate(x + 0.005,
     * 2) for any x >= 0 — fx_rate is always positive (FxConverter's own
     * guard rejects <= 0 before a rate ever reaches a sent invoice).
     */
    public static function formatRate(string $rate): string
    {
        $rounded = bcadd(trim($rate), '0.005', 2);
        [$whole, $fraction] = explode('.', $rounded);

        return number_format((int) $whole).'.'.$fraction;
    }

    /**
     * Trims a decimal quantity string (invoice_lines.quantity is
     * DECIMAL(12,4), e.g. "2.5000") down to its shortest readable form
     * ("2.5") for the line-item table. Display only — never used in any
     * calculation, so trimming trailing zeros here can't affect the money
     * math above.
     */
    public static function formatQuantity(string $quantity): string
    {
        if (! str_contains($quantity, '.')) {
            return $quantity;
        }

        return rtrim(rtrim($quantity, '0'), '.');
    }
}
