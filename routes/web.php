<?php

use App\Livewire\Accounting\AccountIndex;
use App\Livewire\Accounting\JournalEntryForm;
use App\Livewire\Accounting\JournalEntryIndex;
use App\Livewire\Accounting\LedgerCardReport;
use App\Livewire\Accounting\TrialBalanceReport;
use App\Livewire\CompanyIndex;
use App\Livewire\Inventory\ItemIndex;
use App\Livewire\Inventory\ItemMovementCardReport;
use App\Livewire\Inventory\StockMovementForm;
use App\Livewire\Inventory\StockOnHandReport;
use App\Livewire\Inventory\StockValuationReport;
use App\Livewire\Inventory\WarehouseIndex;
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

// Array-callable form (not bare class-string) for the same reason as the
// accounting.* group above: five of these six target classes don't exist
// until later Inventory tasks, and a bare class-string would crash route
// registration immediately.
Route::middleware(['auth'])->prefix('companies/{company}')->name('inventory.')->group(function () {
    Route::get('/warehouses', [WarehouseIndex::class, '__invoke'])->name('warehouses.index');
    Route::get('/items', [ItemIndex::class, '__invoke'])->name('items.index');
    Route::get('/stock-movements/create/{type}', [StockMovementForm::class, '__invoke'])->name('stock-movements.create');
    Route::get('/reports/stock-on-hand', [StockOnHandReport::class, '__invoke'])->name('reports.stock-on-hand');
    Route::get('/reports/item-movement-card', [ItemMovementCardReport::class, '__invoke'])->name('reports.item-movement-card');
    Route::get('/reports/stock-valuation', [StockValuationReport::class, '__invoke'])->name('reports.stock-valuation');
});

require __DIR__.'/auth.php';
