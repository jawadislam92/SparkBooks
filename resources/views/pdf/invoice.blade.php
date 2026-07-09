<!DOCTYPE html>
<html>
<head>
<meta charset="utf-8">
<title>{{ $isDraft ? 'Draft invoice' : $invoice->number }} — {{ $company->name }}</title>
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

    /* ---- header ---- */
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
        font-size: 25px;
        font-weight: bold;
        letter-spacing: 1px;
        color: #0FA678;
    }
    .doc-title.is-draft { color: #2E6273; }
    .meta-block { text-align: right; margin-top: 8px; font-size: 9.5px; color: #2E6273; }
    .meta-block .meta-row { margin-top: 3px; }
    .meta-block .meta-value { color: #16323B; font-weight: bold; }
    .status-badge {
        display: inline-block;
        margin-top: 5px;
        padding: 3px 10px;
        border-radius: 3px;
        font-size: 8px;
        font-weight: bold;
        letter-spacing: 0.6px;
        text-transform: uppercase;
        color: #FFFFFF;
        background-color: #0FA678;
    }
    .status-badge.status-draft { background-color: #2E6273; }
    .status-badge.status-void { background-color: #D65A4A; }
    .status-badge.status-paid { background-color: #0C8A64; }
    .status-badge.status-partially_paid { background-color: #E8A13D; }

    .rule { border-top: 1px solid #E4EEE6; margin: 16px 0; }

    /* ---- bill-to / currency ---- */
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

    /* ---- line items ---- */
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

    /* ---- totals ---- */
    .totals-outer { width: 100%; margin-top: 6px; }
    .totals-spacer { width: 55%; }
    .totals-col { width: 45%; }
    .totals-table { width: 100%; }
    .totals-table td { padding: 4px 0; font-size: 10px; }
    .totals-table td.label { color: #2E6273; }
    .totals-table td.money {
        text-align: right;
        font-family: 'DejaVu Sans Mono', monospace;
        white-space: nowrap;
    }
    .totals-table tr.grand td {
        border-top: 2px solid #16323B;
        padding-top: 8px;
        font-size: 12.5px;
        font-weight: bold;
    }
    .totals-table tr.fx-line td {
        padding-top: 0;
        padding-bottom: 6px;
        font-size: 8.5px;
        color: #2E6273;
        font-weight: normal;
        border-top: none;
    }

    .fx-rate-note {
        clear: both;
        text-align: right;
        font-size: 8.5px;
        color: #2E6273;
        padding-top: 8px;
    }

    .footer {
        clear: both;
        margin-top: 50px;
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
            <span class="company-tagline">{{ $company->base_currency }} · Tax invoice</span>
        </td>
        <td style="width:42%;">
            <div class="doc-title {{ $isDraft ? 'is-draft' : '' }}">{{ $isDraft ? 'DRAFT' : 'INVOICE' }}</div>
            <div class="meta-block">
                <div class="meta-row">Number: <span class="meta-value">{{ $isDraft ? 'DRAFT' : $invoice->number }}</span></div>
                <div class="meta-row">Issue date: <span class="meta-value">{{ $invoice->issue_date?->format('d M Y') ?? '—' }}</span></div>
                <div class="meta-row">Due date: <span class="meta-value">{{ $invoice->due_date?->format('d M Y') ?? '—' }}</span></div>
                <div class="meta-row">
                    <span class="status-badge status-{{ $invoice->status->value }}">{{ ucwords(str_replace('_', ' ', $invoice->status->value)) }}</span>
                </div>
            </div>
        </td>
    </tr>
</table>

<div class="rule"></div>

<table class="bill-to-table">
    <tr>
        <td style="width:60%;">
            <div class="bill-to-heading">Bill To</div>
            <div class="bill-to-name">{{ $contact->name }}</div>
            @if($contact->email)
                <div class="bill-to-detail">{{ $contact->email }}</div>
            @endif
            @if($contact->billing_address)
                <div class="bill-to-detail">{{ $contact->billing_address }}</div>
            @endif
            @if($contact->tax_number)
                <div class="bill-to-detail">Tax No. {{ $contact->tax_number }}</div>
            @endif
        </td>
        <td style="width:40%; text-align:right;">
            <div class="bill-to-heading">Currency</div>
            <div class="bill-to-name">{{ $invoice->currency_code }}</div>
            @if($isForeign)
                <div class="bill-to-detail">1 {{ $invoice->currency_code }} = {{ \App\Services\InvoicePdf::formatRate($invoice->fx_rate) }} {{ $company->base_currency }}</div>
            @endif
        </td>
    </tr>
</table>

<table class="lines-table">
    <thead>
        <tr>
            <th style="width:46%;">Description</th>
            <th style="width:14%;" class="money">Qty</th>
            <th style="width:18%;" class="money">Unit price</th>
            <th style="width:22%;" class="money">Amount</th>
        </tr>
    </thead>
    <tbody>
        @foreach($lines as $line)
            <tr>
                <td>{{ $line->description }}</td>
                <td class="money">{{ \App\Services\InvoicePdf::formatQuantity((string) $line->quantity) }}</td>
                <td class="money">{{ \App\Services\InvoicePdf::formatMoney($line->unit_price, $invoice->currency_code) }}</td>
                <td class="money">{{ \App\Services\InvoicePdf::formatMoney($line->line_subtotal, $invoice->currency_code) }}</td>
            </tr>
        @endforeach
    </tbody>
</table>

<table class="totals-outer">
    <tr>
        <td class="totals-spacer"></td>
        <td class="totals-col">
            <table class="totals-table">
                <tr>
                    <td class="label">Subtotal</td>
                    <td class="money">{{ \App\Services\InvoicePdf::formatMoney($invoice->subtotal, $invoice->currency_code) }}</td>
                </tr>
                @if($isForeign)
                    <tr class="fx-line">
                        <td></td>
                        <td class="money">≈ {{ \App\Services\InvoicePdf::formatMoney($invoice->base_subtotal, $company->base_currency) }}</td>
                    </tr>
                @endif
                <tr>
                    <td class="label">Tax</td>
                    <td class="money">{{ \App\Services\InvoicePdf::formatMoney($invoice->tax_total, $invoice->currency_code) }}</td>
                </tr>
                @if($isForeign)
                    <tr class="fx-line">
                        <td></td>
                        <td class="money">≈ {{ \App\Services\InvoicePdf::formatMoney($invoice->base_tax_total, $company->base_currency) }}</td>
                    </tr>
                @endif
                <tr class="grand">
                    <td class="label">Total</td>
                    <td class="money">{{ \App\Services\InvoicePdf::formatMoney($invoice->total, $invoice->currency_code) }}</td>
                </tr>
                @if($isForeign)
                    <tr class="fx-line">
                        <td></td>
                        <td class="money">≈ {{ \App\Services\InvoicePdf::formatMoney($invoice->base_total, $company->base_currency) }}</td>
                    </tr>
                @endif
            </table>
        </td>
    </tr>
</table>

@if($isForeign)
    <div class="fx-rate-note">
        Total ≈ {{ \App\Services\InvoicePdf::formatMoney($invoice->base_total, $company->base_currency) }}
        at 1 {{ $invoice->currency_code }} = {{ \App\Services\InvoicePdf::formatRate($invoice->fx_rate) }} {{ $company->base_currency }}
    </div>
@endif

<div class="footer">
    Thank you for your business.
    @if($invoice->notes)
        <br>{{ $invoice->notes }}
    @endif
</div>

</body>
</html>
