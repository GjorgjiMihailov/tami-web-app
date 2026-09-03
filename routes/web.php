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
use App\Http\Controllers\PayrollRecapPdfController;
use App\Http\Controllers\PayslipPdfController;
use App\Http\Controllers\SalesInvoicePdfController;
use App\Http\Controllers\StockOnHandPdfController;
use App\Http\Middleware\EnsureAccountingAccess;
use App\Http\Middleware\EnsureIndividual;
use App\Http\Middleware\EnsureLegalEntity;
use App\Livewire\Accounting\AccountIndex;
use App\Livewire\Accounting\JournalEntryForm;
use App\Livewire\Accounting\JournalEntryIndex;
use App\Livewire\Accounting\JournalGroupIndex;
use App\Livewire\Accounting\LedgerCardReport;
use App\Livewire\Accounting\TrialBalanceReport;
use App\Livewire\Bank\BankStatementIndex;
use App\Livewire\Bank\Form743Upload;
use App\Livewire\Bank\Form743Worklist;
use App\Livewire\ComingSoon;
use App\Livewire\CompanyDashboard;
use App\Livewire\CompanyIndex;
use App\Livewire\CompanyProfile;
use App\Livewire\Costs\OtherCostIndex;
use App\Livewire\Dashboard;
use App\Livewire\DocumentIndex;
use App\Livewire\EfakturaAccessRequests;
use App\Livewire\EmployeeForm;
use App\Livewire\EmployeeIndex;
use App\Livewire\Inventory\ItemBulkImport;
use App\Livewire\Inventory\ItemIndex;
use App\Livewire\Inventory\ItemMovementCardReport;
use App\Livewire\Inventory\StockMovementForm;
use App\Livewire\Inventory\StockOnHandReport;
use App\Livewire\Inventory\StockValuationReport;
use App\Livewire\Inventory\WarehouseIndex;
use App\Livewire\Invoicing\PurchaseInvoiceForm;
use App\Livewire\Invoicing\PurchaseInvoiceIndex;
use App\Livewire\Invoicing\PurchaseInvoiceShow;
use App\Livewire\Invoicing\SalesInvoiceForm;
use App\Livewire\Invoicing\SalesInvoiceIndex;
use App\Livewire\Invoicing\SalesInvoiceShow;
use App\Livewire\PartnerIndex;
use App\Livewire\PartnerShow;
use App\Livewire\Payroll\PayrollRunIndex;
use App\Livewire\Payroll\PayrollRunShow;
use App\Livewire\PayrollParameterIndex;
use App\Livewire\Reports\Ddv04Report;
use App\Livewire\Reports\ReportIndex;
use Illuminate\Support\Facades\Route;

Route::view('/', 'welcome');

Route::get('dashboard', [Dashboard::class, '__invoke'])
    ->middleware(['auth', 'verified'])
    ->name('dashboard');

Route::view('profile', 'profile')
    ->middleware(['auth'])
    ->name('profile');

Route::middleware(['auth'])->group(function () {
    Route::get('/companies', CompanyIndex::class)->name('companies.index');
});

// Array-callable form (not bare class-string) for the same reason noted
// below for the accounting.* group: avoids an eager method_exists() check
// at route registration time.
Route::middleware(['auth'])->get('/efaktura/access-requests', [EfakturaAccessRequests::class, '__invoke'])->name('efaktura.access-requests');

Route::middleware(['auth'])->prefix('companies/{company}')->group(function () {
    Route::get('/dashboard', [CompanyDashboard::class, '__invoke'])->name('companies.dashboard');
    Route::get('/profile', [CompanyProfile::class, '__invoke'])->name('companies.profile');
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
Route::middleware(['auth', EnsureAccountingAccess::class, EnsureLegalEntity::class])->prefix('companies/{company}')->name('accounting.')->group(function () {
    Route::get('/accounts', [AccountIndex::class, '__invoke'])->name('accounts.index');
    Route::get('/journal-groups', [JournalGroupIndex::class, '__invoke'])->name('journal-groups.index');
    Route::get('/journal-groups/{journalGroup}/entries', [JournalEntryIndex::class, '__invoke'])->name('journal-groups.entries');
    Route::get('/journal-entries', [JournalEntryIndex::class, '__invoke'])->name('journal-entries.index');
    Route::get('/journal-entries/create', [JournalEntryForm::class, '__invoke'])->name('journal-entries.create');
    Route::get('/journal-entries/{journalEntry}/edit', [JournalEntryForm::class, '__invoke'])->name('journal-entries.edit');
    Route::get('/journal-entries/{journalEntry}/pdf', [JournalEntryPdfController::class, '__invoke'])->name('journal-entries.pdf');
    Route::get('/reports/ledger-card', [LedgerCardReport::class, '__invoke'])->name('reports.ledger-card');
    Route::get('/reports/trial-balance', [TrialBalanceReport::class, '__invoke'])->name('reports.trial-balance');
});

// Array-callable form (not bare class-string) for the same reason as the
// accounting.* group above. (Historically some of these target classes
// didn't exist yet during earlier Inventory tasks, which would have
// crashed route registration with a bare class-string; all classes in
// this group exist now.)
Route::middleware(['auth', EnsureLegalEntity::class])->prefix('companies/{company}')->name('inventory.')->group(function () {
    Route::get('/warehouses', [WarehouseIndex::class, '__invoke'])->name('warehouses.index');
    Route::get('/items', [ItemIndex::class, '__invoke'])->name('items.index');
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
    Route::get('/partners/{partner}', [PartnerShow::class, '__invoke'])->name('show');
});

// Array-callable form (not bare class-string) for the same reason as the
// accounting.* group above: EmployeeIndex and EmployeeForm don't exist until
// Tasks 8 and 9, and a bare class-string would crash route registration
// immediately.
Route::middleware(['auth', EnsureLegalEntity::class])->prefix('companies/{company}')->name('employees.')->group(function () {
    Route::get('/employees', [EmployeeIndex::class, '__invoke'])->name('index');
    Route::get('/employees/create', [EmployeeForm::class, '__invoke'])->name('create');
    Route::get('/employees/{employee}/edit', [EmployeeForm::class, '__invoke'])->name('edit');
});

Route::middleware(['auth', EnsureLegalEntity::class])->prefix('companies/{company}')->name('payroll-parameters.')->group(function () {
    Route::get('/payroll-parameters', [PayrollParameterIndex::class, '__invoke'])->name('index');
});

// Registered before payroll-runs. below so /payroll-runs/{run}/recap.pdf is
// not swallowed by /payroll-runs/{run}.
Route::middleware(['auth', EnsureAccountingAccess::class, EnsureLegalEntity::class])->prefix('companies/{company}')->name('payroll.')->group(function () {
    Route::get('/payroll-runs/{run}/recap.pdf', PayrollRecapPdfController::class)->name('recap-pdf');
    Route::get('/payroll-runs/{run}/payslip/{runEmployee}.pdf', PayslipPdfController::class)->name('payslip-pdf');
    Route::get('/payroll-runs/{run}/mpin.xml', MpinExportController::class)->name('mpin-export');
});

// EnsureAccountingAccess, not a policy: payroll is the firm's work, not the
// client's, and a group-level gate covers screens added later by default
// instead of by remembering.
Route::middleware(['auth', EnsureAccountingAccess::class, EnsureLegalEntity::class])->prefix('companies/{company}')->name('payroll-runs.')->group(function () {
    Route::get('/payroll-runs', [PayrollRunIndex::class, '__invoke'])->name('index');
    Route::get('/payroll-runs/{run}', [PayrollRunShow::class, '__invoke'])->name('show');
});

// Array-callable form (not bare class-string) for the same reason as the
// accounting.* and inventory.* groups above: four of these five target
// classes don't exist until later Invoicing tasks, and a bare class-string
// would crash route registration immediately.
Route::middleware(['auth'])->prefix('companies/{company}')->name('sales-invoices.')->group(function () {
    Route::get('/sales-invoices', [SalesInvoiceIndex::class, '__invoke'])->name('index');
    Route::get('/sales-invoices/create', [SalesInvoiceForm::class, '__invoke'])->name('create');
    Route::get('/sales-invoices/{salesInvoice}/edit', [SalesInvoiceForm::class, '__invoke'])->name('edit');
    Route::get('/sales-invoices/{salesInvoice}', [SalesInvoiceShow::class, '__invoke'])->name('show');
    Route::get('/sales-invoices/{salesInvoice}/pdf', [SalesInvoicePdfController::class, '__invoke'])->name('pdf');
});

Route::middleware(['auth'])->prefix('companies/{company}/sales-invoices/{salesInvoice}')->name('sales-invoices.efaktura.')->group(function () {
    Route::post('/efaktura/signing-input', [EfakturaSendController::class, 'signingInput'])->name('signing-input');
    Route::post('/efaktura/send', [EfakturaSendController::class, 'send'])->name('send');
    Route::post('/efaktura/pdf/signing-input', [EfakturaPdfController::class, 'signingInput'])->name('pdf.signing-input');
    Route::post('/efaktura/pdf', [EfakturaPdfController::class, 'store'])->name('pdf.store');
    Route::get('/efaktura/pdf/download', [EfakturaPdfController::class, 'download'])->name('pdf.download');
});

Route::middleware(['auth'])->prefix('companies/{company}/sales-invoices')->name('sales-invoices.efaktura.')->group(function () {
    Route::post('/efaktura/refresh-statuses/signing-input', [EfakturaStatusController::class, 'signingInput'])->name('refresh-statuses.signing-input');
    Route::post('/efaktura/refresh-statuses', [EfakturaStatusController::class, 'refresh'])->name('refresh-statuses');
});

// Array-callable form (not bare class-string) for the same reason as the
// accounting.*, inventory.*, and sales-invoices.* groups above: four of
// these five target classes don't exist until later Purchase Invoicing
// tasks, and a bare class-string would crash route registration immediately.
Route::middleware(['auth', EnsureLegalEntity::class])->prefix('companies/{company}')->name('purchase-invoices.')->group(function () {
    Route::get('/purchase-invoices', [PurchaseInvoiceIndex::class, '__invoke'])->name('index');
    Route::get('/purchase-invoices/create', [PurchaseInvoiceForm::class, '__invoke'])->name('create');
    Route::get('/purchase-invoices/{purchaseInvoice}/edit', [PurchaseInvoiceForm::class, '__invoke'])->name('edit');
    Route::get('/purchase-invoices/{purchaseInvoice}', [PurchaseInvoiceShow::class, '__invoke'])->name('show');
});

Route::middleware(['auth', EnsureLegalEntity::class])->prefix('companies/{company}/incoming-efaktura')->name('incoming-efaktura.')->group(function () {
    Route::post('/discover/ids/signing-input', [EfakturaIncomingDiscoveryController::class, 'idsSigningInput'])->name('discover.ids.signing-input');
    Route::post('/discover/ids', [EfakturaIncomingDiscoveryController::class, 'ids'])->name('discover.ids');
    Route::post('/discover/payload/signing-input', [EfakturaIncomingDiscoveryController::class, 'payloadSigningInput'])->name('discover.payload.signing-input');
    Route::post('/discover/payload', [EfakturaIncomingDiscoveryController::class, 'payload'])->name('discover.payload');
    Route::post('/discover/status/signing-input', [EfakturaIncomingDiscoveryController::class, 'statusSigningInput'])->name('discover.status.signing-input');
    Route::post('/discover/status', [EfakturaIncomingDiscoveryController::class, 'status'])->name('discover.status');
});

Route::middleware(['auth', EnsureLegalEntity::class])->prefix('companies/{company}/incoming-efaktura/{incomingEfakturaDocument}')->name('incoming-efaktura.')->group(function () {
    Route::post('/accept/signing-input', [EfakturaIncomingAcceptController::class, 'signingInput'])->name('accept.signing-input');
    Route::post('/accept', [EfakturaIncomingAcceptController::class, 'store'])->name('accept');
    Route::post('/reject/signing-input', [EfakturaIncomingRejectController::class, 'signingInput'])->name('reject.signing-input');
    Route::post('/reject', [EfakturaIncomingRejectController::class, 'store'])->name('reject');
    Route::post('/pdf/signing-input', [EfakturaIncomingPdfController::class, 'signingInput'])->name('pdf.signing-input');
    Route::post('/pdf', [EfakturaIncomingPdfController::class, 'store'])->name('pdf.store');
    Route::get('/pdf/download', [EfakturaIncomingPdfController::class, 'download'])->name('pdf.download');
});

Route::middleware(['auth'])->prefix('companies/{company}')->group(function () {
    Route::get('/naskoro/{feature}', [ComingSoon::class, '__invoke'])->name('coming-soon');
});

// Работниот список е на канцеларијата и ги собира обрасците од сите клиенти,
// па намерно стои надвор од `companies/{company}`.
Route::middleware(['auth'])->get('/743-obrasci', [Form743Worklist::class, '__invoke'])->name('form743.worklist');

// 743 обрасците се на физичко лице, па оваа група стои надвор од `documents.`
// и носи сопствена брана — огледалото на `EnsureLegalEntity`.
Route::middleware(['auth', EnsureIndividual::class])->prefix('companies/{company}')->name('form743.')->group(function () {
    Route::get('/743', [Form743Upload::class, '__invoke'])->name('index');
    Route::get('/743/{form743}/download', [Form743DocumentController::class, '__invoke'])->name('download');
});

// Документите тука се сметководствени прилози на фирма (влезни фактури, изводи,
// договори), па групата намерно е затворена за профил на физичко лице.
//
// Фаза Б додава прикачување на 743 обрасци токму за физички лица. Тоа НЕ значи
// дека EnsureLegalEntity се тргнува од оваа група — 743 обрасците излегуваат од
// неа во сопствена група со сопствена рута, а оваа останува каква што е.
Route::middleware(['auth', EnsureLegalEntity::class])->prefix('companies/{company}')->name('other-costs.')->group(function () {
    Route::get('/drugi-trosoci', [OtherCostIndex::class, '__invoke'])->name('index');
});

Route::middleware(['auth', EnsureLegalEntity::class])->prefix('companies/{company}')->name('bank-statements.')->group(function () {
    Route::get('/izvodi', [BankStatementIndex::class, '__invoke'])->name('index');
});

Route::middleware(['auth', EnsureLegalEntity::class])->prefix('companies/{company}')->name('documents.')->group(function () {
    Route::get('/documents', [DocumentIndex::class, '__invoke'])->name('index');
    Route::get('/documents/{document}', [DocumentController::class, '__invoke'])->name('download');
});

Route::middleware(['auth', EnsureAccountingAccess::class, EnsureLegalEntity::class])->prefix('companies/{company}')->name('reports.')->group(function () {
    Route::get('/reports', [ReportIndex::class, '__invoke'])->name('index');
    Route::get('/reports/ddv04', [Ddv04Report::class, '__invoke'])->name('ddv04');
});

require __DIR__.'/auth.php';
