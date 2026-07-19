<?php

use App\Livewire\Accounting\AccountIndex;
use App\Livewire\Accounting\JournalEntryForm;
use App\Livewire\Accounting\JournalEntryIndex;
use App\Livewire\Accounting\LedgerCardReport;
use App\Livewire\Accounting\TrialBalanceReport;
use App\Livewire\CompanyIndex;
use Illuminate\Support\Facades\Route;

Route::view('/', 'welcome');

Route::view('dashboard', 'dashboard')
    ->middleware(['auth', 'verified'])
    ->name('dashboard');

Route::view('profile', 'profile')
    ->middleware(['auth'])
    ->name('profile');

Route::middleware(['auth'])->group(function () {
    Route::get('/companies', CompanyIndex::class)->name('companies.index');
});

// NOTE: Route::get($uri, ClassString::class) (bare class-string) resolves
// method_exists($action, '__invoke') eagerly at route *registration* time,
// so registering a route against a Livewire class that doesn't exist yet
// throws UnexpectedValueException("Invalid route action") immediately and
// breaks the entire app boot. Using the array-callable form
// [ClassString::class, '__invoke'] instead avoids this: Laravel's
// is_callable($action, true) syntax-only check accepts a 2-element
// [string, string] array without verifying the class/method exist, so all
// six routes can be registered up front even though four of their target
// classes (JournalEntryIndex, JournalEntryForm, LedgerCardReport,
// TrialBalanceReport) are only built in later tasks. Both forms resolve to
// the same action at dispatch time once the class exists.
Route::middleware(['auth'])->prefix('companies/{company}')->name('accounting.')->group(function () {
    Route::get('/accounts', [AccountIndex::class, '__invoke'])->name('accounts.index');
    Route::get('/journal-entries', [JournalEntryIndex::class, '__invoke'])->name('journal-entries.index');
    Route::get('/journal-entries/create', [JournalEntryForm::class, '__invoke'])->name('journal-entries.create');
    Route::get('/journal-entries/{journalEntry}/edit', [JournalEntryForm::class, '__invoke'])->name('journal-entries.edit');
    Route::get('/reports/ledger-card', [LedgerCardReport::class, '__invoke'])->name('reports.ledger-card');
    Route::get('/reports/trial-balance', [TrialBalanceReport::class, '__invoke'])->name('reports.trial-balance');
});

require __DIR__.'/auth.php';
