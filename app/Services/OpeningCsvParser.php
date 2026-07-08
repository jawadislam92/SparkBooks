<?php

namespace App\Services;

use App\Models\BankAccount;
use App\Models\Contact;
use App\Support\Money;

/**
 * Parses one of the opening-setup CSV templates (spec §17.7) into structured
 * rows the OpeningBalances wizard (Task 8) can stage for review, OR a
 * line-numbered error list. This is NOT a general import system — it exists
 * only to feed that wizard's existing bank_lines/customer_lines/vendor_lines
 * shapes, never to post anything itself (OpeningBalanceService does that,
 * from data the user has reviewed and explicitly submitted).
 *
 * All-or-nothing (spec §17.7): if ANY row in the file has an error, `rows`
 * comes back empty and `errors` carries every problem found, across every
 * row — never a partial success that could tempt a caller into staging some
 * rows and silently dropping others. Line numbers are 1-based with the
 * header counted as line 1, so the first data row is line 2.
 *
 * Money: every amount goes through Money::parseToMinor — bcmath-backed
 * integer-string arithmetic, never float — per the project's no-float money
 * rule (enforced separately by the CI grep in .github/workflows/ci.yml).
 *
 * Tenant scoping: Contact/BankAccount lookups here are plain Eloquent
 * queries. Both models use the BelongsToCompany trait, which adds a global
 * scope keyed off CompanyContext — so a name that exists only in another
 * company is indistinguishable from a name that doesn't exist at all, and is
 * rejected the same way. No explicit company_id filtering is needed here.
 */
class OpeningCsvParser
{
    public const CUSTOMERS = 'customers';

    public const VENDORS = 'vendors';

    public const OPEN_CUSTOMER_INVOICES = 'open_customer_invoices';

    public const OPEN_VENDOR_BILLS = 'open_vendor_bills';

    public const BANK_BALANCES = 'bank_balances';

    /** @var list<string> */
    public const KINDS = [
        self::CUSTOMERS,
        self::VENDORS,
        self::OPEN_CUSTOMER_INVOICES,
        self::OPEN_VENDOR_BILLS,
        self::BANK_BALANCES,
    ];

    /**
     * Canonical column order per kind — this IS the template header row.
     *
     * @var array<string, list<string>>
     */
    private const COLUMNS = [
        self::CUSTOMERS => ['name', 'email', 'phone', 'tax_number'],
        self::VENDORS => ['name', 'email', 'phone', 'tax_number'],
        self::OPEN_CUSTOMER_INVOICES => ['customer', 'reference', 'original_date', 'due_date', 'amount'],
        self::OPEN_VENDOR_BILLS => ['vendor', 'reference', 'original_date', 'due_date', 'amount'],
        self::BANK_BALANCES => ['account', 'amount'],
    ];

    /**
     * Columns that must carry a non-empty value on every data row.
     * (`reference` is the only optional column across all kinds.)
     *
     * @var array<string, list<string>>
     */
    private const REQUIRED_COLUMNS = [
        self::CUSTOMERS => ['name'],
        self::VENDORS => ['name'],
        self::OPEN_CUSTOMER_INVOICES => ['customer', 'original_date', 'due_date', 'amount'],
        self::OPEN_VENDOR_BILLS => ['vendor', 'original_date', 'due_date', 'amount'],
        self::BANK_BALANCES => ['account', 'amount'],
    ];

    /** One example row per kind, for the downloadable template.
     *
     * @var array<string, list<string>>
     */
    private const EXAMPLE_ROWS = [
        self::CUSTOMERS => ['Acme Traders', 'billing@acme.test', '0300-1234567', '1234567-8'],
        self::VENDORS => ['Beta Supplies', 'accounts@beta.test', '0300-7654321', '7654321-8'],
        self::OPEN_CUSTOMER_INVOICES => ['Acme Traders', 'INV-1001', '2026-05-01', '2026-06-01', '1500.50'],
        self::OPEN_VENDOR_BILLS => ['Beta Supplies', 'BILL-2001', '2026-05-15', '2026-06-15', '800.00'],
        self::BANK_BALANCES => ['Main Bank Account', '50000.00'],
    ];

    public static function isValidKind(string $kind): bool
    {
        return in_array($kind, self::KINDS, true);
    }

    /** @return list<string> */
    public function headerRow(string $kind): array
    {
        return self::COLUMNS[$kind];
    }

    /** @return list<string> */
    public function exampleRow(string $kind): array
    {
        return self::EXAMPLE_ROWS[$kind];
    }

    /**
     * @return array{rows: list<array<string, mixed>>, errors: list<array{line:int, message:string}>}
     */
    public function parse(string $kind, string $csvContent): array
    {
        $stream = fopen('php://temp', 'r+');
        fwrite($stream, $csvContent);
        rewind($stream);

        $header = fgetcsv($stream);

        if ($header === false || $header === null) {
            fclose($stream);

            return ['rows' => [], 'errors' => [['line' => 1, 'message' => 'The file is empty.']]];
        }

        $header = array_map(fn ($value) => strtolower(trim((string) $value)), $header);
        $missing = array_values(array_diff(self::REQUIRED_COLUMNS[$kind], $header));

        if ($missing !== []) {
            fclose($stream);

            return ['rows' => [], 'errors' => [[
                'line' => 1,
                'message' => 'The file is missing required column(s): '.implode(', ', $missing).'.',
            ]]];
        }

        $rows = [];
        $errors = [];
        $line = 1;

        while (($record = fgetcsv($stream)) !== false) {
            $line++;

            if ($this->isBlankRecord($record)) {
                continue;
            }

            $fields = [];
            foreach ($header as $index => $column) {
                $fields[$column] = trim((string) ($record[$index] ?? ''));
            }

            [$row, $rowErrors] = $this->parseRow($kind, $fields, $line);

            if ($rowErrors !== []) {
                array_push($errors, ...$rowErrors);

                continue;
            }

            $rows[] = $row;
        }

        fclose($stream);

        // All-or-nothing: any error anywhere rejects the whole file, so
        // nothing partial is ever handed back for the wizard to stage.
        if ($errors !== []) {
            return ['rows' => [], 'errors' => $errors];
        }

        return ['rows' => $rows, 'errors' => []];
    }

    /** fgetcsv returns [null] for a genuinely empty line — that's a blank
     * line in the file, not a data row missing its only column. */
    private function isBlankRecord(?array $record): bool
    {
        return $record === null || (count($record) === 1 && trim((string) ($record[0] ?? '')) === '');
    }

    /**
     * @param  array<string, string>  $fields
     * @return array{0: array<string, mixed>, 1: list<array{line:int, message:string}>}
     */
    private function parseRow(string $kind, array $fields, int $line): array
    {
        return match ($kind) {
            self::CUSTOMERS, self::VENDORS => $this->parseContactRow($fields, $line),
            self::OPEN_CUSTOMER_INVOICES => $this->parseDocumentRow($fields, $line, 'customer', fn (Contact $contact) => $contact->type->isCustomer(), 'contact_id'),
            self::OPEN_VENDOR_BILLS => $this->parseDocumentRow($fields, $line, 'vendor', fn (Contact $contact) => $contact->type->isVendor(), 'contact_id'),
            self::BANK_BALANCES => $this->parseBankBalanceRow($fields, $line),
            default => [[], [['line' => $line, 'message' => "Unknown template kind '{$kind}'."]]],
        };
    }

    /**
     * @param  array<string, string>  $fields
     * @return array{0: array<string, mixed>, 1: list<array{line:int, message:string}>}
     */
    private function parseContactRow(array $fields, int $line): array
    {
        $errors = [];
        $name = $fields['name'] ?? '';

        if ($name === '') {
            $errors[] = ['line' => $line, 'message' => "Row {$line}: name is required."];
        }

        $email = $fields['email'] ?? '';
        if ($email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            $errors[] = ['line' => $line, 'message' => "Row {$line}: email '{$email}' isn't valid."];
        }

        if ($errors !== []) {
            return [[], $errors];
        }

        return [[
            'name' => $name,
            'email' => $email === '' ? null : $email,
            'phone' => ($fields['phone'] ?? '') === '' ? null : $fields['phone'],
            'tax_number' => ($fields['tax_number'] ?? '') === '' ? null : $fields['tax_number'],
        ], []];
    }

    /**
     * Shared shape for open_customer_invoices / open_vendor_bills: a named
     * contact + reference + original/due dates + amount, resolved into a
     * customer_line/vendor_line the wizard already knows how to stage
     * (spec §17.7, P8b/P8c in OpeningBalanceService).
     *
     * @param  array<string, string>  $fields
     * @param  \Closure(Contact): bool  $typeMatches
     * @return array{0: array<string, mixed>, 1: list<array{line:int, message:string}>}
     */
    private function parseDocumentRow(array $fields, int $line, string $nameColumn, \Closure $typeMatches, string $idKey): array
    {
        $errors = [];
        $name = $fields[$nameColumn] ?? '';
        $noun = $nameColumn === 'customer' ? 'customer' : 'vendor';

        $contactId = null;
        if ($name === '') {
            $errors[] = ['line' => $line, 'message' => "Row {$line}: {$noun} is required."];
        } else {
            $contact = Contact::query()->whereRaw('LOWER(name) = ?', [strtolower($name)])->first();

            if ($contact === null || ! $typeMatches($contact)) {
                $errors[] = ['line' => $line, 'message' => "Row {$line}: no {$noun} named '{$name}' — create the contact first."];
            } elseif ($contact->is_archived) {
                $errors[] = ['line' => $line, 'message' => "Row {$line}: {$noun} '{$name}' is archived — unarchive it or use a different name."];
            } else {
                $contactId = $contact->id;
            }
        }

        $originalDate = $this->parseDate($fields['original_date'] ?? '', 'original_date', $line, $errors);
        $dueDate = $this->parseDate($fields['due_date'] ?? '', 'due_date', $line, $errors);

        if ($originalDate !== null && $dueDate !== null && $dueDate < $originalDate) {
            $errors[] = ['line' => $line, 'message' => "Row {$line}: due_date must be on or after original_date."];
        }

        $amount = $this->parseAmount($fields['amount'] ?? '', $line, $errors);

        if ($errors !== []) {
            return [[], $errors];
        }

        return [[
            $idKey => $contactId,
            'reference' => ($fields['reference'] ?? '') === '' ? null : $fields['reference'],
            'original_date' => $originalDate,
            'due_date' => $dueDate,
            'amount' => $amount,
        ], []];
    }

    /**
     * @param  array<string, string>  $fields
     * @return array{0: array<string, mixed>, 1: list<array{line:int, message:string}>}
     */
    private function parseBankBalanceRow(array $fields, int $line): array
    {
        $errors = [];
        $name = $fields['account'] ?? '';
        $bankAccountId = null;

        if ($name === '') {
            $errors[] = ['line' => $line, 'message' => "Row {$line}: account is required."];
        } else {
            $bankAccount = BankAccount::query()->whereRaw('LOWER(name) = ?', [strtolower($name)])->first();

            if ($bankAccount === null) {
                $errors[] = ['line' => $line, 'message' => "Row {$line}: no bank account named '{$name}' — create the account first."];
            } elseif ($bankAccount->is_archived) {
                $errors[] = ['line' => $line, 'message' => "Row {$line}: bank account '{$name}' is archived — unarchive it or use a different name."];
            } else {
                $bankAccountId = $bankAccount->id;
            }
        }

        $amount = $this->parseAmount($fields['amount'] ?? '', $line, $errors);

        if ($errors !== []) {
            return [[], $errors];
        }

        return [[
            'bank_account_id' => $bankAccountId,
            'amount' => $amount,
        ], []];
    }

    /**
     * Strict YYYY-MM-DD parse — round-trips the input back through the same
     * format and requires an exact match, so oddities like "2026-13-40" (which
     * PHP's DateTime would otherwise happily roll over into a nonsense date)
     * are rejected rather than silently normalized.
     *
     * @param  list<array{line:int, message:string}>  $errors
     */
    private function parseDate(string $value, string $field, int $line, array &$errors): ?string
    {
        if ($value === '') {
            $errors[] = ['line' => $line, 'message' => "Row {$line}: {$field} is required."];

            return null;
        }

        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $value);

        if ($date === false || $date->format('Y-m-d') !== $value) {
            $errors[] = ['line' => $line, 'message' => "Row {$line}: {$field} '{$value}' isn't a valid date (use YYYY-MM-DD)."];

            return null;
        }

        return $value;
    }

    /**
     * @param  list<array{line:int, message:string}>  $errors
     */
    private function parseAmount(string $value, int $line, array &$errors): ?int
    {
        if ($value === '') {
            $errors[] = ['line' => $line, 'message' => "Row {$line}: amount is required."];

            return null;
        }

        $minor = Money::parseToMinor($value);

        if ($minor === null) {
            $errors[] = ['line' => $line, 'message' => "Row {$line}: amount '{$value}' isn't a valid number (use up to 2 decimals)."];

            return null;
        }

        if ($minor <= 0) {
            $errors[] = ['line' => $line, 'message' => "Row {$line}: amount must be greater than zero."];

            return null;
        }

        return $minor;
    }
}
