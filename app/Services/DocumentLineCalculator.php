<?php

namespace App\Services;

use App\Support\Money;
use InvalidArgumentException;

/**
 * Pure expense/bill-totals calculator — the AP-side mirror of
 * InvoiceCalculator (Phase 3 T2). No DB access, no float, no side effects.
 *
 * Unlike InvoiceCalculator, lines here are AMOUNT-based, not qty x unit_price
 * (spec §7.8): each line is already the net minor-unit amount the user typed
 * (a category-account posting, not a sold quantity), so there is no
 * multiplyByQuantity step — just tax on the amount, per line, then summed.
 *
 * Rounding happens PER LINE, then the per-line results are summed — never
 * computed once on a pre-summed subtotal — mirroring InvoiceCalculator's own
 * per-line-then-sum discipline (see Money::taxMinorUnits' own doc comment).
 */
class DocumentLineCalculator
{
    /**
     * @param  array<int, array{amount: int, tax_rate: string|null}>  $lines
     *                                                                        amount: integer minor units, net of tax. tax_rate: percent decimal string
     *                                                                        (e.g. "18", "18.5") or null for an untaxed line.
     * @return array{
     *     lines: array<int, array{amount: int, line_tax: int}>,
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
            $amount = $line['amount'];

            if ($amount < 0) {
                throw new InvalidArgumentException('amount must not be negative.');
            }

            $taxRate = $line['tax_rate'] ?? null;
            $lineTax = $taxRate === null ? 0 : Money::taxMinorUnits($amount, $taxRate);

            $computedLines[] = [
                'amount' => $amount,
                'line_tax' => $lineTax,
            ];

            $subtotal += $amount;
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
