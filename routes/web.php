<?php

use App\Http\Controllers\DocumentController;
use App\Http\Controllers\EfakturaIncomingAcceptController;
use App\Http\Controllers\EfakturaIncomingDiscoveryController;
use App\Http\Controllers\EfakturaIncomingPdfController;
use App\Http\Controllers\EfakturaIncomingRejectController;
use App\Http\Controllers\EfakturaPdfController;
use App\Http\Controllers\EfakturaSendController;
use App\Http\Controllers\EfakturaStatusController;
use App\Http\Controllers\Form743DocumentController;
use App\Http\Controllers\ItemImportTemplateController;
use App\Http\Controllers\JournalEntryPdfController;
use App\Http\Controllers\MpinExportController;
use App\Http\Controllers\PartnerListPdfController;
use App\Http\Controllers\PartnerShowRedirectController;
use App\Http\Controllers\PartnerStatementPdfController;
use App\Http\Controllers\PayrollRecapPdfController;
use App\Http\Controllers\PayslipPdfController;
use App\Http\Controllers\ProformaPdfController;
use App\Http\Controllers\SalesInvoicePdfController;
use App\Livewire\Invoicing\ProformaForm;
use App\Livewire\Invoicing\ProformaIndex;
use App\Http\Controllers\StockOnHandPdfController;
use App\Http\Middleware\EnsureAccountingAccess;
use App\Http\Middleware\EnsureAppAccess;
use App\Http\Middleware\EnsureCompanyModule;
use App\Http\Middleware\EnsureIndividual;
use App\Http\Middleware\EnsureLegalEntity;
use App\Livewire\Accounting\AccountIndex;
use App\Livewire\Accounting\JournalEntryForm;
use App\Livewire\Accounting\JournalEntryIndex;
use App\Livewire\Accounting\JournalGroupIndex;
use App\Livewire\Accounting\LedgerCardReport;
use App\Livewire\Accounting\TrialBalanceReport;
use App\Livewire\Apps\SalesDashboard;
use App\Livewire\Bank\BankStatementIndex;
use App\Livewire\Bank\Form743Upload;
use App\Livewire\Bank\Form743Worklist;
use App\Livewire\ComingSoon;
use App\Livewire\CompanyDashboard;
use App\Livewire\CompanyIndex;
use App\Livewire\CompanyModules;
use App\Livewire\CompanyProfile;
use App\Livewire\CompanyUsers;
use App\Livewire\Costs\OtherCostIndex;
use App\Livewire\Dashboard;
use App\Livewire\DocumentIndex;
use App\Livewire\Efaktura\PendingSendList;
use App\Livewire\EmployeeForm;
use App\Livewire\EmployeeIndex;
use App\Livewire\FirstClient;
use App\Livewire\Inventory\ItemBulkImport;
use App\Livewire\Inventory\ItemForm;
use App\Livewire\Inventory\ItemIndex;
use App\Livewire\Inventory\ItemMovementCardReport;
use App\Livewire\Inventory\StockMovementForm;
use App\Livewire\Inventory\StockOnHandReport;
use App\Livewire\Inventory\StockValuationReport;
use App\Livewire\Inventory\WarehouseIndex;
use App\Livewire\Invoicing\InvoiceSettings;
use App\Livewire\Invoicing\PurchaseInvoiceForm;
use App\Livewire\Invoicing\PurchaseInvoiceIndex;
use App\Livewire\Invoicing\PurchaseInvoiceShow;
use App\Livewire\Invoicing\SalesInvoiceForm;
use App\Livewire\Invoicing\SalesInvoiceIndex;
use App\Livewire\Invoicing\SalesInvoiceShow;
use App\Livewire\OfficeUsers;
use App\Livewire\PartnerForm;
use App\Livewire\PartnerIndex;
use App\Livewire\Payroll\PayrollRunIndex;
use App\Livewire\Payroll\PayrollRunShow;
use App\Livewire\PayrollParameterIndex;
use App\Livewire\Reports\Ddv04Report;
use App\Livewire\Reports\ReportIndex;
use App\Support\LandingUrl;
use App\Support\PortalApp;
use Illuminate\Support\Facades\Route;

// „Наскоро" рутата ја користи повеќе од една апликација (е-ПДД е во ПЛАТА,
// Профактури и Попис се во ПРОДАЖБА), па намерно стои надвор од секоја
// Route::domain() група — исто како рутите за најава подолу во auth.php —
// за да одговара на секој хост, а не само на оној каде беше регистрирана
// првата „наскоро" ставка.
Route::middleware(['auth'])->prefix('companies/{company}')->group(function () {
    Route::get('/naskoro/{feature}', [ComingSoon::class, '__invoke'])->name('coming-soon');
});

Route::domain(PortalApp::PORTAL->domain())->group(function () {
    // Јавната влезна страна. Отворена и за најавени корисници — за нив копчињата
    // пишуваат „Влези во порталот" наместо „Најави се", па нема причина да се
    // пренасочуваат.
    Route::view('/', 'marketing.home')->name('home');

    Route::get('dashboard', [Dashboard::class, '__invoke'])
        ->middleware(['auth', 'verified'])
        ->name('dashboard');

    // Излезот за сметководител што сè уште нема ниту еден клиент. Стои на
    // порталот зашто тој човек нема фирма, па нема ни апликациски екран што
    // би можел да го отвори.
    Route::get('prv-klient', [FirstClient::class, '__invoke'])
        ->middleware(['auth', 'verified'])
        ->name('onboarding.first-client');

    Route::view('profile', 'profile')
        ->middleware(['auth'])
        ->name('profile');

    Route::middleware(['auth'])->group(function () {
        Route::get('/companies', CompanyIndex::class)->name('companies.index');
    });

    // Клиенти на админот: еден список и по една страница за внес на секој вид профил.
    Route::middleware(['auth'])->group(function () {
        Route::get('/klienti', \App\Livewire\ClientIndex::class)->name('clients.index');
        Route::get('/klienti/nov/{kind}', \App\Livewire\ClientCreate::class)->name('clients.create');
        Route::get('/klienti/smetkovoditel/{user}', \App\Livewire\ClientAccountantShow::class)->name('clients.accountant');
    });

    // Array-callable form (not bare class-string) for the same reason noted
    // below for the accounting.* group: avoids an eager method_exists() check
    // at route registration time.

    // Мора да стои пред групата `companies/{company}`, инаку 'office' би бил фатен
    // како фирма.
    Route::middleware(['auth'])->get('/companies/office', [OfficeUsers::class, '__invoke'])
        ->name('companies.office');

    Route::middleware(['auth'])->prefix('companies/{company}')->group(function () {
        Route::get('/dashboard', [CompanyDashboard::class, '__invoke'])->name('companies.dashboard');
        Route::get('/profile', [CompanyProfile::class, '__invoke'])->name('companies.profile');
        Route::get('/modules', [CompanyModules::class, '__invoke'])->name('companies.modules');
        Route::get('/users', [CompanyUsers::class, '__invoke'])->name('companies.users');
    });

    // Работниот список е на канцеларијата и ги собира обрасците од сите клиенти,
    // па намерно стои надвор од `companies/{company}`.
    Route::middleware(['auth'])->get('/743-obrasci', [Form743Worklist::class, '__invoke'])->name('form743.worklist');
    // Работен список на канцеларијата (низ сите клиенти), по угледот на 743 обрасците.
    Route::middleware(['auth'])->get('/efaktura/na-cekanje', [PendingSendList::class, '__invoke'])->name('efaktura.pending');

    // form743.download е преземање датотека, не сметководствен екран — работниот
    // список погоре го линкува од порталот, каде секој сметководител/админ смее
    // да пристапи без разлика на правото за finansii. Истата брана (EnsureIndividual)
    // патува со рутата.
    Route::middleware(['auth', EnsureIndividual::class])->prefix('companies/{company}')->name('form743.')->group(function () {
        Route::get('/743/{form743}/download', [Form743DocumentController::class, '__invoke'])->name('download');
    });

    // documents.download е исто така преземање датотека, не екран на Продажба —
    // Банкарски документи (finansii) го линкува овој истиот URL. Истата брана
    // (EnsureLegalEntity) патува со рутата.
    Route::middleware(['auth', EnsureLegalEntity::class])->prefix('companies/{company}')->name('documents.')->group(function () {
        Route::get('/documents/{document}', [DocumentController::class, '__invoke'])->name('download');
    });
});

Route::domain(PortalApp::PRODAZBA->domain())->middleware(EnsureAppAccess::class.':prodazba')->group(function () {
    // Коренот на апликацијата. Јавната влезна страна живее само на порталот, па
    // без ова човек што ќе ја напише голата адреса добива 404. `auth` ги носи
    // ненајавените на формата за најава на ИСТИОТ хост, а најавените одат на
    // својот почетен екран.
    Route::get('/', fn () => redirect(LandingUrl::for(auth()->user(), PortalApp::PRODAZBA)))->middleware('auth');

    // Таблата на апликацијата. Намерно БЕЗ EnsureCompanyModule: Кооперанти
    // немаат модул (партнерите ги бара и книжењето), па таблата мора да
    // преживее исклучено Материјално. Секое копче на неа сама си одлучува
    // дали да се нацрта.
    Route::middleware(['auth'])->get('/companies/{company}/tabla', [SalesDashboard::class, '__invoke'])
        ->name('prodazba.dashboard');

    // Array-callable form (not bare class-string) for the same reason as the
    // accounting.* group above. (Historically some of these target classes
    // didn't exist yet during earlier Inventory tasks, which would have
    // crashed route registration with a bare class-string; all classes in
    // this group exist now.)
    Route::middleware(['auth', EnsureLegalEntity::class, EnsureCompanyModule::class.':stock'])->prefix('companies/{company}')->name('inventory.')->group(function () {
        Route::get('/warehouses', [WarehouseIndex::class, '__invoke'])->name('warehouses.index');
        Route::get('/items', [ItemIndex::class, '__invoke'])->name('items.index');
        Route::get('/items/create', [ItemForm::class, '__invoke'])->name('items.create');
        Route::get('/items/{item}/edit', [ItemForm::class, '__invoke'])->name('items.edit');
        Route::get('/items/bulk-import', [ItemBulkImport::class, '__invoke'])->name('items.bulk-import');
        Route::get('/items/bulk-import/template', [ItemImportTemplateController::class, '__invoke'])->name('items.bulk-import.template');
        Route::get('/stock-movements/create/{type}', [StockMovementForm::class, '__invoke'])->name('stock-movements.create');
        Route::get('/reports/stock-on-hand', [StockOnHandReport::class, '__invoke'])->name('reports.stock-on-hand');
        Route::get('/reports/stock-on-hand/pdf', [StockOnHandPdfController::class, '__invoke'])->name('reports.stock-on-hand.pdf');
        Route::get('/reports/item-movement-card', [ItemMovementCardReport::class, '__invoke'])->name('reports.item-movement-card');
        Route::get('/reports/stock-valuation', [StockValuationReport::class, '__invoke'])->name('reports.stock-valuation');
    });

    Route::middleware(['auth'])->prefix('companies/{company}')->name('partners.')->group(function () {
        Route::get('/partners', [PartnerIndex::class, '__invoke'])->name('index');
        Route::get('/partners/pdf', [PartnerListPdfController::class, '__invoke'])->name('pdf');
        Route::get('/partners/create', [PartnerForm::class, '__invoke'])->name('create');
        Route::get('/partners/{partner}/edit', [PartnerForm::class, '__invoke'])->name('edit');
        Route::get('/partners/{partner}/statement/pdf', [PartnerStatementPdfController::class, '__invoke'])->name('statement.pdf');
        // Детали за кооперант живеат во листата (лево список, десно детали) — стар линк ги носи таму.
        Route::get('/partners/{partner}', [PartnerShowRedirectController::class, '__invoke'])->name('show');
    });

    // Array-callable form (not bare class-string) for the same reason as the
    // accounting.* and inventory.* groups above: four of these five target
    // classes don't exist until later Invoicing tasks, and a bare class-string
    // would crash route registration immediately.
    Route::middleware(['auth', EnsureCompanyModule::class.':material'])->prefix('companies/{company}')->name('sales-invoices.')->group(function () {
        Route::get('/sales-invoices', [SalesInvoiceIndex::class, '__invoke'])->name('index');
        Route::get('/sales-invoices/create', [SalesInvoiceForm::class, '__invoke'])->name('create');
        Route::get('/sales-invoices/{salesInvoice}/edit', [SalesInvoiceForm::class, '__invoke'])->name('edit');
        Route::get('/sales-invoices/{salesInvoice}', [SalesInvoiceShow::class, '__invoke'])->name('show');
        Route::get('/sales-invoices/{salesInvoice}/pdf', [SalesInvoicePdfController::class, '__invoke'])->name('pdf');
    });

    // Профактури: ист модул (Материјално) како излезните фактури, отворено и за физичко лице.
    Route::middleware(['auth', EnsureCompanyModule::class.':material'])->prefix('companies/{company}')->name('proformas.')->group(function () {
        Route::get('/proformas', [ProformaIndex::class, '__invoke'])->name('index');
        Route::get('/proformas/create', [ProformaForm::class, '__invoke'])->name('create');
        Route::get('/proformas/{proforma}/edit', [ProformaForm::class, '__invoke'])->name('edit');
        Route::get('/proformas/{proforma}/pdf', [ProformaPdfController::class, '__invoke'])->name('pdf');
    });

    // Намерно ВОН sales-invoices.* — Menu.php ги бои групите со Str::is() врз
    // првото совпаѓање, а „Излезни фактури" во ПРОДАЖБА веќе користи
    // sales-invoices.* како шаблон. Кога поставките седеа на
    // sales-invoices.settings, ПРОДАЖБА секогаш го краднеше означувањето од
    // ПОСТАВКИ (Sidebar::groupMatchingCurrentRoute() го враќа првиот погодок).
    // Истите middleware како sales-invoices. групата — истите правила на пристап
    // како листата со фактури.
    Route::middleware(['auth', EnsureCompanyModule::class.':material'])->prefix('companies/{company}')->name('invoice-settings.')->group(function () {
        Route::get('/invoice-settings', [InvoiceSettings::class, '__invoke'])->name('index');
    });

    Route::middleware(['auth', EnsureCompanyModule::class.':material'])->prefix('companies/{company}/sales-invoices/{salesInvoice}')->name('sales-invoices.efaktura.')->group(function () {
        Route::post('/efaktura/signing-input', [EfakturaSendController::class, 'signingInput'])->name('signing-input');
        Route::post('/efaktura/send', [EfakturaSendController::class, 'send'])->name('send');
        Route::post('/efaktura/pdf/signing-input', [EfakturaPdfController::class, 'signingInput'])->name('pdf.signing-input');
        Route::post('/efaktura/pdf', [EfakturaPdfController::class, 'store'])->name('pdf.store');
        Route::get('/efaktura/pdf/download', [EfakturaPdfController::class, 'download'])->name('pdf.download');
    });

    Route::middleware(['auth', EnsureCompanyModule::class.':material'])->prefix('companies/{company}/sales-invoices')->name('sales-invoices.efaktura.')->group(function () {
        Route::post('/efaktura/refresh-statuses/signing-input', [EfakturaStatusController::class, 'signingInput'])->name('refresh-statuses.signing-input');
        Route::post('/efaktura/refresh-statuses', [EfakturaStatusController::class, 'refresh'])->name('refresh-statuses');
    });

    // Array-callable form (not bare class-string) for the same reason as the
    // accounting.*, inventory.*, and sales-invoices.* groups above: four of
    // these five target classes don't exist until later Purchase Invoicing
    // tasks, and a bare class-string would crash route registration immediately.
    Route::middleware(['auth', EnsureLegalEntity::class, EnsureCompanyModule::class.':material'])->prefix('companies/{company}')->name('purchase-invoices.')->group(function () {
        Route::get('/purchase-invoices', [PurchaseInvoiceIndex::class, '__invoke'])->name('index');
        Route::get('/purchase-invoices/create', [PurchaseInvoiceForm::class, '__invoke'])->name('create');
        Route::get('/purchase-invoices/{purchaseInvoice}/edit', [PurchaseInvoiceForm::class, '__invoke'])->name('edit');
        Route::get('/purchase-invoices/{purchaseInvoice}', [PurchaseInvoiceShow::class, '__invoke'])->name('show');
    });

    Route::middleware(['auth', EnsureLegalEntity::class, EnsureCompanyModule::class.':material'])->prefix('companies/{company}/incoming-efaktura')->name('incoming-efaktura.')->group(function () {
        Route::post('/discover/ids/signing-input', [EfakturaIncomingDiscoveryController::class, 'idsSigningInput'])->name('discover.ids.signing-input');
        Route::post('/discover/ids', [EfakturaIncomingDiscoveryController::class, 'ids'])->name('discover.ids');
        Route::post('/discover/payload/signing-input', [EfakturaIncomingDiscoveryController::class, 'payloadSigningInput'])->name('discover.payload.signing-input');
        Route::post('/discover/payload', [EfakturaIncomingDiscoveryController::class, 'payload'])->name('discover.payload');
        Route::post('/discover/status/signing-input', [EfakturaIncomingDiscoveryController::class, 'statusSigningInput'])->name('discover.status.signing-input');
        Route::post('/discover/status', [EfakturaIncomingDiscoveryController::class, 'status'])->name('discover.status');
    });

    Route::middleware(['auth', EnsureLegalEntity::class, EnsureCompanyModule::class.':material'])->prefix('companies/{company}/incoming-efaktura/{incomingEfakturaDocument}')->name('incoming-efaktura.')->group(function () {
        Route::post('/accept/signing-input', [EfakturaIncomingAcceptController::class, 'signingInput'])->name('accept.signing-input');
        Route::post('/accept', [EfakturaIncomingAcceptController::class, 'store'])->name('accept');
        Route::post('/reject/signing-input', [EfakturaIncomingRejectController::class, 'signingInput'])->name('reject.signing-input');
        Route::post('/reject', [EfakturaIncomingRejectController::class, 'store'])->name('reject');
        Route::post('/pdf/signing-input', [EfakturaIncomingPdfController::class, 'signingInput'])->name('pdf.signing-input');
        Route::post('/pdf', [EfakturaIncomingPdfController::class, 'store'])->name('pdf.store');
        Route::get('/pdf/download', [EfakturaIncomingPdfController::class, 'download'])->name('pdf.download');
    });

    // Документите тука се сметководствени прилози на фирма (влезни фактури, изводи,
    // договори), па групата намерно е затворена за профил на физичко лице.
    //
    // Фаза Б додава прикачување на 743 обрасци токму за физички лица. Тоа НЕ значи
    // дека EnsureLegalEntity се тргнува од оваа група — 743 обрасците излегуваат од
    // неа во сопствена група со сопствена рута, а оваа останува каква што е.
    Route::middleware(['auth', EnsureLegalEntity::class, EnsureCompanyModule::class.':material'])->prefix('companies/{company}')->name('other-costs.')->group(function () {
        Route::get('/drugi-trosoci', [OtherCostIndex::class, '__invoke'])->name('index');
    });

    // documents.download е тргнат на порталот (види ја портал групата погоре) —
    // само listата останува тука.
    Route::middleware(['auth', EnsureLegalEntity::class])->prefix('companies/{company}')->name('documents.')->group(function () {
        Route::get('/documents', [DocumentIndex::class, '__invoke'])->name('index');
    });
});

Route::domain(PortalApp::FINANSII->domain())->middleware(EnsureAppAccess::class.':finansii')->group(function () {
    // Истата причина како кај Продажба: голата адреса мора да води некаде.
    Route::get('/', fn () => redirect(LandingUrl::for(auth()->user(), PortalApp::FINANSII)))->middleware('auth');

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
    Route::middleware(['auth', EnsureAccountingAccess::class, EnsureLegalEntity::class, EnsureCompanyModule::class.':finance'])->prefix('companies/{company}')->name('accounting.')->group(function () {
        Route::get('/accounts', [AccountIndex::class, '__invoke'])->name('accounts.index');
        Route::get('/journal-groups', [JournalGroupIndex::class, '__invoke'])->name('journal-groups.index');
        Route::get('/journal-groups/{journalGroup}/entries', [JournalEntryIndex::class, '__invoke'])->name('journal-groups.entries');
        Route::get('/journal-entries', [JournalEntryIndex::class, '__invoke'])->name('journal-entries.index');
        Route::get('/journal-entries/create', [JournalEntryForm::class, '__invoke'])->name('journal-entries.create');
        Route::get('/journal-entries/{journalEntry}/edit', [JournalEntryForm::class, '__invoke'])->name('journal-entries.edit');
        Route::get('/journal-entries/{journalEntry}/pdf', [JournalEntryPdfController::class, '__invoke'])->name('journal-entries.pdf');
    });

    // Читачки извештаи: LedgerCardReport/TrialBalanceReport немаат ниту едно
    // дејство што пишува, само mount()+render(), веќе штитени со
    // Gate::authorize('view', $company). internal_client ги гледа сопствените.
    Route::middleware(['auth', EnsureLegalEntity::class, EnsureCompanyModule::class.':finance'])->prefix('companies/{company}')->name('accounting.')->group(function () {
        Route::get('/reports/ledger-card', [LedgerCardReport::class, '__invoke'])->name('reports.ledger-card');
        Route::get('/reports/trial-balance', [TrialBalanceReport::class, '__invoke'])->name('reports.trial-balance');
    });

    // 743 обрасците се на физичко лице, па оваа група стои надвор од `documents.`
    // и носи сопствена брана — огледалото на `EnsureLegalEntity`.
    //
    // Групата некогаш вклучуваше и form743.worklist, но тој е работен список на
    // канцеларијата над сите клиенти (нема фирма во патеката, работи за сите
    // фирми), па остана на порталот. form743.download е исто така преместен на
    // порталот (види ја портал групата на почетокот на фајлот) — преземање
    // датотека е апликациски неутрално и работниот список на порталот го
    // линкува директно, без разлика на правото за finansii. Овде останува само
    // формата за прикачување (form743.index), која навистина е екран на
    // Финансии.
    Route::middleware(['auth', EnsureIndividual::class])->prefix('companies/{company}')->name('form743.')->group(function () {
        Route::get('/743', [Form743Upload::class, '__invoke'])->name('index');
    });

    Route::middleware(['auth', EnsureLegalEntity::class, EnsureCompanyModule::class.':finance'])->prefix('companies/{company}')->name('bank-statements.')->group(function () {
        Route::get('/izvodi', [BankStatementIndex::class, '__invoke'])->name('index');
    });

    Route::middleware(['auth', EnsureLegalEntity::class, EnsureCompanyModule::class.':finance'])->prefix('companies/{company}')->name('reports.')->group(function () {
        Route::get('/reports', [ReportIndex::class, '__invoke'])->name('index');
        Route::get('/reports/ddv04', [Ddv04Report::class, '__invoke'])->name('ddv04');
    });
});

Route::domain(PortalApp::PLATA->domain())->middleware(EnsureAppAccess::class.':plata')->group(function () {
    // Истата причина како кај Продажба: голата адреса мора да води некаде.
    Route::get('/', fn () => redirect(LandingUrl::for(auth()->user(), PortalApp::PLATA)))->middleware('auth');

    // Array-callable form (not bare class-string) for the same reason as the
    // accounting.* group above: EmployeeIndex and EmployeeForm don't exist until
    // Tasks 8 and 9, and a bare class-string would crash route registration
    // immediately.
    Route::middleware(['auth', EnsureLegalEntity::class, EnsureCompanyModule::class.':payroll'])->prefix('companies/{company}')->name('employees.')->group(function () {
        Route::get('/employees', [EmployeeIndex::class, '__invoke'])->name('index');
        Route::get('/employees/create', [EmployeeForm::class, '__invoke'])->name('create');
        Route::get('/employees/{employee}/edit', [EmployeeForm::class, '__invoke'])->name('edit');
    });

    Route::middleware(['auth', EnsureLegalEntity::class, EnsureCompanyModule::class.':payroll'])->prefix('companies/{company}')->name('payroll-parameters.')->group(function () {
        Route::get('/payroll-parameters', [PayrollParameterIndex::class, '__invoke'])->name('index');
    });

    // Читачки: PDF-от веќе прашува Gate::authorize('view', $company) внатре,
    // internal_client го гледа сопствениот платопис/рекапитулар. Мора да
    // остане пред payroll-runs. подолу — /payroll-runs/{run}/recap.pdf не
    // смее да се проголта од /payroll-runs/{run}.
    Route::middleware(['auth', EnsureLegalEntity::class, EnsureCompanyModule::class.':payroll'])->prefix('companies/{company}')->name('payroll.')->group(function () {
        Route::get('/payroll-runs/{run}/recap.pdf', PayrollRecapPdfController::class)->name('recap-pdf');
        Route::get('/payroll-runs/{run}/payslip/{runEmployee}.pdf', PayslipPdfController::class)->name('payslip-pdf');
    });

    // МПИН извозот пишува во run (mpin_exported_at) и е канцелариска задача
    // — останува затворено.
    Route::middleware(['auth', EnsureAccountingAccess::class, EnsureLegalEntity::class, EnsureCompanyModule::class.':payroll'])->prefix('companies/{company}')->name('payroll.')->group(function () {
        Route::get('/payroll-runs/{run}/mpin.xml', MpinExportController::class)->name('mpin-export');
    });

    // Читачки: mount() веќе прашува Gate::authorize('view', $company).
    // Секое дејство што пишува (createRun, saveLine, deleteLine, confirm,
    // returnToDraft) си носи сопствена CompanyPolicy::managePayroll проверка
    // однатре — EnsureAccountingAccess веќе не се повторува на овие дејства
    // штом ја нема во почетната рута.
    Route::middleware(['auth', EnsureLegalEntity::class, EnsureCompanyModule::class.':payroll'])->prefix('companies/{company}')->name('payroll-runs.')->group(function () {
        Route::get('/payroll-runs', [PayrollRunIndex::class, '__invoke'])->name('index');
        Route::get('/payroll-runs/{run}', [PayrollRunShow::class, '__invoke'])->name('show');
    });
});

require __DIR__.'/auth.php';
