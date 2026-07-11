@extends('pdf.reports.layout')

@section('body')
    <table class="report-table">
        <thead>
            <tr>
                <th style="width:28%;">Contact</th>
                @foreach($data['buckets'] as $bucketKey)
                    <th class="money">{{ $data['bucket_labels'][$bucketKey] }}</th>
                @endforeach
                <th class="money">Total</th>
            </tr>
        </thead>
        <tbody>
            @forelse($data['contacts'] as $row)
                <tr>
                    <td>{{ $row['name'] }}</td>
                    @foreach($data['buckets'] as $bucketKey)
                        <td class="money">{{ \App\Services\InvoicePdf::formatMoney($row['buckets'][$bucketKey], $company->base_currency) }}</td>
                    @endforeach
                    <td class="money">{{ \App\Services\InvoicePdf::formatMoney($row['total'], $company->base_currency) }}</td>
                </tr>
            @empty
                <tr><td colspan="{{ count($data['buckets']) + 2 }}" class="empty-note">Nothing outstanding as of this date.</td></tr>
            @endforelse
            <tr class="total-row">
                <td>Totals</td>
                @foreach($data['buckets'] as $bucketKey)
                    <td class="money">{{ \App\Services\InvoicePdf::formatMoney($data['totals'][$bucketKey], $company->base_currency) }}</td>
                @endforeach
                <td class="money">{{ \App\Services\InvoicePdf::formatMoney($data['grand_total'], $company->base_currency) }}</td>
            </tr>
        </tbody>
    </table>
@endsection
