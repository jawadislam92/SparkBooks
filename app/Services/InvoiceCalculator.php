<?php

namespace App\Services;

use App\Support\Money;

/**
 * Pure invoice-totals calculator: no DB access, no float, no side effects —
 * every number in and out is an integer minor unit or a decimal string, and
 * every arithmetic step delegates to Money (bcmath). Deliberately a plain
 * value-in/value-out service (unlike TransferService/OtherReceiptService,
 * which post to the ledger) so InvoiceController can call it identically for
 * both store() and update() without touching a transaction, and so it can be
 * unit-tested with zero app/DB boot (see tests/Unit/InvoiceCalculatorTest).
 *
 * Rounding happens PER LINE, then the per-line results are summed — never
 * computed once on a pre-summed subtotal — so line-level rounding drift never
 * silently accumulates or cancels out in a way a per-line audit couldn't
 * reproduce (mirrors Money::taxMinorUnits' own per-line-then-sum discipline,
 * see MoneyTest::test_three_awkward_lines_sum_without_rounding_drift).
 */
class InvoiceCalculator
{
    /**
     * @param  array<int, array{quantity: string, unit_price: int, tax_rate: string|null}>  $lines
     *                                                                                              quantity: decimal string, up to 4dp (e.g. "2.5"). unit_price: integer minor
     *                                                                                              units. tax_rate: percent decimal string (e.g. "18", "18.5") or null for an
     *                                                                                              untaxed line.
     * @return array{
     *     lines: array<int, array{line_subtotal: int, line_tax: int}>,
     *     subtotal: int,
     *     tax_total: int,
     *     total: int
     * }
     */
    public static function compute(array $lines): array
    {
        $computedLines = [];
        $subtotal = 0;
        $taxTotal = 0;

        foreach ($lines as $line) {
            $lineSubtotal = Money::multiplyByQuantity($line['unit_price'], $line['quantity']);
            $taxRate = $line['tax_rate'] ?? null;
            $lineTax = $taxRate === null ? 0 : Money::taxMinorUnits($lineSubtotal, $taxRate);

            $computedLines[] = [
                'line_subtotal' => $lineSubtotal,
                'line_tax' => $lineTax,
            ];

            $subtotal += $lineSubtotal;
            $taxTotal += $lineTax;
        }

        return [
            'lines' => $computedLines,
            'subtotal' => $subtotal,
            'tax_total' => $taxTotal,
            'total' => $subtotal + $taxTotal,
        ];
    }
}
