@extends('pdf.reports.layout')

@section('body')
    <table class="report-table">
        <thead>
            <tr>
                <th style="width:80%;">Item</th>
                <th style="width:20%;" class="money">Amount</th>
            </tr>
        </thead>
        <tbody>
            <tr class="section-row"><td colspan="2">Operating activities</td></tr>
            <tr>
                <td>Net income</td>
                <td class="money">{{ \App\Services\InvoicePdf::formatMoney($data['operating']['net_income'], $company->base_currency) }}</td>
            </tr>
            @foreach($data['operating']['add_backs']['rows'] as $row)
                <tr>
                    <td>{{ $row['name'] }}</td>
                    <td class="money">{{ \App\Services\InvoicePdf::formatMoney($row['amount'], $company->base_currency) }}</td>
                </tr>
            @endforeach
            <tr>
                <td>Foreign exchange (gain)/loss — reclassified below</td>
                <td class="money">{{ \App\Services\InvoicePdf::formatMoney($data['operating']['fx_reclass'], $company->base_currency) }}</td>
            </tr>
            @foreach($data['operating']['working_capital']['rows'] as $row)
                <tr>
                    <td>{{ $row['name'] }}</td>
                    <td class="money">{{ \App\Services\InvoicePdf::formatMoney($row['amount'], $company->base_currency) }}</td>
                </tr>
            @endforeach
            <tr class="subtotal-row">
                <td>Net cash from operating activities</td>
                <td class="money">{{ \App\Services\InvoicePdf::formatMoney($data['operating']['total'], $company->base_currency) }}</td>
            </tr>

            <tr class="section-row"><td colspan="2">Investing activities</td></tr>
            @forelse($data['investing']['rows'] as $row)
                <tr>
                    <td>{{ $row['name'] }}</td>
                    <td class="money">{{ \App\Services\InvoicePdf::formatMoney($row['amount'], $company->base_currency) }}</td>
                </tr>
            @empty
                <tr><td colspan="2" class="empty-note">No investing activity.</td></tr>
            @endforelse
            <tr class="subtotal-row">
                <td>Net cash from investing activities</td>
                <td class="money">{{ \App\Services\InvoicePdf::formatMoney($data['investing']['total'], $company->base_currency) }}</td>
            </tr>

            <tr class="section-row"><td colspan="2">Financing activities</td></tr>
            @forelse($data['financing']['rows'] as $row)
                <tr>
                    <td>{{ $row['name'] }}</td>
                    <td class="money">{{ \App\Services\InvoicePdf::formatMoney($row['amount'], $company->base_currency) }}</td>
                </tr>
            @empty
                <tr><td colspan="2" class="empty-note">No financing activity.</td></tr>
            @endforelse
            <tr class="subtotal-row">
                <td>Net cash from financing activities</td>
                <td class="money">{{ \App\Services\InvoicePdf::formatMoney($data['financing']['total'], $company->base_currency) }}</td>
            </tr>

            <tr>
                <td>Effect of exchange rate changes on cash</td>
                <td class="money">{{ \App\Services\InvoicePdf::formatMoney($data['fx_on_cash'], $company->base_currency) }}</td>
            </tr>
            <tr class="subtotal-row">
                <td>Net change in cash</td>
                <td class="money">{{ \App\Services\InvoicePdf::formatMoney($data['net_change_in_cash'], $company->base_currency) }}</td>
            </tr>
            <tr>
                <td>Cash at beginning of period</td>
                <td class="money">{{ \App\Services\InvoicePdf::formatMoney($data['opening_cash'], $company->base_currency) }}</td>
            </tr>
            <tr class="total-row">
                <td>Cash at end of period</td>
                <td class="money">{{ \App\Services\InvoicePdf::formatMoney($data['closing_cash'], $company->base_currency) }}</td>
            </tr>
        </tbody>
    </table>
@endsection
