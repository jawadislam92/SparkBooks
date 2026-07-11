@extends('pdf.reports.layout')

@section('body')
    <table class="report-table">
        <thead>
            <tr>
                <th style="width:14%;">Code</th>
                <th style="width:56%;">Name</th>
                <th style="width:15%;" class="money">Debit</th>
                <th style="width:15%;" class="money">Credit</th>
            </tr>
        </thead>
        <tbody>
            @forelse($data['rows'] as $row)
                <tr>
                    <td>{{ $row['code'] }}</td>
                    <td>{{ $row['name'] }}</td>
                    <td class="money">{{ \App\Services\InvoicePdf::formatMoney($row['debit'], $company->base_currency) }}</td>
                    <td class="money">{{ \App\Services\InvoicePdf::formatMoney($row['credit'], $company->base_currency) }}</td>
                </tr>
            @empty
                <tr><td colspan="4" class="empty-note">No account activity for this period.</td></tr>
            @endforelse
            <tr class="total-row">
                <td></td>
                <td>Totals</td>
                <td class="money">{{ \App\Services\InvoicePdf::formatMoney($data['total_debit'], $company->base_currency) }}</td>
                <td class="money">{{ \App\Services\InvoicePdf::formatMoney($data['total_credit'], $company->base_currency) }}</td>
            </tr>
        </tbody>
    </table>
@endsection
