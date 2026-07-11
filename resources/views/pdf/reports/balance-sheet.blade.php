@extends('pdf.reports.layout')

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
            <tr class="section-row"><td colspan="3">Assets</td></tr>
            @forelse($data['assets']['rows'] as $row)
                <tr>
                    <td>{{ $row['code'] }}</td>
                    <td>{{ $row['name'] }}</td>
                    <td class="money">{{ \App\Services\InvoicePdf::formatMoney($row['amount'], $company->base_currency) }}</td>
                </tr>
            @empty
                <tr><td colspan="3" class="empty-note">No assets posted as of this date.</td></tr>
            @endforelse
            <tr class="subtotal-row">
                <td></td>
                <td>Total Assets</td>
                <td class="money">{{ \App\Services\InvoicePdf::formatMoney($data['assets']['total'], $company->base_currency) }}</td>
            </tr>

            <tr class="section-row"><td colspan="3">Liabilities</td></tr>
            @forelse($data['liabilities']['rows'] as $row)
                <tr>
                    <td>{{ $row['code'] }}</td>
                    <td>{{ $row['name'] }}</td>
                    <td class="money">{{ \App\Services\InvoicePdf::formatMoney($row['amount'], $company->base_currency) }}</td>
                </tr>
            @empty
                <tr><td colspan="3" class="empty-note">No liabilities posted as of this date.</td></tr>
            @endforelse
            <tr class="subtotal-row">
                <td></td>
                <td>Total Liabilities</td>
                <td class="money">{{ \App\Services\InvoicePdf::formatMoney($data['liabilities']['total'], $company->base_currency) }}</td>
            </tr>

            <tr class="section-row"><td colspan="3">Equity</td></tr>
            @foreach($data['equity']['rows'] as $row)
                <tr>
                    <td>{{ $row['code'] }}</td>
                    <td>{{ $row['name'] }}</td>
                    <td class="money">{{ \App\Services\InvoicePdf::formatMoney($row['amount'], $company->base_currency) }}</td>
                </tr>
            @endforeach
            <tr>
                <td></td>
                <td>Retained Earnings</td>
                <td class="money">{{ \App\Services\InvoicePdf::formatMoney($data['equity']['retained_earnings'], $company->base_currency) }}</td>
            </tr>
            <tr>
                <td></td>
                <td>Current Year Earnings</td>
                <td class="money">{{ \App\Services\InvoicePdf::formatMoney($data['equity']['current_year_earnings'], $company->base_currency) }}</td>
            </tr>
            <tr class="subtotal-row">
                <td></td>
                <td>Total Equity</td>
                <td class="money">{{ \App\Services\InvoicePdf::formatMoney($data['equity']['total'], $company->base_currency) }}</td>
            </tr>

            <tr class="total-row">
                <td></td>
                <td>Total Liabilities &amp; Equity</td>
                <td class="money">{{ \App\Services\InvoicePdf::formatMoney($data['total_liabilities_and_equity'], $company->base_currency) }}</td>
            </tr>
        </tbody>
    </table>
@endsection
