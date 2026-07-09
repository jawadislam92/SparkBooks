<?php

use App\Http\Controllers\AccountController;
use App\Http\Controllers\BankAccountController;
use App\Http\Controllers\CompanySwitchController;
use App\Http\Controllers\ContactController;
use App\Http\Controllers\DashboardController;
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
    Route::get('/invoices/{invoice}/edit', [InvoiceController::class, 'edit'])->name('invoices.edit');
    Route::patch('/invoices/{invoice}', [InvoiceController::class, 'update'])->name('invoices.update');
    Route::delete('/invoices/{invoice}', [InvoiceController::class, 'destroy'])->name('invoices.destroy');
});

// invoices.send is a narrower gate than invoices.draft (Staff can draft but
// not send, see config/permissions.php §7.14), so it needs its own group.
Route::middleware(['auth', 'verified', 'company', 'can:invoices.send'])->group(function () {
    Route::post('/invoices/{invoice}/send', [InvoiceController::class, 'send'])->name('invoices.send');
});

// payments.record is its own permission (Owner/Admin/Accountant, not Staff —
// config/permissions.php §7.14), independent of invoices.send.
Route::middleware(['auth', 'verified', 'company', 'can:payments.record'])->group(function () {
    Route::post('/invoices/{invoice}/payments', [InvoiceController::class, 'recordPayment'])->name('invoices.payments.record');
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
