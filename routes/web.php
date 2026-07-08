<?php

use App\Http\Controllers\AccountController;
use App\Http\Controllers\CompanySwitchController;
use App\Http\Controllers\ManualJournalController;
use App\Http\Controllers\OnboardingController;
use App\Http\Controllers\ProfileController;
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

Route::middleware(['auth', 'verified', 'company', 'can:accounts.manage'])->group(function () {
    Route::get('/accounts', [AccountController::class, 'index'])->name('accounts.index');
    Route::post('/accounts', [AccountController::class, 'store'])->name('accounts.store');
    Route::patch('/accounts/{account}', [AccountController::class, 'update'])->name('accounts.update');
    Route::post('/accounts/{account}/archive', [AccountController::class, 'archive'])->name('accounts.archive');
    Route::post('/accounts/{account}/unarchive', [AccountController::class, 'unarchive'])->name('accounts.unarchive');
    Route::get('/accounts/{account}', [AccountController::class, 'show'])->name('accounts.show');
});

require __DIR__.'/auth.php';
