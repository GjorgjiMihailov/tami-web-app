<?php

namespace App\Providers;

use App\Models\Company;
use App\Models\JournalEntry;
use App\Models\Partner;
use App\Models\PurchaseInvoice;
use App\Models\SalesInvoice;
use App\Models\User;
use App\Observers\CompanyObserver;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\ServiceProvider;

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
        ]);
    }
}
