<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
</head>
<body style="margin:0; padding:0; background-color:#f4f6f5; font-family: Arial, Helvetica, sans-serif;">
    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background-color:#f4f6f5; padding:24px 0;">
        <tr>
            <td align="center">
                <table role="presentation" width="560" cellpadding="0" cellspacing="0" style="background-color:#ffffff; border-radius:8px; overflow:hidden;">
                    <tr>
                        <td style="background-color:#16323B; padding:20px 32px;">
                            <span style="color:#ffffff; font-size:18px; font-weight:bold;">{{ $company->name }}</span>
                        </td>
                    </tr>
                    <tr>
                        <td style="padding:32px; color:#16323B; font-size:14px; line-height:1.6;">
                            <p>Hi {{ $contact->name }},</p>
                            <p>
                                Please find attached invoice
                                <strong>{{ $invoice->number }}</strong>
                                for
                                <strong>{{ \App\Services\InvoicePdf::formatMoney($invoice->total, $invoice->currency_code) }}</strong>,
                                @if ($invoice->due_date)
                                    due {{ $invoice->due_date->toFormattedDateString() }}.
                                @else
                                    due on receipt.
                                @endif
                            </p>
                            <p>Thank you for your business.</p>
                            <p style="margin-top:24px; color:#0FA678; font-weight:bold;">{{ $company->name }}</p>
                        </td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>
</body>
</html>
