<?php

namespace App\Support;

use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Shared streamed-CSV response builder for every LIST-screen export endpoint
 * added in Phase 6 Task 3 (spec §11) — invoices/bills/expenses/contacts/
 * items/bank-accounts/journal-entries. Money columns must already be
 * formatted by the caller via Money::minorToDecimalString (integer math
 * only, mirrors every Reports/*Controller::exportCsv — this class only
 * frames the CSV, it doesn't touch money math itself).
 *
 * Bakes the §17.8 export-access-logging requirement into the SAME call that
 * produces the response: `$audit` is a REQUIRED argument, logged BEFORE the
 * streamed response is built, so a caller cannot obtain a CsvExport::stream()
 * response without an audit row also being written — the anti-exfiltration
 * trail can't be skipped by omission the way a bare
 * response()->streamDownload() call could be. The Phase 5 report exports
 * predate this helper and already log explicitly inline (see
 * TrialBalanceController::exportCsv and its siblings) — this class doesn't
 * change them (no behavior regression risk), it's the completeness guarantee
 * for every NEW export endpoint from here on.
 */
class CsvExport
{
    /**
     * @param  list<string>  $headers
     * @param  iterable<int, list<scalar|null>>  $rows
     * @param  array<string, mixed>  $detail  merged into the audit row's `after` — the resource, the filter/period applied, and anything else worth recording; who/when are automatic (AuditLogger writes user_id + created_at).
     */
    public static function stream(
        string $filename,
        array $headers,
        iterable $rows,
        AuditLogger $audit,
        string $action,
        array $detail = [],
    ): StreamedResponse {
        $audit->log($action, after: $detail);

        return response()->streamDownload(function () use ($headers, $rows) {
            $handle = fopen('php://output', 'w');

            fputcsv($handle, $headers);

            foreach ($rows as $row) {
                fputcsv($handle, $row);
            }

            fclose($handle);
        }, $filename, ['Content-Type' => 'text/csv']);
    }
}
