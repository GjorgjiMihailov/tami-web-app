<?php

use App\Livewire\Accounting\AccountIndex;
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

// NOTE: Route::get($uri, ClassString::class) resolves the action's __invoke
// method via method_exists() inside the Route constructor — i.e. at route
// *registration* time (every request/artisan bootstrap), not lazily at
// dispatch. Registering a route against a Livewire class that doesn't exist
// yet throws UnexpectedValueException("Invalid route action") immediately
// and breaks the entire app boot (every artisan command, every test).
// So each of this module's routes is registered in the same task that
// creates its target class, rather than all six up front:
//   - accounts.index            -> this task (Task 7)
//   - journal-entries.index     -> Task 8 (creates JournalEntryIndex)
//   - journal-entries.create    -> Task 8 (creates JournalEntryForm)
//   - journal-entries.edit      -> Task 8 (creates JournalEntryForm)
//   - reports.ledger-card       -> Task 9/10 (creates LedgerCardReport)
//   - reports.trial-balance     -> Task 9/11 (creates TrialBalanceReport)
// Whichever task adds each class should add its route(s) to this same
// 'accounting.' group below.
Route::middleware(['auth'])->prefix('companies/{company}')->name('accounting.')->group(function () {
    Route::get('/accounts', AccountIndex::class)->name('accounts.index');
});

require __DIR__.'/auth.php';
