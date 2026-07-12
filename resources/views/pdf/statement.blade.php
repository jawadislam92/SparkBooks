<!DOCTYPE html>
<html>
<head>
<meta charset="utf-8">
<title>{{ $heading }} — {{ $contact->name }} — {{ $company->name }}</title>
<style>
    @page { margin: 34px 42px 40px 42px; }
    * { box-sizing: border-box; }
    body {
        font-family: 'DejaVu Sans', sans-serif;
        color: #16323B;
        font-size: 10.5px;
        line-height: 1.5;
    }
    table { border-collapse: collapse; }

    .header-table { width: 100%; margin-bottom: 22px; }
    .header-table td { vertical-align: top; }
    .company-name { font-size: 19px; font-weight: bold; color: #16323B; }
    .company-tagline {
        display: block;
        margin-top: 3px;
        font-size: 8.5px;
        color: #2E6273;
        letter-spacing: 0.6px;
        text-transform: uppercase;
    }
    .doc-title {
        text-align: right;
        font-size: 22px;
        font-weight: bold;
        letter-spacing: 1px;
        color: #0FA678;
    }
    .meta-block { text-align: right; margin-top: 8px; font-size: 9.5px; color: #2E6273; }
    .meta-block .meta-row { margin-top: 3px; }
    .meta-block .meta-value { color: #16323B; font-weight: bold; }

    .rule { border-top: 1px solid #E4EEE6; margin: 16px 0; }

    .bill-to-table { width: 100%; margin-bottom: 20px; }
    .bill-to-heading {
        font-size: 8.5px;
        text-transform: uppercase;
        letter-spacing: 0.6px;
        color: #2E6273;
        margin-bottom: 4px;
    }
    .bill-to-name { font-size: 12px; font-weight: bold; color: #16323B; }
    .bill-to-detail { font-size: 9.5px; color: #2E6273; margin-top: 2px; }

    .lines-table { width: 100%; margin-bottom: 4px; }
    .lines-table th {
        text-align: left;
        font-size: 8.5px;
        text-transform: uppercase;
        letter-spacing: 0.5px;
        color: #2E6273;
        border-bottom: 1.5px solid #16323B;
        padding: 6px 6px;
    }
    .lines-table td {
        padding: 7px 6px;
        border-bottom: 1px solid #E4EEE6;
        font-size: 10px;
        vertical-align: top;
    }
    .lines-table th.money, .lines-table td.money {
        text-align: right;
        font-family: 'DejaVu Sans Mono', monospace;
        white-space: nowrap;
    }
    .lines-table tr.balance-row td {
        font-weight: bold;
        background-color: #F4F8F5;
    }
    .lines-table tr.closing-row td {
        font-weight: bold;
        border-top: 2px solid #16323B;
        border-bottom: none;
    }

    .footer {
        clear: both;
        margin-top: 40px;
        padding-top: 12px;
        border-top: 1px solid #E4EEE6;
        font-size: 8.5px;
        color: #2E6273;
    }
</style>
</head>
<body>

<table class="header-table">
    <tr>
        <td style="width:58%;">
            <span class="company-name">{{ $company->name }}</span>
            <span class="company-tagline">{{ $company->base_currency }} · Statement</span>
        </td>
        <td style="width:42%;">
            <div class="doc-title">{{ strtoupper($heading) }}</div>
            <div class="meta-block">
                <div class="meta-row">Period: <span class="meta-value">{{ \Carbon\CarbonImmutable::parse($statement['from'])->format('d M Y') }} – {{ \Carbon\CarbonImmutable::parse($statement['to'])->format('d M Y') }}</span></div>
            </div>
        </td>
    </tr>
</table>

<div class="rule"></div>

<table class="bill-to-table">
    <tr>
        <td style="width:60%;">
            <div class="bill-to-heading">{{ $direction === 'ar' ? 'Customer' : 'Vendor' }}</div>
            <div class="bill-to-name">{{ $contact->name }}</div>
            @if($contact->email)
                <div class="bill-to-detail">{{ $contact->email }}</div>
            @endif
            @if($contact->billing_address)
                <div class="bill-to-detail">{{ $contact->billing_address }}</div>
            @endif
        </td>
    </tr>
</table>

<table class="lines-table">
    <thead>
        <tr>
            <th style="width:16%;">Date</th>
            <th style="width:18%;">Type</th>
            <th style="width:36%;">Reference</th>
            <th style="width:15%;" class="money">Amount</th>
            <th style="width:15%;" class="money">Balance</th>
        </tr>
    </thead>
    <tbody>
        <tr class="balance-row">
            <td colspan="4">Opening balance</td>
            <td class="money">{{ \App\Services\InvoicePdf::formatMoney($statement['opening_balance'], $company->base_currency) }}</td>
        </tr>
        @foreach($statement['transactions'] as $line)
            <tr>
                <td>{{ \Carbon\CarbonImmutable::parse($line['date'])->format('d M Y') }}</td>
                <td>{{ ucfirst($line['type']) }}</td>
                <td>{{ $line['reference'] }}</td>
                <td class="money">{{ \App\Services\InvoicePdf::formatMoney($line['amount'], $company->base_currency) }}</td>
                <td class="money">{{ \App\Services\InvoicePdf::formatMoney($line['running_balance'], $company->base_currency) }}</td>
            </tr>
        @endforeach
        <tr class="closing-row">
            <td colspan="4">Closing balance</td>
            <td class="money">{{ \App\Services\InvoicePdf::formatMoney($statement['closing_balance'], $company->base_currency) }}</td>
        </tr>
    </tbody>
</table>

<div class="footer">
    All amounts are shown in {{ $company->base_currency }}, the company's base currency.
</div>

</body>
</html>
