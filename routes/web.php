<?php

use App\Http\Controllers\AccountController;
use App\Http\Controllers\CompanySwitchController;
use App\Http\Controllers\ContactController;
use App\Http\Controllers\HealthController;
use App\Http\Controllers\ManualJournalController;
use App\Http\Controllers\OnboardingController;
use App\Http\Controllers\PeriodLockController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\Reports\TrialBalanceController;
use App\Http\Controllers\TaxRateController;
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

Route::get('/dashboard', function () {
    return Inertia::render('Dashboard');
})->middleware(['auth', 'verified', 'company'])->name('dashboard');

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

Route::middleware(['auth', 'verified', 'company', 'can:tax_rates.manage'])->group(function () {
    Route::get('/tax-rates', [TaxRateController::class, 'index'])->name('tax-rates.index');
    Route::post('/tax-rates', [TaxRateController::class, 'store'])->name('tax-rates.store');
    Route::patch('/tax-rates/{taxRate}', [TaxRateController::class, 'update'])->name('tax-rates.update');
    Route::post('/tax-rates/{taxRate}/archive', [TaxRateController::class, 'archive'])->name('tax-rates.archive');
    Route::post('/tax-rates/{taxRate}/unarchive', [TaxRateController::class, 'unarchive'])->name('tax-rates.unarchive');
});

require __DIR__.'/auth.php';
