<?php

namespace Tests\Feature;

use App\Enums\AccountSubtype;
use App\Enums\AccountType;
use App\Enums\ContactType;
use App\Enums\InvoiceStatus;
use App\Enums\Role;
use App\Models\Account;
use App\Models\ActivityLog;
use App\Models\BankAccount;
use App\Models\Company;
use App\Models\Contact;
use App\Models\Invoice;
use App\Models\JournalEntry;
use App\Models\JournalLine;
use App\Models\Reconciliation;
use App\Models\ReconciliationLine;
use App\Models\Transfer;
use App\Models\User;
use App\Support\CompanyContext;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia;
use Tests\Support\AssertsLedgerBalanced;
use Tests\TestCase;

/**
 * Bank reconciliation (spec §7.9, Phase 6 Task 1). CRITICAL: reconciliation
 * never writes a ledger entry — it only records which already-posted
 * journal_lines on a bank account's linked ledger account cleared a
 * statement. Every test that creates a "bank line" to reconcile does so via
 * a REAL posting flow (Transfer between two bank accounts, or an FX invoice
 * payment for the §17.1 test) — journal_lines has no factory of its own in
 * this codebase (see LedgerService::post as the sole writer), same
 * convention as InvoiceFxPaymentTest/TransfersTest.
 *
 * The two load-bearing invariants: complete() refuses unless the live
 * difference is exactly 0 (nothing stored otherwise), and reopen() only ever
 * allows the account's newest completed reconciliation to be reopened (so
 * opening_reconciled_balance stays a consistent chain).
 */
class ReconciliationTest extends TestCase
{
    use AssertsLedgerBalanced;
    use RefreshDatabase;

    private function actingAsRole(Company $company, Role $role): User
    {
        $user = User::factory()->create();
        $user->companies()->attach($company, ['role' => $role->value]);
        $user->forceFill(['current_company_id' => $company->id])->save();
        $this->actingAs($user);

        return $user;
    }

    /** @param array<string, mixed> $overrides */
    private function bankAccount(Company $company, array $overrides = []): BankAccount
    {
        return app(CompanyContext::class)->runFor($company, fn () => BankAccount::factory()->create(array_merge([
            'company_id' => $company->id,
        ], $overrides)));
    }

    private function postTransfer(BankAccount $from, BankAccount $to, string $date, int $amount, ?string $key = null): TestResponse
    {
        return $this->postJson('/transfers', [
            'from_bank_account_id' => $from->id,
            'to_bank_account_id' => $to->id,
            'transfer_date' => $date,
            'amount' => $amount,
            'memo' => 'Test transfer',
        ], ['X-Idempotency-Key' => $key ?? (string) Str::uuid()]);
    }

    /**
     * Posts a real transfer $from -> $into (Dr $into / Cr $from) and returns
     * the journal_line id on $into's ledger account — a positive/debit
     * "deposit" line to reconcile.
     */
    private function depositLine(BankAccount $into, BankAccount $from, string $date, int $amount): int
    {
        $this->postTransfer($from, $into, $date, $amount)->assertRedirect();

        $transfer = Transfer::query()->latest('id')->first();
        $entry = JournalEntry::query()
            ->where('source_type', 'transfer')->where('source_id', $transfer->id)
            ->sole();

        return $entry->lines->firstWhere('account_id', $into->ledger_account_id)->id;
    }

    /**
     * Posts a real transfer $from -> $to (Dr $to / Cr $from) and returns the
     * journal_line id on $from's ledger account — a negative/credit
     * "withdrawal" line.
     */
    private function withdrawalLine(BankAccount $from, BankAccount $to, string $date, int $amount): int
    {
        $this->postTransfer($from, $to, $date, $amount)->assertRedirect();

        $transfer = Transfer::query()->latest('id')->first();
        $entry = JournalEntry::query()
            ->where('source_type', 'transfer')->where('source_id', $transfer->id)
            ->sole();

        return $entry->lines->firstWhere('account_id', $from->ledger_account_id)->id;
    }

    /** @param list<int> $tickedIds */
    private function complete(BankAccount $account, string $statementDate, int $statementEndingBalance, array $tickedIds, ?string $key = null): TestResponse
    {
        return $this->postJson("/reconciliations/{$account->id}/complete", [
            'statement_date' => $statementDate,
            'statement_ending_balance' => $statementEndingBalance,
            'ticked_journal_line_ids' => $tickedIds,
        ], ['X-Idempotency-Key' => $key ?? (string) Str::uuid()]);
    }

    private function reopen(Reconciliation $reconciliation, ?string $key = null): TestResponse
    {
        return $this->postJson("/reconciliations/{$reconciliation->id}/reopen", [], [
            'X-Idempotency-Key' => $key ?? (string) Str::uuid(),
        ]);
    }

    /**
     * Owner-owned company with one bank account reconciled twice
     * (statement 1: 2026-07-01 ending 50,000; statement 2: 2026-07-15 ending
     * 70,000, chaining off the first) — shared setup for the reopen
     * newest-first tests.
     *
     * @return array{0: BankAccount, 1: Reconciliation, 2: Reconciliation}
     */
    private function twoCompletedReconciliations(): array
    {
        $company = Company::factory()->create();
        $this->actingAsRole($company, Role::Owner);
        $account = $this->bankAccount($company);
        $other = $this->bankAccount($company);

        $line1 = $this->depositLine($account, $other, '2026-07-01', 50000);
        $this->complete($account, '2026-07-01', 50000, [$line1])->assertRedirect();
        $rec1 = Reconciliation::query()->sole();

        $line2 = $this->depositLine($account, $other, '2026-07-15', 20000);
        $this->complete($account, '2026-07-15', 70000, [$line2])->assertRedirect();
        $rec2 = Reconciliation::query()->where('id', '!=', $rec1->id)->sole();

        return [$account, $rec1, $rec2];
    }

    // -----------------------------------------------------------------
    // The difference == 0 gate
    // -----------------------------------------------------------------

    public function test_complete_at_difference_zero_stores_the_reconciliation_and_marks_the_line_reconciled(): void
    {
        $company = Company::factory()->create();
        $this->actingAsRole($company, Role::Owner);
        $account = $this->bankAccount($company);
        $other = $this->bankAccount($company);
        $lineId = $this->depositLine($account, $other, '2026-07-01', 50000);

        $response = $this->complete($account, '2026-07-01', 50000, [$lineId]);
        $response->assertRedirect(route('reconciliations.show', $account->id));

        $reconciliation = Reconciliation::query()->sole();
        $this->assertSame($account->id, $reconciliation->bank_account_id);
        $this->assertSame('2026-07-01', $reconciliation->statement_date->toDateString());
        $this->assertSame(50000, $reconciliation->statement_ending_balance);
        $this->assertSame(0, $reconciliation->opening_reconciled_balance);
        $this->assertSame('completed', $reconciliation->status);
        $this->assertNotNull($reconciliation->completed_by);
        $this->assertNotNull($reconciliation->completed_at);

        $reconciliationLine = ReconciliationLine::query()->sole();
        $this->assertSame($reconciliation->id, $reconciliationLine->reconciliation_id);
        $this->assertSame($lineId, $reconciliationLine->journal_line_id);
        $this->assertSame(50000, $reconciliationLine->amount);

        $this->assertLedgerBalances();
    }

    public function test_complete_at_a_nonzero_difference_is_refused_and_stores_nothing(): void
    {
        $company = Company::factory()->create();
        $this->actingAsRole($company, Role::Owner);
        $account = $this->bankAccount($company);
        $other = $this->bankAccount($company);
        $lineId = $this->depositLine($account, $other, '2026-07-01', 50000);

        $response = $this->complete($account, '2026-07-01', 49000, [$lineId]);

        $response->assertSessionHasErrors('status');
        $this->assertStringContainsString(
            "doesn't balance",
            session('errors')->first('status')
        );
        $this->assertSame(0, Reconciliation::query()->count());
        $this->assertSame(0, ReconciliationLine::query()->count());
        $this->assertLedgerBalances();
    }

    // -----------------------------------------------------------------
    // Ticked-line eligibility
    // -----------------------------------------------------------------

    public function test_a_ticked_line_not_belonging_to_the_account_is_rejected(): void
    {
        $company = Company::factory()->create();
        $this->actingAsRole($company, Role::Owner);
        $account = $this->bankAccount($company);
        $unrelated = $this->bankAccount($company);
        $sideAccount = $this->bankAccount($company);
        $foreignLineId = $this->depositLine($unrelated, $sideAccount, '2026-07-01', 30000);

        $response = $this->complete($account, '2026-07-01', 30000, [$foreignLineId]);

        $response->assertSessionHasErrors('status');
        $this->assertStringContainsString(
            "doesn't belong",
            session('errors')->first('status')
        );
        $this->assertSame(0, Reconciliation::query()->count());
        $this->assertLedgerBalances();
    }

    public function test_a_ticked_line_dated_after_the_statement_date_is_rejected(): void
    {
        $company = Company::factory()->create();
        $this->actingAsRole($company, Role::Owner);
        $account = $this->bankAccount($company);
        $other = $this->bankAccount($company);
        $lineId = $this->depositLine($account, $other, '2026-07-10', 20000);

        $response = $this->complete($account, '2026-07-05', 20000, [$lineId]);

        $response->assertSessionHasErrors('status');
        $this->assertStringContainsString(
            'dated after',
            session('errors')->first('status')
        );
        $this->assertSame(0, Reconciliation::query()->count());
        $this->assertLedgerBalances();
    }

    public function test_a_ticked_line_already_reconciled_is_rejected(): void
    {
        $company = Company::factory()->create();
        $this->actingAsRole($company, Role::Owner);
        $account = $this->bankAccount($company);
        $other = $this->bankAccount($company);
        $lineId = $this->depositLine($account, $other, '2026-07-01', 50000);

        $this->complete($account, '2026-07-01', 50000, [$lineId])->assertRedirect();

        // Attempting to reuse the SAME line in a second, brand-new
        // reconciliation must be rejected — regardless of what the new
        // statement's own numbers would otherwise say.
        $response = $this->complete($account, '2026-07-15', 50000, [$lineId]);

        $response->assertSessionHasErrors('status');
        $this->assertStringContainsString(
            'already been reconciled',
            session('errors')->first('status')
        );
        $this->assertSame(1, Reconciliation::query()->count());
        $this->assertSame(1, ReconciliationLine::query()->count());
        $this->assertLedgerBalances();
    }

    /**
     * Defense-in-depth at the database layer: the (company_id,
     * journal_line_id) unique index on reconciliation_lines is the ACTUAL
     * mechanism that makes double-reconciling a line unreachable, even if a
     * future caller ever bypassed ReconciliationService's own eligibility
     * guard above.
     */
    public function test_a_line_cannot_be_reconciled_by_two_reconciliations_at_the_database_level(): void
    {
        $company = Company::factory()->create();
        $this->actingAsRole($company, Role::Owner);
        $account = $this->bankAccount($company);
        $other = $this->bankAccount($company);
        $lineId = $this->depositLine($account, $other, '2026-07-01', 10000);

        $rec1 = Reconciliation::factory()->create(['bank_account_id' => $account->id]);
        $rec2 = Reconciliation::factory()->create(['bank_account_id' => $account->id]);

        ReconciliationLine::query()->create([
            'reconciliation_id' => $rec1->id,
            'journal_line_id' => $lineId,
            'amount' => 10000,
        ]);

        $this->expectException(UniqueConstraintViolationException::class);

        ReconciliationLine::query()->create([
            'reconciliation_id' => $rec2->id,
            'journal_line_id' => $lineId,
            'amount' => 10000,
        ]);
    }

    // -----------------------------------------------------------------
    // opening_reconciled_balance chain
    // -----------------------------------------------------------------

    public function test_opening_reconciled_balance_chains_from_the_prior_reconciliation(): void
    {
        [$account, $rec1, $rec2] = $this->twoCompletedReconciliations();

        $this->assertSame(0, $rec1->opening_reconciled_balance);
        $this->assertSame(50000, $rec1->statement_ending_balance);

        $this->assertSame(50000, $rec2->opening_reconciled_balance);
        $this->assertSame(70000, $rec2->statement_ending_balance);
        $this->assertLedgerBalances();
    }

    // -----------------------------------------------------------------
    // Reopen: newest-first only
    // -----------------------------------------------------------------

    public function test_reopening_a_non_newest_reconciliation_is_refused_and_names_the_newer_one(): void
    {
        [$account, $rec1, $rec2] = $this->twoCompletedReconciliations();

        $response = $this->reopen($rec1);

        $response->assertSessionHasErrors('status');
        $this->assertStringContainsString('2026-07-15', session('errors')->first('status'));

        $rec1->refresh();
        $this->assertSame('completed', $rec1->status);
        $this->assertTrue(ReconciliationLine::query()->where('reconciliation_id', $rec1->id)->exists());
    }

    public function test_reopening_the_newest_reconciliation_unlocks_its_lines(): void
    {
        [$account, $rec1, $rec2] = $this->twoCompletedReconciliations();
        $line2Id = ReconciliationLine::query()->where('reconciliation_id', $rec2->id)->sole()->journal_line_id;

        $response = $this->reopen($rec2);
        $response->assertRedirect(route('reconciliations.show', $account->id));

        $rec2->refresh();
        $this->assertSame('reopened', $rec2->status);
        $this->assertSame(0, ReconciliationLine::query()->where('reconciliation_id', $rec2->id)->count());
        $this->assertFalse(ReconciliationLine::query()->where('journal_line_id', $line2Id)->exists());

        // rec1 (now the newest completed one) is untouched.
        $rec1->refresh();
        $this->assertSame('completed', $rec1->status);

        $audit = ActivityLog::query()->where('action', 'reconciliation.reopened')->sole();
        $this->assertSame($rec2->id, $audit->subject_id);
        $this->assertSame(['status' => 'completed'], $audit->before);
        $this->assertSame('reopened', $audit->after['status']);

        $this->assertLedgerBalances();
    }

    public function test_reopening_an_already_reopened_reconciliation_with_a_new_key_is_refused(): void
    {
        $company = Company::factory()->create();
        $this->actingAsRole($company, Role::Owner);
        $account = $this->bankAccount($company);
        $other = $this->bankAccount($company);
        $lineId = $this->depositLine($account, $other, '2026-07-01', 50000);
        $this->complete($account, '2026-07-01', 50000, [$lineId])->assertRedirect();
        $reconciliation = Reconciliation::query()->sole();

        $this->reopen($reconciliation)->assertRedirect();

        // A second reopen call under a DIFFERENT idempotency key (not a
        // replay of the first) has nothing left to unlock.
        $response = $this->reopen($reconciliation);

        $response->assertSessionHasErrors('status');
        $this->assertStringContainsString('already been reopened', session('errors')->first('status'));
    }

    // -----------------------------------------------------------------
    // Idempotency
    // -----------------------------------------------------------------

    public function test_idempotent_complete_replay_creates_exactly_one_reconciliation(): void
    {
        $company = Company::factory()->create();
        $this->actingAsRole($company, Role::Owner);
        $account = $this->bankAccount($company);
        $other = $this->bankAccount($company);
        $lineId = $this->depositLine($account, $other, '2026-07-01', 50000);
        $key = (string) Str::uuid();

        $this->complete($account, '2026-07-01', 50000, [$lineId], $key)->assertRedirect();
        $this->complete($account, '2026-07-01', 50000, [$lineId], $key)->assertRedirect();

        $this->assertSame(1, Reconciliation::query()->count());
        $this->assertSame(1, ReconciliationLine::query()->count());
        $this->assertSame(1, ActivityLog::query()->where('action', 'reconciliation.completed')->count());
        $this->assertLedgerBalances();
    }

    public function test_idempotent_reopen_replay_reopens_exactly_once(): void
    {
        $company = Company::factory()->create();
        $this->actingAsRole($company, Role::Owner);
        $account = $this->bankAccount($company);
        $other = $this->bankAccount($company);
        $lineId = $this->depositLine($account, $other, '2026-07-01', 50000);
        $this->complete($account, '2026-07-01', 50000, [$lineId])->assertRedirect();
        $reconciliation = Reconciliation::query()->sole();
        $key = (string) Str::uuid();

        $this->reopen($reconciliation, $key)->assertRedirect();
        $this->reopen($reconciliation, $key)->assertRedirect();

        $reconciliation->refresh();
        $this->assertSame('reopened', $reconciliation->status);
        $this->assertSame(1, ActivityLog::query()->where('action', 'reconciliation.reopened')->count());
    }

    // -----------------------------------------------------------------
    // Gate: reconciliation.manage (Owner/Accountant only)
    // -----------------------------------------------------------------

    public function test_owner_and_accountant_can_complete_and_reopen(): void
    {
        foreach ([Role::Owner, Role::Accountant] as $role) {
            $company = Company::factory()->create();
            $this->actingAsRole($company, $role);
            $account = $this->bankAccount($company);
            $other = $this->bankAccount($company);
            $lineId = $this->depositLine($account, $other, '2026-07-01', 50000);

            $this->complete($account, '2026-07-01', 50000, [$lineId])->assertRedirect();
            $reconciliation = Reconciliation::query()->where('bank_account_id', $account->id)->sole();

            $this->reopen($reconciliation)->assertRedirect();
            $reconciliation->refresh();
            $this->assertSame('reopened', $reconciliation->status);
        }
    }

    public function test_staff_and_admin_lack_reconciliation_manage(): void
    {
        $company = Company::factory()->create();
        $this->actingAsRole($company, Role::Owner);
        $account = $this->bankAccount($company);
        $other = $this->bankAccount($company);
        $lineId = $this->depositLine($account, $other, '2026-07-01', 50000);
        $this->complete($account, '2026-07-01', 50000, [$lineId])->assertRedirect();
        $reconciliation = Reconciliation::query()->sole();

        foreach ([Role::Admin, Role::Staff] as $role) {
            $this->actingAsRole($company, $role);

            $this->get('/reconciliations')->assertForbidden();
            $this->get("/reconciliations/{$account->id}")->assertForbidden();
            $this->complete($account, '2026-07-20', 0, [])->assertForbidden();
            $this->reopen($reconciliation)->assertForbidden();
        }

        $reconciliation->refresh();
        $this->assertSame('completed', $reconciliation->status);
    }

    // -----------------------------------------------------------------
    // Scoped 404
    // -----------------------------------------------------------------

    public function test_a_foreign_companys_bank_account_404s_on_show_and_complete(): void
    {
        $companyA = Company::factory()->create();
        $foreignAccount = $this->bankAccount($companyA);

        $companyB = Company::factory()->create();
        $this->actingAsRole($companyB, Role::Owner);

        $this->get("/reconciliations/{$foreignAccount->id}")->assertNotFound();
        $this->complete($foreignAccount, '2026-07-01', 0, [])->assertNotFound();
        $this->assertSame(0, Reconciliation::query()->count());
    }

    public function test_a_foreign_companys_reconciliation_404s_on_reopen(): void
    {
        $companyA = Company::factory()->create();
        $this->actingAsRole($companyA, Role::Owner);
        $account = $this->bankAccount($companyA);
        $other = $this->bankAccount($companyA);
        $lineId = $this->depositLine($account, $other, '2026-07-01', 50000);
        $this->complete($account, '2026-07-01', 50000, [$lineId])->assertRedirect();
        $foreignReconciliation = Reconciliation::query()->sole();

        $companyB = Company::factory()->create();
        $this->actingAsRole($companyB, Role::Owner);

        $this->reopen($foreignReconciliation)->assertNotFound();

        $foreignReconciliation->refresh();
        $this->assertSame('completed', $foreignReconciliation->status);
    }

    // -----------------------------------------------------------------
    // No ledger writes
    // -----------------------------------------------------------------

    public function test_no_journal_entries_or_lines_are_created_by_complete_or_reopen(): void
    {
        $company = Company::factory()->create();
        $this->actingAsRole($company, Role::Owner);
        $account = $this->bankAccount($company);
        $other = $this->bankAccount($company);
        $lineId = $this->depositLine($account, $other, '2026-07-01', 50000);

        $entriesBefore = JournalEntry::query()->count();
        $linesBefore = JournalLine::query()->count();

        $this->complete($account, '2026-07-01', 50000, [$lineId])->assertRedirect();
        $reconciliation = Reconciliation::query()->sole();
        $this->reopen($reconciliation)->assertRedirect();

        $this->assertSame($entriesBefore, JournalEntry::query()->count());
        $this->assertSame($linesBefore, JournalLine::query()->count());
    }

    // -----------------------------------------------------------------
    // §17.1 FX: a P2fx payment posts the ACTUAL base amount received to the
    // bank ledger, so reconciling that line completes at difference 0.
    // -----------------------------------------------------------------

    /** @param array<string, mixed> $overrides */
    private function customer(Company $company, array $overrides = []): Contact
    {
        return Contact::factory()->create(array_merge([
            'company_id' => $company->id,
            'type' => ContactType::Customer->value,
        ], $overrides));
    }

    private function arAccount(Company $company): Account
    {
        return Account::factory()->create([
            'company_id' => $company->id,
            'code' => '1100',
            'name' => 'Accounts Receivable',
            'type' => AccountType::Asset->value,
            'subtype' => AccountSubtype::Receivable->value,
            'is_system' => true,
        ]);
    }

    private function fxGainAccount(Company $company): Account
    {
        return Account::factory()->create([
            'company_id' => $company->id,
            'code' => '4950',
            'name' => 'FX Gain',
            'type' => AccountType::Income->value,
            'subtype' => AccountSubtype::OtherIncome->value,
            'is_system' => true,
        ]);
    }

    private function fxLossAccount(Company $company): Account
    {
        return Account::factory()->create([
            'company_id' => $company->id,
            'code' => '5950',
            'name' => 'FX Loss',
            'type' => AccountType::Expense->value,
            'subtype' => AccountSubtype::OperatingExpense->value,
            'is_system' => true,
        ]);
    }

    /** @param array<string, mixed> $overrides */
    private function sentFxInvoice(Company $company, Contact $contact, int $total, string $fxRate, int $baseTotal, array $overrides = []): Invoice
    {
        return Invoice::factory()->create(array_merge([
            'company_id' => $company->id,
            'contact_id' => $contact->id,
            'status' => InvoiceStatus::Sent->value,
            'number' => 'INV-'.fake()->unique()->numerify('####'),
            'issue_date' => '2026-07-01',
            'currency_code' => 'USD',
            'fx_rate' => $fxRate,
            'subtotal' => $total,
            'tax_total' => 0,
            'total' => $total,
            'amount_paid' => 0,
            'balance' => $total,
            'base_subtotal' => $baseTotal,
            'base_tax_total' => 0,
            'base_total' => $baseTotal,
        ], $overrides));
    }

    /** @param array<string, mixed> $data */
    private function pay(Invoice $invoice, array $data, ?string $key = null): TestResponse
    {
        return $this->postJson("/invoices/{$invoice->id}/payments", $data, [
            'X-Idempotency-Key' => $key ?? (string) Str::uuid(),
        ]);
    }

    public function test_an_fx_invoice_payment_line_reconciles_at_difference_zero(): void
    {
        $company = Company::factory()->create(); // base_currency PKR
        $this->actingAsRole($company, Role::Owner);
        $contact = $this->customer($company);
        $this->arAccount($company);
        $this->fxGainAccount($company);
        $this->fxLossAccount($company);
        $bank = $this->bankAccount($company);

        // USD 1,000 @ 280 (base_total 28,000,000), paid in full. The bank
        // credits the ACTUAL base amount (28,500,000, spec §17.1 P2fx) — not
        // amount * rate — which is exactly what a real bank statement would
        // show, so a reconciliation ticking this line should hit 0 exactly.
        $invoice = $this->sentFxInvoice($company, $contact, 100000, '280', 28000000);

        $this->pay($invoice, [
            'amount' => 100000,
            'base_amount' => 28500000,
            'bank_account_id' => $bank->id,
            'payment_date' => '2026-07-15',
        ])->assertRedirect();

        $entry = JournalEntry::query()->where('source_type', 'invoice_payment')->sole();
        $bankLine = $entry->lines->firstWhere('account_id', $bank->ledger_account_id);
        $this->assertNotNull($bankLine);
        $this->assertSame(28500000, $bankLine->debit);

        $response = $this->complete($bank, '2026-07-15', 28500000, [$bankLine->id]);
        $response->assertRedirect(route('reconciliations.show', $bank->id));

        $reconciliation = Reconciliation::query()->sole();
        $this->assertSame(28500000, $reconciliation->statement_ending_balance);
        $this->assertSame('completed', $reconciliation->status);

        $reconciliationLine = ReconciliationLine::query()->sole();
        $this->assertSame($bankLine->id, $reconciliationLine->journal_line_id);
        $this->assertSame(28500000, $reconciliationLine->amount);

        $this->assertLedgerBalances();
    }

    // -----------------------------------------------------------------
    // Routes: Index / Show render the expected Inertia pages
    // -----------------------------------------------------------------

    public function test_index_lists_bank_accounts(): void
    {
        $company = Company::factory()->create();
        $this->actingAsRole($company, Role::Owner);
        $account = $this->bankAccount($company, ['name' => 'Meezan Current']);

        $response = $this->get('/reconciliations');

        $response->assertOk();
        $response->assertInertia(fn (AssertableInertia $page) => $page
            ->component('Reconciliation/Index')
            ->where('bankAccounts', fn ($accounts) => collect($accounts)->pluck('id')->contains($account->id))
        );
    }

    public function test_show_renders_history_and_a_preview_when_statement_params_are_given(): void
    {
        $company = Company::factory()->create();
        $this->actingAsRole($company, Role::Owner);
        $account = $this->bankAccount($company);
        $other = $this->bankAccount($company);
        $lineId = $this->depositLine($account, $other, '2026-07-01', 50000);

        $response = $this->get("/reconciliations/{$account->id}?statement_date=2026-07-01&statement_ending_balance=50000");

        $response->assertOk();
        $response->assertInertia(fn (AssertableInertia $page) => $page
            ->component('Reconciliation/Show')
            ->where('preview.opening_reconciled_balance', 0)
            ->where('preview.lines.0.id', $lineId)
            ->where('preview.lines.0.amount', 50000)
        );
    }

    public function test_show_marks_only_the_newest_completed_reconciliation_as_reopenable(): void
    {
        [$account, $rec1, $rec2] = $this->twoCompletedReconciliations();

        $response = $this->get("/reconciliations/{$account->id}");

        $response->assertInertia(fn (AssertableInertia $page) => $page
            ->component('Reconciliation/Show')
            ->where('history', function ($history) use ($rec1, $rec2) {
                $byId = collect($history)->keyBy('id');

                return $byId[$rec2->id]['can_reopen'] === true
                    && $byId[$rec1->id]['can_reopen'] === false;
            })
        );
    }
}
