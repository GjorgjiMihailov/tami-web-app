<?php

namespace App\Providers;

use App\Http\Middleware\EnsureAccountingAccess;
use App\Models\BankStatement;
use App\Models\Company;
use App\Models\Form743;
use App\Models\JournalEntry;
use App\Models\OtherCost;
use App\Models\Partner;
use App\Models\PurchaseInvoice;
use App\Models\SalesInvoice;
use App\Models\User;
use App\Observers\CompanyObserver;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\ServiceProvider;
use Livewire\Livewire;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Company::observe(CompanyObserver::class);

        Relation::enforceMorphMap([
            'purchase_invoice' => PurchaseInvoice::class,
            'sales_invoice' => SalesInvoice::class,
            'journal_entry' => JournalEntry::class,
            'partner' => Partner::class,
            'user' => User::class,
            'form743' => Form743::class,
            'bank_statement' => BankStatement::class,
            'other_cost' => OtherCost::class,
        ]);

        // Route-group middleware protects the initial page load only.
        // Livewire's own update endpoint persists just Authenticate,
        // Authorize, SubstituteBindings and a few others by default — a
        // demoted user keeps a working component until this is added
        // explicitly. Registered once here so every accounting screen,
        // present and future, is covered without remembering to repeat it
        // per component.
        Livewire::addPersistentMiddleware([
            EnsureAccountingAccess::class,
        ]);
    }
}
