<?php

use App\Http\Controllers\AccountController;
use App\Http\Controllers\BankAccountController;
use App\Http\Controllers\BillController;
use App\Http\Controllers\CompanySwitchController;
use App\Http\Controllers\ContactController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\ExpenseController;
use App\Http\Controllers\HealthController;
use App\Http\Controllers\InvoiceController;
use App\Http\Controllers\ItemController;
use App\Http\Controllers\ManualJournalController;
use App\Http\Controllers\OnboardingController;
use App\Http\Controllers\OpeningBalanceController;
use App\Http\Controllers\OpeningCsvController;
use App\Http\Controllers\OtherReceiptController;
use App\Http\Controllers\PeriodLockController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\Reports\TrialBalanceController;
use App\Http\Controllers\TaxRateController;
use App\Http\Controllers\TransferController;
use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

Route::get('/', function () {
    return Inertia::render('Welcome', [
        'canLogin' => Route::has('login'),
        'canRegister' => Route::has('register'),
        'laravelVersion' => Application::VERSION,
        'phpVersion' => PHP_VERSION,
    ]);
});

Route::get('/dashboard', [DashboardController::class, 'show'])
    ->middleware(['auth', 'verified', 'company'])
    ->name('dashboard');

Route::middleware('auth')->group(function () {
    Route::get('/onboarding/company', [OnboardingController::class, 'create'])->name('onboarding.company');
    Route::post('/onboarding/company', [OnboardingController::class, 'store']);

    Route::get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('/profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::delete('/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');
    Route::post('/company/switch', CompanySwitchController::class)->name('company.switch');
});

Route::middleware(['auth', 'verified', 'company', 'can:ledger.post_manual'])->group(function () {
    Route::get('/journal-entries', [ManualJournalController::class, 'index'])->name('journal-entries.index');
    Route::get('/journal-entries/new', [ManualJournalController::class, 'create'])->name('journal-entries.new');
    Route::post('/journal-entries', [ManualJournalController::class, 'store'])->name('journal-entries.store');
    Route::post('/journal-entries/{manualJournal}/reverse', [ManualJournalController::class, 'reverse'])->name('journal-entries.reverse');
});

Route::middleware(['auth', 'verified', 'company'])->group(function () {
    // GET is gated inside the controller (periods.lock OR reports.view — a
    // broader "may view" check than a single `can:` middleware ability
    // expresses); POST is narrowed to periods.lock only.
    Route::get('/settings/books', [PeriodLockController::class, 'edit'])->name('settings.books');
    Route::post('/settings/books', [PeriodLockController::class, 'update'])
        ->middleware('can:periods.lock')
        ->name('settings.books.update');
});

Route::middleware(['auth', 'verified', 'company', 'can:reports.view'])->group(function () {
    Route::get('/reports/trial-balance', [TrialBalanceController::class, 'show'])->name('reports.trial-balance');
    // Export is stricter than view: reports.view (group) AND exports.run (here).
    Route::get('/reports/trial-balance/export.csv', [TrialBalanceController::class, 'exportCsv'])
        ->middleware('can:exports.run')
        ->name('reports.trial-balance.export');
});

Route::middleware(['auth', 'verified', 'company', 'can:audit.view'])->group(function () {
    Route::get('/settings/health', [HealthController::class, 'show'])->name('settings.health');
    Route::post('/settings/health/run', [HealthController::class, 'run'])->name('settings.health.run');
});

Route::middleware(['auth', 'verified', 'company', 'can:accounts.manage'])->group(function () {
    Route::get('/accounts', [AccountController::class, 'index'])->name('accounts.index');
    Route::post('/accounts', [AccountController::class, 'store'])->name('accounts.store');
    Route::patch('/accounts/{account}', [AccountController::class, 'update'])->name('accounts.update');
    Route::post('/accounts/{account}/archive', [AccountController::class, 'archive'])->name('accounts.archive');
    Route::post('/accounts/{account}/unarchive', [AccountController::class, 'unarchive'])->name('accounts.unarchive');
    Route::get('/accounts/{account}', [AccountController::class, 'show'])->name('accounts.show');
});

Route::middleware(['auth', 'verified', 'company', 'can:contacts.manage'])->group(function () {
    Route::get('/contacts', [ContactController::class, 'index'])->name('contacts.index');
    Route::post('/contacts', [ContactController::class, 'store'])->name('contacts.store');
    Route::patch('/contacts/{contact}', [ContactController::class, 'update'])->name('contacts.update');
    Route::post('/contacts/{contact}/archive', [ContactController::class, 'archive'])->name('contacts.archive');
    Route::post('/contacts/{contact}/unarchive', [ContactController::class, 'unarchive'])->name('contacts.unarchive');
    Route::get('/contacts/{contact}', [ContactController::class, 'show'])->name('contacts.show');
});

Route::middleware(['auth', 'verified', 'company', 'can:invoices.draft'])->group(function () {
    Route::get('/invoices', [InvoiceController::class, 'index'])->name('invoices.index');
    Route::get('/invoices/new', [InvoiceController::class, 'create'])->name('invoices.new');
    Route::post('/invoices', [InvoiceController::class, 'store'])->name('invoices.store');
    // Registered BEFORE the /edit route below on purpose: both are GETs under
    // /invoices/{invoice}/..., and Laravel matches route definitions in
    // registration order — {invoice} alone would otherwise never lose to a
    // more specific suffix if it were registered first (it isn't a real
    // conflict here since /edit has an extra path segment, but this keeps the
    // "wildcard's own root path last" convention obvious at a glance).
    Route::get('/invoices/{invoice}', [InvoiceController::class, 'show'])->name('invoices.show');
    Route::get('/invoices/{invoice}/edit', [InvoiceController::class, 'edit'])->name('invoices.edit');
    Route::patch('/invoices/{invoice}', [InvoiceController::class, 'update'])->name('invoices.update');
    Route::delete('/invoices/{invoice}', [InvoiceController::class, 'destroy'])->name('invoices.destroy');
    Route::get('/invoices/{invoice}/pdf', [InvoiceController::class, 'pdf'])->name('invoices.pdf');
});

// invoices.send is a narrower gate than invoices.draft (Staff can draft but
// not send, see config/permissions.php §7.14), so it needs its own group.
// Emailing (spec §17.6) shares this gate: the person who sends invoices
// emails them too — there is no separate email permission in the catalog.
Route::middleware(['auth', 'verified', 'company', 'can:invoices.send'])->group(function () {
    Route::post('/invoices/{invoice}/send', [InvoiceController::class, 'send'])->name('invoices.send');
    Route::post('/invoices/{invoice}/email', [InvoiceController::class, 'email'])->name('invoices.email');
    Route::post('/invoices/{invoice}/email/resend', [InvoiceController::class, 'resendEmail'])->name('invoices.email.resend');
});

// payments.record is its own permission (Owner/Admin/Accountant, not Staff —
// config/permissions.php §7.14), independent of invoices.send.
Route::middleware(['auth', 'verified', 'company', 'can:payments.record'])->group(function () {
    Route::post('/invoices/{invoice}/payments', [InvoiceController::class, 'recordPayment'])->name('invoices.payments.record');
});

// invoices.void gates BOTH void actions (Owner/Admin/Accountant, not Staff —
// config/permissions.php §7.14): voiding a payment (P2/P3 reversal) and
// voiding the invoice itself (P9, spec §6) are two steps of the same
// accounting-correct "void an invoice" workflow (payments first).
Route::middleware(['auth', 'verified', 'company', 'can:invoices.void'])->group(function () {
    Route::post('/invoices/{invoice}/void', [InvoiceController::class, 'void'])->name('invoices.void');
    Route::post('/invoices/{invoice}/payments/{payment}/void', [InvoiceController::class, 'voidPayment'])->name('invoices.payments.void');
});

// Phase 4 Task 2: draft-only expense/bill CRUD — expenses.draft/bills.draft
// are held by ALL four roles (spec §7.14, Staff included), same shape as
// invoices.draft. Record/receive (Task 3/4, below) got their own narrower
// gates, mirroring invoices.send/void above; void/payments are still later
// Phase 4 tasks.
Route::middleware(['auth', 'verified', 'company', 'can:expenses.draft'])->group(function () {
    Route::get('/expenses', [ExpenseController::class, 'index'])->name('expenses.index');
    Route::get('/expenses/new', [ExpenseController::class, 'create'])->name('expenses.new');
    Route::post('/expenses', [ExpenseController::class, 'store'])->name('expenses.store');
    // Registered BEFORE /edit below on purpose — mirrors invoices.show/
    // invoices.edit's own ordering comment (Phase 3 T11): both are GETs
    // under /expenses/{expense}/..., Laravel matches in registration order.
    // Phase 4 Task 9: the canonical landing page for every status, including
    // draft (Edit remains the draft EDITOR only, as of this task).
    Route::get('/expenses/{expense}', [ExpenseController::class, 'show'])->name('expenses.show');
    Route::get('/expenses/{expense}/edit', [ExpenseController::class, 'edit'])->name('expenses.edit');
    Route::patch('/expenses/{expense}', [ExpenseController::class, 'update'])->name('expenses.update');
    Route::delete('/expenses/{expense}', [ExpenseController::class, 'destroy'])->name('expenses.destroy');

    // Phase 4 Task 8 (spec §17.8): attachments are addable anytime — same
    // broad, all-four-roles gate as the rest of this group — and download
    // shares it too (anyone who can see the expense can pull its receipts).
    // Delete is narrower (expenses.void, below).
    Route::post('/expenses/{expense}/attachments', [ExpenseController::class, 'storeAttachment'])->name('expenses.attachments.store');
    Route::get('/expenses/{expense}/attachments/{attachment}', [ExpenseController::class, 'downloadAttachment'])->name('expenses.attachments.download');
});

// expenses.record is a narrower gate than expenses.draft (Staff can draft but
// not record, see config/permissions.php §7.14), so it needs its own group —
// mirrors invoices.send/invoices.draft's split (Phase 3 T3).
Route::middleware(['auth', 'verified', 'company', 'can:expenses.record'])->group(function () {
    Route::post('/expenses/{expense}/record', [ExpenseController::class, 'record'])->name('expenses.record');
});

// expenses.void — Phase 4 Task 6, mirrors invoices.void's own narrower-than-
// draft split (Owner/Admin/Accountant, not Staff — config/permissions.php §7.14).
// Task 8 reuses this SAME ability for deleting an attachment (spec §17.8's
// decision: deletes are Owner/Admin/Accountant only) rather than inventing a
// new one — the tightest existing ability that already maps to that exact
// role set.
Route::middleware(['auth', 'verified', 'company', 'can:expenses.void'])->group(function () {
    Route::post('/expenses/{expense}/void', [ExpenseController::class, 'void'])->name('expenses.void');
    Route::delete('/expenses/{expense}/attachments/{attachment}', [ExpenseController::class, 'destroyAttachment'])->name('expenses.attachments.destroy');
});

Route::middleware(['auth', 'verified', 'company', 'can:bills.draft'])->group(function () {
    Route::get('/bills', [BillController::class, 'index'])->name('bills.index');
    Route::get('/bills/new', [BillController::class, 'create'])->name('bills.new');
    Route::post('/bills', [BillController::class, 'store'])->name('bills.store');
    // Registered BEFORE /edit below — mirrors expenses.show's own ordering
    // comment above (Phase 4 Task 9). The canonical landing page for every
    // status, including draft and the opening-bill banner (relocated here
    // from Edit this same task).
    Route::get('/bills/{bill}', [BillController::class, 'show'])->name('bills.show');
    Route::get('/bills/{bill}/edit', [BillController::class, 'edit'])->name('bills.edit');
    Route::patch('/bills/{bill}', [BillController::class, 'update'])->name('bills.update');
    Route::delete('/bills/{bill}', [BillController::class, 'destroy'])->name('bills.destroy');

    // Phase 4 Task 8 — mirrors the expenses.attachments group above exactly.
    Route::post('/bills/{bill}/attachments', [BillController::class, 'storeAttachment'])->name('bills.attachments.store');
    Route::get('/bills/{bill}/attachments/{attachment}', [BillController::class, 'downloadAttachment'])->name('bills.attachments.download');
});

// bills.receive is a narrower gate than bills.draft (Staff can draft but not
// receive) — mirrors expenses.record/expenses.draft above.
Route::middleware(['auth', 'verified', 'company', 'can:bills.receive'])->group(function () {
    Route::post('/bills/{bill}/receive', [BillController::class, 'receive'])->name('bills.receive');
});

// Phase 4 Task 5: pay bill (P7) — payments.record is the SAME permission
// invoices.payments.record uses above (Owner/Admin/Accountant, not Staff —
// config/permissions.php §7.14): recording a payment is one ability shared
// across both AR and AP, not a separate bills-specific gate.
Route::middleware(['auth', 'verified', 'company', 'can:payments.record'])->group(function () {
    Route::post('/bills/{bill}/payments', [BillController::class, 'recordPayment'])->name('bills.payments.record');
});

// bills.void gates BOTH void actions (Owner/Admin/Accountant, not Staff —
// config/permissions.php §7.14): voiding a payment (P7 reversal) and voiding
// the bill itself (P9, spec §6) are two steps of the same accounting-correct
// "void a bill" workflow (payments first) — mirrors invoices.void gating
// both invoice + invoice payment void.
// Task 8 reuses bills.void for deleting an attachment too — same reasoning
// as the expenses.void group above (tightest existing Owner/Admin/Accountant
// ability, spec §17.8).
Route::middleware(['auth', 'verified', 'company', 'can:bills.void'])->group(function () {
    Route::post('/bills/{bill}/void', [BillController::class, 'void'])->name('bills.void');
    Route::post('/bills/{bill}/payments/{payment}/void', [BillController::class, 'voidPayment'])->name('bills.payments.void');
    Route::delete('/bills/{bill}/attachments/{attachment}', [BillController::class, 'destroyAttachment'])->name('bills.attachments.destroy');
});

Route::middleware(['auth', 'verified', 'company', 'can:items.manage'])->group(function () {
    Route::get('/items', [ItemController::class, 'index'])->name('items.index');
    Route::post('/items', [ItemController::class, 'store'])->name('items.store');
    Route::patch('/items/{item}', [ItemController::class, 'update'])->name('items.update');
    Route::post('/items/{item}/archive', [ItemController::class, 'archive'])->name('items.archive');
    Route::post('/items/{item}/unarchive', [ItemController::class, 'unarchive'])->name('items.unarchive');
});

Route::middleware(['auth', 'verified', 'company', 'can:bank_accounts.manage'])->group(function () {
    Route::get('/bank-accounts', [BankAccountController::class, 'index'])->name('bank-accounts.index');
    Route::post('/bank-accounts', [BankAccountController::class, 'store'])->name('bank-accounts.store');
    Route::patch('/bank-accounts/{bankAccount}', [BankAccountController::class, 'update'])->name('bank-accounts.update');
    Route::post('/bank-accounts/{bankAccount}/archive', [BankAccountController::class, 'archive'])->name('bank-accounts.archive');
    Route::post('/bank-accounts/{bankAccount}/unarchive', [BankAccountController::class, 'unarchive'])->name('bank-accounts.unarchive');
});

Route::middleware(['auth', 'verified', 'company', 'can:transfers.create'])->group(function () {
    Route::get('/transfers/new', [TransferController::class, 'create'])->name('transfers.new');
    Route::post('/transfers', [TransferController::class, 'store'])->name('transfers.store');
});

Route::middleware(['auth', 'verified', 'company', 'can:other_receipts.create'])->group(function () {
    Route::get('/other-receipts/new', [OtherReceiptController::class, 'create'])->name('other-receipts.new');
    Route::post('/other-receipts', [OtherReceiptController::class, 'store'])->name('other-receipts.store');
});

Route::middleware(['auth', 'verified', 'company', 'can:settings.manage'])->group(function () {
    Route::get('/setup/opening-balances', [OpeningBalanceController::class, 'create'])->name('setup.opening-balances');
    Route::post('/setup/opening-balances', [OpeningBalanceController::class, 'store'])->name('setup.opening-balances.store');
    Route::get('/setup/opening-balances/template/{kind}.csv', [OpeningCsvController::class, 'template'])
        ->where('kind', '[a-z_]+')
        ->name('setup.opening-balances.template');
    Route::post('/setup/opening-balances/import/{kind}', [OpeningCsvController::class, 'import'])
        ->where('kind', '[a-z_]+')
        ->name('setup.opening-balances.import');
});

Route::middleware(['auth', 'verified', 'company', 'can:tax_rates.manage'])->group(function () {
    Route::get('/tax-rates', [TaxRateController::class, 'index'])->name('tax-rates.index');
    Route::post('/tax-rates', [TaxRateController::class, 'store'])->name('tax-rates.store');
    Route::patch('/tax-rates/{taxRate}', [TaxRateController::class, 'update'])->name('tax-rates.update');
    Route::post('/tax-rates/{taxRate}/archive', [TaxRateController::class, 'archive'])->name('tax-rates.archive');
    Route::post('/tax-rates/{taxRate}/unarchive', [TaxRateController::class, 'unarchive'])->name('tax-rates.unarchive');
});

require __DIR__.'/auth.php';
