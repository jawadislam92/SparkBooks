@extends('pdf.reports.layout')

@section('body')
    @if(empty($data['accounts']))
        <p class="empty-note">No account activity for this period.</p>
    @endif

    @foreach($data['accounts'] as $account)
        <table class="report-table" style="margin-bottom:12px;">
            <thead>
                <tr>
                    <th style="width:26%;">{{ $account['code'] }} — {{ $account['name'] }}</th>
                    <th style="width:14%;">Entry</th>
                    <th style="width:20%;">Source</th>
                    <th style="width:14%;">Contact</th>
                    <th style="width:9%;" class="money">Debit</th>
                    <th style="width:9%;" class="money">Credit</th>
                    <th style="width:8%;" class="money">Balance</th>
                </tr>
            </thead>
            <tbody>
                <tr class="muted-row">
                    <td>Opening balance</td>
                    <td></td>
                    <td></td>
                    <td></td>
                    <td class="money"></td>
                    <td class="money"></td>
                    <td class="money">{{ \App\Services\InvoicePdf::formatMoney($account['opening_balance'], $company->base_currency) }}</td>
                </tr>
                @foreach($account['lines'] as $line)
                    <tr>
                        <td>{{ $line['entry_date'] }}</td>
                        <td>{{ $line['entry_number_display'] ?? 'Entry #'.$line['entry_id'] }}</td>
                        <td>{{ $line['source_label'] }}</td>
                        <td>{{ $line['contact_name'] ?? '' }}</td>
                        <td class="money">{{ \App\Services\InvoicePdf::formatMoney($line['debit'], $company->base_currency) }}</td>
                        <td class="money">{{ \App\Services\InvoicePdf::formatMoney($line['credit'], $company->base_currency) }}</td>
                        <td class="money">{{ \App\Services\InvoicePdf::formatMoney($line['running_balance'], $company->base_currency) }}</td>
                    </tr>
                @endforeach
                <tr class="muted-row">
                    <td>Closing balance</td>
                    <td></td>
                    <td></td>
                    <td></td>
                    <td class="money"></td>
                    <td class="money"></td>
                    <td class="money">{{ \App\Services\InvoicePdf::formatMoney($account['closing_balance'], $company->base_currency) }}</td>
                </tr>
            </tbody>
        </table>
    @endforeach
@endsection
