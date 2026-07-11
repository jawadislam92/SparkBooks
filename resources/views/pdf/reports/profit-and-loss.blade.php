@extends('pdf.reports.layout')

{{--
    Full-range totals only (no per-month columns) — the monthly breakdown is
    the CSV/screen's job (ProfitAndLossReport still computes it, this PDF
    just doesn't need a dozen cramped columns on an A4 page to make its own
    Total column tie out).
--}}
@section('body')
    <table class="report-table">
        <thead>
            <tr>
                <th style="width:14%;">Code</th>
                <th style="width:66%;">Name</th>
                <th style="width:20%;" class="money">Amount</th>
            </tr>
        </thead>
        <tbody>
            <tr class="section-row"><td colspan="3">Income</td></tr>
            @forelse($data['income']['rows'] as $row)
                <tr>
                    <td>{{ $row['code'] }}</td>
                    <td>{{ $row['name'] }}</td>
                    <td class="money">{{ \App\Services\InvoicePdf::formatMoney($row['amount'], $company->base_currency) }}</td>
                </tr>
            @empty
                <tr><td colspan="3" class="empty-note">No income for this period.</td></tr>
            @endforelse
            <tr class="subtotal-row">
                <td></td>
                <td>Total Income</td>
                <td class="money">{{ \App\Services\InvoicePdf::formatMoney($data['income']['total'], $company->base_currency) }}</td>
            </tr>

            <tr class="section-row"><td colspan="3">Expenses</td></tr>
            @forelse($data['expenses']['rows'] as $row)
                <tr>
                    <td>{{ $row['code'] }}</td>
                    <td>{{ $row['name'] }}</td>
                    <td class="money">{{ \App\Services\InvoicePdf::formatMoney($row['amount'], $company->base_currency) }}</td>
                </tr>
            @empty
                <tr><td colspan="3" class="empty-note">No expenses for this period.</td></tr>
            @endforelse
            <tr class="subtotal-row">
                <td></td>
                <td>Total Expenses</td>
                <td class="money">{{ \App\Services\InvoicePdf::formatMoney($data['expenses']['total'], $company->base_currency) }}</td>
            </tr>

            <tr class="total-row">
                <td></td>
                <td>Net Profit</td>
                <td class="money">{{ \App\Services\InvoicePdf::formatMoney($data['net_profit'], $company->base_currency) }}</td>
            </tr>
        </tbody>
    </table>
@endsection
