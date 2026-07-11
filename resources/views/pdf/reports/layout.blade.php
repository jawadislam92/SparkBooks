<!DOCTYPE html>
<html>
<head>
<meta charset="utf-8">
<title>{{ $title }} — {{ $company->name }}</title>
<style>
    @page { margin: 34px 42px 40px 42px; }
    * { box-sizing: border-box; }
    body {
        font-family: 'DejaVu Sans', sans-serif;
        color: #16323B;
        font-size: 9.5px;
        line-height: 1.5;
    }
    table { border-collapse: collapse; width: 100%; }

    /* ---- header (mirrors resources/views/pdf/invoice.blade.php) ---- */
    .header-table { margin-bottom: 18px; }
    .header-table td { vertical-align: top; }
    .company-name { font-size: 16px; font-weight: bold; color: #16323B; }
    .company-tagline {
        display: block;
        margin-top: 3px;
        font-size: 8px;
        color: #2E6273;
        letter-spacing: 0.6px;
        text-transform: uppercase;
    }
    .doc-title {
        text-align: right;
        font-size: 18px;
        font-weight: bold;
        letter-spacing: 0.6px;
        color: #0FA678;
    }
    .meta-block { text-align: right; margin-top: 5px; font-size: 9px; color: #2E6273; }

    .rule { border-top: 1px solid #E4EEE6; margin: 14px 0; }

    /* ---- report tables ---- */
    .report-table th {
        text-align: left;
        font-size: 8px;
        text-transform: uppercase;
        letter-spacing: 0.4px;
        color: #2E6273;
        border-bottom: 1.5px solid #16323B;
        padding: 5px 5px;
    }
    .report-table td {
        padding: 4px 5px;
        border-bottom: 1px solid #E4EEE6;
        font-size: 9px;
        vertical-align: top;
    }
    .report-table th.money, .report-table td.money {
        text-align: right;
        font-family: 'DejaVu Sans Mono', monospace;
        white-space: nowrap;
    }
    .report-table tr.section-row td {
        padding-top: 10px;
        font-weight: bold;
        font-size: 9.5px;
        color: #16323B;
        border-bottom: none;
    }
    .report-table tr.total-row td {
        font-weight: bold;
        border-top: 1.5px solid #16323B;
        border-bottom: none;
    }
    .report-table tr.subtotal-row td {
        font-weight: bold;
        border-top: 1px solid #16323B;
    }
    .report-table tr.muted-row td {
        color: #2E6273;
        font-style: italic;
    }

    .empty-note { color: #2E6273; font-size: 9.5px; padding: 10px 0; }

    .footer {
        margin-top: 24px;
        padding-top: 10px;
        border-top: 1px solid #E4EEE6;
        font-size: 8px;
        color: #2E6273;
    }
</style>
</head>
<body>

<table class="header-table">
    <tr>
        <td style="width:55%;">
            <span class="company-name">{{ $company->name }}</span>
            <span class="company-tagline">{{ $company->base_currency }} · Accountant package</span>
        </td>
        <td style="width:45%;">
            <div class="doc-title">{{ $title }}</div>
            <div class="meta-block">{{ $periodLabel }}</div>
        </td>
    </tr>
</table>

<div class="rule"></div>

@yield('body')

<div class="footer">
    Generated {{ \Carbon\CarbonImmutable::now()->format('d M Y, H:i') }} — figures tie to the ledger (journal_lines), base currency {{ $company->base_currency }}.
</div>

</body>
</html>
