<?php

namespace App\Http\Controllers;

use App\Services\OpeningCsvParser;
use App\Support\AuditLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Downloadable opening-data CSV templates + the validating parser endpoint
 * that stages rows for the OpeningBalances wizard (spec §17.7). Both actions
 * sit behind the same `can:settings.manage` gate as the wizard itself
 * (routes/web.php) — this is not a general import surface, only the wizard's
 * own upload control talks to it.
 */
class OpeningCsvController extends Controller
{
    /**
     * A template CSV for the given kind: the exact header row the parser
     * expects, plus one worked example row. `{kind}` is validated against
     * the known template kinds; anything else 404s rather than guessing.
     */
    public function template(string $kind, OpeningCsvParser $parser): StreamedResponse
    {
        abort_unless(OpeningCsvParser::isValidKind($kind), 404);

        return response()->streamDownload(function () use ($parser, $kind) {
            $handle = fopen('php://output', 'w');
            fputcsv($handle, $parser->headerRow($kind));
            fputcsv($handle, $parser->exampleRow($kind));
            fclose($handle);
        }, "opening-{$kind}-template.csv", ['Content-Type' => 'text/csv']);
    }

    /**
     * Validates and parses an uploaded CSV for the given kind. This STAGES
     * rows for the wizard to review — it never posts to the ledger itself
     * (the wizard's existing submit does that via OpeningBalanceService,
     * only once the user confirms). All-or-nothing: a non-empty `errors`
     * array means `rows` is empty and nothing should be staged.
     */
    public function import(Request $request, string $kind, OpeningCsvParser $parser, AuditLogger $audit): JsonResponse
    {
        abort_unless(OpeningCsvParser::isValidKind($kind), 404);

        $validated = $request->validate([
            // mimes:csv,txt (rather than mimetypes:text/csv) because a
            // hand-edited or spreadsheet-exported CSV commonly reports a
            // MIME type of text/plain or application/vnd.ms-excel depending
            // on the OS/editor — mimes checks the extension against the
            // sniffed type's known extensions, which tolerates that.
            'file' => ['required', 'file', 'mimes:csv,txt', 'max:1024'],
        ]);

        $result = $parser->parse($kind, $validated['file']->get());

        $audit->log('opening_csv.imported', after: [
            'kind' => $kind,
            'row_count' => count($result['rows']),
            'error_count' => count($result['errors']),
        ]);

        return response()->json($result);
    }
}
