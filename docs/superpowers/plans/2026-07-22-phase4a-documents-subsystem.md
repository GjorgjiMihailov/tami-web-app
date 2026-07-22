# Phase 4a: Documents Subsystem Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add a polymorphic document/attachment system (upload, list, download, soft-delete) usable on purchase invoices, sales invoices, journal entries, and partners, plus a company-wide Documents browser — replacing Phase 3b's single-purpose `source_document_path` column.

**Architecture:** One `documents` table with a `documentable_type`/`documentable_id` polymorphic pair (Laravel morph relation), a single reusable `DocumentManager` Livewire component embedded on each entity's detail page, a generic `DocumentController` for downloads, and a `DocumentIndex` page listing all documents for a company. No new policy class — authorization inherits the parent record's existing policy (`Gate::authorize('view'|'update', $documentable)`).

**Tech Stack:** Laravel 13 + Livewire 3 (existing app), `google` disk (Google Drive OAuth, already configured), no new package dependencies.

## Global Constraints

- Upload limit: 25MB per file, any file type.
- Categories are a fixed, app-level list (not a DB enum, matching this codebase's existing `string` status-column convention): `Invoice`, `Contract`, `Bank Statement`, `Receipt`, `ID/Registration`, `Other`.
- Storage path convention: `documents/{company_id}/{documentable_type}/{documentable_id}/{document_id}_{original_filename}` on the `google` disk.
- Deletion is soft (`deleted_at`), never hard — the underlying Drive file is not removed.
- Permissions inherit the parent record's existing policy (`PurchaseInvoicePolicy`, `SalesInvoicePolicy`, `JournalEntryPolicy`, `PartnerPolicy`) — no new `DocumentPolicy`.
- Morph map keys (registered in `AppServiceProvider`): `purchase_invoice`, `sales_invoice`, `journal_entry`, `partner`.
- Route model binding + array-callable `[ClassName::class, '__invoke']` route registration, matching this codebase's established convention (bare class-strings crash route registration for not-yet-existing classes).

---

### Task 1: Documents table, `Document` model, morph map, entity relations

**Files:**
- Create: `database/migrations/2026_07_22_090000_create_documents_table.php`
- Create: `app/Models/Document.php`
- Create: `database/factories/DocumentFactory.php`
- Modify: `app/Providers/AppServiceProvider.php`
- Modify: `app/Models/PurchaseInvoice.php`
- Modify: `app/Models/SalesInvoice.php`
- Modify: `app/Models/JournalEntry.php`
- Modify: `app/Models/Partner.php`
- Test: `tests/Feature/DocumentTest.php`

**Interfaces:**
- Produces: `App\Models\Document` with `documentable(): MorphTo`, `company(): BelongsTo`, `uploader(): BelongsTo` (FK `uploaded_by`), constant `Document::CATEGORIES` (array of 6 strings), soft deletes via `deleted_at`.
- Produces: `documents(): MorphMany` on `PurchaseInvoice`, `SalesInvoice`, `JournalEntry`, `Partner`.
- Produces: morph map registered globally — `documentable_type` stores `purchase_invoice`/`sales_invoice`/`journal_entry`/`partner`, not FQCNs.

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/DocumentTest.php`:

```php
<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Document;
use App\Models\JournalEntry;
use App\Models\Partner;
use App\Models\PurchaseInvoice;
use App\Models\SalesInvoice;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DocumentTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_document_morphs_to_a_purchase_invoice(): void
    {
        $company = Company::factory()->create();
        $invoice = PurchaseInvoice::factory()->for($company)->create();

        $document = Document::factory()->for($invoice, 'documentable')->create(['company_id' => $company->id]);

        $this->assertInstanceOf(PurchaseInvoice::class, $document->documentable);
        $this->assertSame($invoice->id, $document->documentable->id);
        $this->assertSame('purchase_invoice', $document->documentable_type);
        $this->assertTrue($invoice->documents->contains($document));
    }

    public function test_a_document_morphs_to_a_sales_invoice(): void
    {
        $company = Company::factory()->create();
        $invoice = SalesInvoice::factory()->for($company)->create();

        $document = Document::factory()->for($invoice, 'documentable')->create(['company_id' => $company->id]);

        $this->assertSame('sales_invoice', $document->documentable_type);
        $this->assertTrue($invoice->documents->contains($document));
    }

    public function test_a_document_morphs_to_a_journal_entry(): void
    {
        $company = Company::factory()->create();
        $entry = JournalEntry::factory()->for($company)->create();

        $document = Document::factory()->for($entry, 'documentable')->create(['company_id' => $company->id]);

        $this->assertSame('journal_entry', $document->documentable_type);
        $this->assertTrue($entry->documents->contains($document));
    }

    public function test_a_document_morphs_to_a_partner(): void
    {
        $company = Company::factory()->create();
        $partner = Partner::factory()->for($company)->create();

        $document = Document::factory()->for($partner, 'documentable')->create(['company_id' => $company->id]);

        $this->assertSame('partner', $document->documentable_type);
        $this->assertTrue($partner->documents->contains($document));
    }

    public function test_a_soft_deleted_document_is_excluded_from_the_documentable_relation(): void
    {
        $company = Company::factory()->create();
        $invoice = PurchaseInvoice::factory()->for($company)->create();
        $document = Document::factory()->for($invoice, 'documentable')->create(['company_id' => $company->id]);

        $document->delete();

        $this->assertCount(0, $invoice->documents()->get());
        $this->assertNotNull($document->fresh()->deleted_at);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --filter=DocumentTest`
Expected: FAIL — `Class "App\Models\Document" not found` (or table `documents` not found).

- [ ] **Step 3: Create the migration**

Create `database/migrations/2026_07_22_090000_create_documents_table.php`:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('documents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->string('documentable_type');
            $table->unsignedBigInteger('documentable_id');
            $table->string('category', 30);
            $table->string('note')->nullable();
            $table->string('path');
            $table->string('original_filename');
            $table->string('mime_type')->nullable();
            $table->unsignedBigInteger('size')->default(0);
            $table->foreignId('uploaded_by')->constrained('users');
            $table->timestamps();
            $table->softDeletes();

            $table->index(['documentable_type', 'documentable_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('documents');
    }
};
```

- [ ] **Step 4: Create the `Document` model**

Create `app/Models/Document.php`:

```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class Document extends Model
{
    use HasFactory;
    use SoftDeletes;

    public const CATEGORIES = ['Invoice', 'Contract', 'Bank Statement', 'Receipt', 'ID/Registration', 'Other'];

    protected $fillable = [
        'company_id', 'documentable_type', 'documentable_id', 'category',
        'note', 'path', 'original_filename', 'mime_type', 'size', 'uploaded_by',
    ];

    public function documentable(): MorphTo
    {
        return $this->morphTo();
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }
}
```

- [ ] **Step 5: Create the `Document` factory**

Create `database/factories/DocumentFactory.php`:

```php
<?php

namespace Database\Factories;

use App\Models\Company;
use App\Models\Document;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

class DocumentFactory extends Factory
{
    protected $model = Document::class;

    public function definition(): array
    {
        return [
            'company_id' => Company::factory(),
            'category' => 'Other',
            'note' => null,
            'path' => 'documents/test/'.$this->faker->uuid().'.pdf',
            'original_filename' => $this->faker->word().'.pdf',
            'mime_type' => 'application/pdf',
            'size' => 1024,
            'uploaded_by' => User::factory(),
        ];
    }
}
```

- [ ] **Step 6: Register the morph map**

Modify `app/Providers/AppServiceProvider.php` (full file):

```php
<?php

namespace App\Providers;

use App\Models\Company;
use App\Models\JournalEntry;
use App\Models\Partner;
use App\Models\PurchaseInvoice;
use App\Models\SalesInvoice;
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
        ]);
    }
}
```

- [ ] **Step 7: Add the `documents()` relation to the four entity models**

Modify `app/Models/PurchaseInvoice.php` — add the import and method (after `payments()`):

```php
use Illuminate\Database\Eloquent\Relations\MorphMany;
```

```php
    public function documents(): MorphMany
    {
        return $this->morphMany(Document::class, 'documentable');
    }
```

Modify `app/Models/SalesInvoice.php` — same import and method, added after its `payments()`.

Modify `app/Models/JournalEntry.php` — add the import and method (after `creator()`):

```php
use Illuminate\Database\Eloquent\Relations\MorphMany;
```

```php
    public function documents(): MorphMany
    {
        return $this->morphMany(Document::class, 'documentable');
    }
```

Modify `app/Models/Partner.php` (full file):

```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphMany;

class Partner extends Model
{
    use HasFactory;

    protected $fillable = ['company_id', 'name', 'tax_id', 'email', 'phone', 'address'];

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function documents(): MorphMany
    {
        return $this->morphMany(Document::class, 'documentable');
    }
}
```

- [ ] **Step 8: Run migrations**

Run: `php artisan migrate`
Expected: `2026_07_22_090000_create_documents_table` migrates successfully.

- [ ] **Step 9: Run test to verify it passes**

Run: `php artisan test --filter=DocumentTest`
Expected: PASS (5 tests).

- [ ] **Step 10: Commit**

```bash
git add database/migrations/2026_07_22_090000_create_documents_table.php app/Models/Document.php database/factories/DocumentFactory.php app/Providers/AppServiceProvider.php app/Models/PurchaseInvoice.php app/Models/SalesInvoice.php app/Models/JournalEntry.php app/Models/Partner.php tests/Feature/DocumentTest.php
git commit -m "Add polymorphic Document model, morph map, and entity relations"
```

---

### Task 2: `DocumentManager` component, `DocumentController`, migrate Phase 3b data, wire into Purchase Invoices

**Files:**
- Create: `app/Livewire/DocumentManager.php`
- Create: `resources/views/livewire/document-manager.blade.php`
- Create: `app/Http/Controllers/DocumentController.php`
- Create: `database/migrations/2026_07_22_090100_migrate_purchase_invoice_source_documents.php`
- Create: `database/migrations/2026_07_22_090200_drop_source_document_path_from_purchase_invoices_table.php`
- Modify: `routes/web.php`
- Modify: `app/Models/PurchaseInvoice.php` (remove `source_document_path` from `$fillable`)
- Modify: `database/factories/PurchaseInvoiceFactory.php`
- Modify: `app/Livewire/Invoicing/PurchaseInvoiceForm.php`
- Modify: `resources/views/livewire/invoicing/purchase-invoice-form.blade.php`
- Modify: `resources/views/livewire/invoicing/purchase-invoice-show.blade.php`
- Modify: `tests/Feature/PurchaseInvoiceFormTest.php`
- Modify: `tests/Feature/PurchaseInvoiceShowTest.php`
- Delete: `app/Http/Controllers/PurchaseInvoiceDocumentController.php`
- Test: `tests/Feature/DocumentManagerTest.php`
- Test: `tests/Feature/PurchaseInvoiceSourceDocumentMigrationTest.php`

**Interfaces:**
- Consumes: `Document::CATEGORIES` (Task 1), `documents()` on `PurchaseInvoice` (Task 1).
- Produces: `App\Livewire\DocumentManager` (public property `Model $documentable`; methods `upload()`, `delete(int $documentId)`; renders `livewire.document-manager` with `documents` and `categories` view data).
- Produces: `App\Http\Controllers\DocumentController::__invoke(Company $company, Document $document)`.
- Produces: route `documents.download` (`/companies/{company}/documents/{document}`), embeddable via `<livewire:document-manager :documentable="$record" />`.

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/DocumentManagerTest.php`:

```php
<?php

namespace Tests\Feature;

use App\Livewire\DocumentManager;
use App\Models\Company;
use App\Models\Document;
use App\Models\PurchaseInvoice;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class DocumentManagerTest extends TestCase
{
    use RefreshDatabase;

    public function setUp(): void
    {
        parent::setUp();
        Role::findOrCreate('admin');
        Role::findOrCreate('accountant');
        Role::findOrCreate('client');
    }

    public function test_uploading_a_document_attaches_it_to_the_purchase_invoice(): void
    {
        Storage::fake('google');
        $company = Company::factory()->create();
        $invoice = PurchaseInvoice::factory()->for($company)->create();
        $admin = User::factory()->create();
        $admin->assignRole('admin');
        $this->actingAs($admin);

        Livewire::test(DocumentManager::class, ['documentable' => $invoice])
            ->set('newFile', UploadedFile::fake()->create('bill.pdf', 50))
            ->set('newCategory', 'Invoice')
            ->set('newNote', 'Supplier scan')
            ->call('upload')
            ->assertHasNoErrors();

        $this->assertDatabaseHas('documents', [
            'company_id' => $company->id,
            'documentable_type' => 'purchase_invoice',
            'documentable_id' => $invoice->id,
            'category' => 'Invoice',
            'note' => 'Supplier scan',
        ]);
        $document = Document::where('documentable_id', $invoice->id)->firstOrFail();
        Storage::disk('google')->assertExists($document->path);
    }

    public function test_a_client_cannot_view_another_companys_purchase_invoice_documents(): void
    {
        $ownCompany = Company::factory()->create();
        $otherCompany = Company::factory()->create();
        $invoice = PurchaseInvoice::factory()->for($otherCompany)->create();
        $client = User::factory()->create(['company_id' => $ownCompany->id]);
        $client->assignRole('client');
        $this->actingAs($client);

        Livewire::test(DocumentManager::class, ['documentable' => $invoice])
            ->assertForbidden();
    }

    public function test_deleting_a_document_soft_deletes_it_and_hides_it_from_the_list(): void
    {
        Storage::fake('google');
        $company = Company::factory()->create();
        $invoice = PurchaseInvoice::factory()->for($company)->create();
        $document = Document::factory()->for($invoice, 'documentable')->create(['company_id' => $company->id]);
        $admin = User::factory()->create();
        $admin->assignRole('admin');
        $this->actingAs($admin);

        Livewire::test(DocumentManager::class, ['documentable' => $invoice])
            ->call('delete', $document->id)
            ->assertHasNoErrors();

        $this->assertSoftDeleted('documents', ['id' => $document->id]);
        $this->assertSame(0, $invoice->documents()->count());
    }

    public function test_downloading_a_document_requires_view_permission_on_its_parent(): void
    {
        Storage::fake('google');
        $ownCompany = Company::factory()->create();
        $otherCompany = Company::factory()->create();
        $invoice = PurchaseInvoice::factory()->for($otherCompany)->create();
        $document = Document::factory()->for($invoice, 'documentable')->create(['company_id' => $otherCompany->id, 'path' => 'documents/test/bill.pdf']);
        Storage::disk('google')->put($document->path, 'fake-pdf-content');
        $client = User::factory()->create(['company_id' => $ownCompany->id]);
        $client->assignRole('client');
        $this->actingAs($client);

        $this->get(route('documents.download', [$otherCompany, $document]))->assertForbidden();
    }

    public function test_downloading_a_document_succeeds_for_an_authorized_user(): void
    {
        Storage::fake('google');
        $company = Company::factory()->create();
        $invoice = PurchaseInvoice::factory()->for($company)->create();
        $document = Document::factory()->for($invoice, 'documentable')->create(['company_id' => $company->id, 'path' => 'documents/test/bill.pdf']);
        Storage::disk('google')->put($document->path, 'fake-pdf-content');
        $admin = User::factory()->create();
        $admin->assignRole('admin');

        $response = $this->actingAs($admin)->get(route('documents.download', [$company, $document]));

        $response->assertOk();
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --filter=DocumentManagerTest`
Expected: FAIL — `Class "App\Livewire\DocumentManager" not found`.

- [ ] **Step 3: Create the `DocumentManager` Livewire component**

Create `app/Livewire/DocumentManager.php`:

```php
<?php

namespace App\Livewire;

use App\Models\Document;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Livewire\Component;
use Livewire\WithFileUploads;

class DocumentManager extends Component
{
    use WithFileUploads;

    public Model $documentable;

    public $newFile = null;

    public string $newCategory = 'Other';

    public string $newNote = '';

    public function mount(Model $documentable): void
    {
        Gate::authorize('view', $documentable);

        $this->documentable = $documentable;
    }

    public function upload(): void
    {
        Gate::authorize('update', $this->documentable);

        $this->validate([
            'newFile' => 'required|file|max:25600',
            'newCategory' => ['required', Rule::in(Document::CATEGORIES)],
            'newNote' => 'nullable|string|max:255',
        ]);

        $document = new Document([
            'company_id' => $this->documentable->company_id,
            'category' => $this->newCategory,
            'note' => $this->newNote ?: null,
            'original_filename' => $this->newFile->getClientOriginalName(),
            'mime_type' => $this->newFile->getMimeType(),
            'size' => $this->newFile->getSize(),
            'uploaded_by' => auth()->id(),
        ]);
        $document->documentable()->associate($this->documentable);
        $document->save();

        $document->path = $this->newFile->storeAs(
            "documents/{$this->documentable->company_id}/{$document->documentable_type}/{$this->documentable->id}",
            "{$document->id}_{$document->original_filename}",
            'google'
        );
        $document->save();

        $this->reset(['newFile', 'newNote']);
        $this->newCategory = 'Other';
    }

    public function delete(int $documentId): void
    {
        Gate::authorize('update', $this->documentable);

        $this->documentable->documents()->findOrFail($documentId)->delete();
    }

    public function render()
    {
        return view('livewire.document-manager', [
            'documents' => $this->documentable->documents()->with('uploader')->latest()->get(),
            'categories' => Document::CATEGORIES,
        ]);
    }
}
```

- [ ] **Step 4: Create the `DocumentManager` view**

Create `resources/views/livewire/document-manager.blade.php`:

```blade
<div class="bg-white shadow rounded-md p-4 mt-4">
    <h2 class="font-semibold text-gray-700 mb-2">Documents</h2>

    @can('update', $documentable)
        <form wire:submit="upload" class="flex flex-wrap gap-3 items-end mb-4">
            <div>
                <x-input-label for="newFile" value="File" />
                <input type="file" id="newFile" wire:model="newFile" class="text-sm">
                @error('newFile') <span class="text-red-600 text-sm">{{ $message }}</span> @enderror
            </div>
            <div>
                <x-input-label for="newCategory" value="Category" />
                <select id="newCategory" wire:model="newCategory" class="border-gray-300 rounded-md text-sm">
                    @foreach ($categories as $category)
                        <option value="{{ $category }}">{{ $category }}</option>
                    @endforeach
                </select>
            </div>
            <div class="flex-1 min-w-[12rem]">
                <x-input-label for="newNote" value="Note" />
                <x-text-input id="newNote" wire:model="newNote" class="w-full" />
            </div>
            <x-primary-button type="submit">Upload</x-primary-button>
        </form>
    @endcan

    <table class="min-w-full text-sm">
        <thead>
            <tr class="text-left text-gray-500">
                <th class="py-1">File</th>
                <th class="py-1">Category</th>
                <th class="py-1">Note</th>
                <th class="py-1">Uploaded by</th>
                <th class="py-1">Date</th>
                <th class="py-1"></th>
            </tr>
        </thead>
        <tbody>
            @forelse ($documents as $document)
                <tr>
                    <td class="py-1">
                        <a href="{{ route('documents.download', [$documentable->company_id, $document]) }}" class="text-indigo-600 hover:underline">
                            {{ $document->original_filename }}
                        </a>
                    </td>
                    <td class="py-1">{{ $document->category }}</td>
                    <td class="py-1">{{ $document->note }}</td>
                    <td class="py-1">{{ $document->uploader?->name }}</td>
                    <td class="py-1">{{ $document->created_at->toDateString() }}</td>
                    <td class="py-1">
                        @can('update', $documentable)
                            <button type="button" wire:click="delete({{ $document->id }})" wire:confirm="Delete this document?" class="text-red-600 text-sm">Delete</button>
                        @endcan
                    </td>
                </tr>
            @empty
                <tr><td colspan="6" class="py-2 text-gray-500">No documents attached.</td></tr>
            @endforelse
        </tbody>
    </table>
</div>
```

- [ ] **Step 5: Create the generic `DocumentController`**

Create `app/Http/Controllers/DocumentController.php`:

```php
<?php

namespace App\Http\Controllers;

use App\Models\Company;
use App\Models\Document;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;

class DocumentController extends Controller
{
    public function __invoke(Company $company, Document $document)
    {
        if ($document->company_id !== $company->id) {
            abort(404);
        }

        Gate::authorize('view', $document->documentable);

        return Storage::disk('google')->download($document->path, $document->original_filename);
    }
}
```

- [ ] **Step 6: Add the `documents` route group and remove the old purchase-invoice document route**

Modify `routes/web.php`. Remove the `use App\Http\Controllers\PurchaseInvoiceDocumentController;` import and add `use App\Http\Controllers\DocumentController;`. Remove this line from the `purchase-invoices.` group:

```php
    Route::get('/purchase-invoices/{purchaseInvoice}/document', [PurchaseInvoiceDocumentController::class, '__invoke'])->name('document');
```

Add a new route group (after the `purchase-invoices.` group, before `require __DIR__.'/auth.php';`):

```php
Route::middleware(['auth'])->prefix('companies/{company}')->name('documents.')->group(function () {
    Route::get('/documents/{document}', [DocumentController::class, '__invoke'])->name('download');
});
```

- [ ] **Step 7: Delete the old `PurchaseInvoiceDocumentController`**

Delete `app/Http/Controllers/PurchaseInvoiceDocumentController.php`.

- [ ] **Step 8: Migrate existing Phase 3b document data**

Create `database/migrations/2026_07_22_090100_migrate_purchase_invoice_source_documents.php`:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $invoices = DB::table('purchase_invoices')->whereNotNull('source_document_path')->get();

        foreach ($invoices as $invoice) {
            DB::table('documents')->insert([
                'company_id' => $invoice->company_id,
                'documentable_type' => 'purchase_invoice',
                'documentable_id' => $invoice->id,
                'category' => 'Invoice',
                'note' => null,
                'path' => $invoice->source_document_path,
                'original_filename' => basename($invoice->source_document_path),
                'mime_type' => null,
                'size' => 0,
                'uploaded_by' => $invoice->created_by,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        DB::table('documents')->where('documentable_type', 'purchase_invoice')->where('category', 'Invoice')->delete();
    }
};
```

Create `database/migrations/2026_07_22_090200_drop_source_document_path_from_purchase_invoices_table.php`:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('purchase_invoices', function (Blueprint $table) {
            $table->dropColumn('source_document_path');
        });
    }

    public function down(): void
    {
        Schema::table('purchase_invoices', function (Blueprint $table) {
            $table->string('source_document_path')->nullable();
        });
    }
};
```

Run: `php artisan migrate`
Expected: both migrations run successfully.

- [ ] **Step 9: Remove `source_document_path` from the `PurchaseInvoice` model and factory**

Modify `app/Models/PurchaseInvoice.php` — remove `'source_document_path'` from the `$fillable` array, leaving:

```php
    protected $fillable = [
        'company_id', 'partner_id', 'warehouse_id', 'journal_entry_id',
        'supplier_invoice_number', 'invoice_date', 'due_date',
        'status', 'notes', 'created_by',
    ];
```

Modify `database/factories/PurchaseInvoiceFactory.php` — remove the line `'source_document_path' => null,`.

- [ ] **Step 10: Remove the file-upload field from `PurchaseInvoiceForm`**

Modify `app/Livewire/Invoicing/PurchaseInvoiceForm.php`:
- Remove `use Livewire\WithFileUploads;` import and `use WithFileUploads;` trait line.
- Remove the property `public $sourceDocument = null;`.
- Remove `'sourceDocument' => 'nullable|file|max:10240',` from the `validate()` array in `save()`.
- Remove this block from inside the `DB::transaction()` closure in `save()`:

```php
            if ($this->sourceDocument) {
                $path = $this->sourceDocument->storeAs(
                    "purchase-invoices/{$this->company->id}/{$invoice->id}",
                    $this->sourceDocument->getClientOriginalName(),
                    'google'
                );
                $invoice->update(['source_document_path' => $path]);
            }

```

- [ ] **Step 11: Remove the file input from the purchase invoice form view**

Modify `resources/views/livewire/invoicing/purchase-invoice-form.blade.php` — remove this block:

```blade
            <div>
                <x-input-label for="sourceDocument" value="Attach supplier's bill (optional)" />
                <input type="file" id="sourceDocument" wire:model="sourceDocument" class="w-full text-sm">
                @error('sourceDocument') <span class="text-red-600 text-sm">{{ $message }}</span> @enderror
            </div>
```

- [ ] **Step 12: Wire `DocumentManager` into `PurchaseInvoiceShow`**

Modify `resources/views/livewire/invoicing/purchase-invoice-show.blade.php` — remove this block:

```blade
        @if ($invoice->source_document_path)
            <a href="{{ route('purchase-invoices.document', [$company, $invoice]) }}" class="text-indigo-600 hover:underline text-sm">Download original</a>
        @endif
```

Add this line at the end of the file, just before the final closing `</div>`:

```blade
    <livewire:document-manager :documentable="$invoice" />
```

- [ ] **Step 13: Update existing tests that referenced the old file-attach shortcut**

Modify `tests/Feature/PurchaseInvoiceFormTest.php` — in `test_it_creates_a_draft_purchase_invoice_with_an_expense_line`, remove `->set('sourceDocument', UploadedFile::fake()->create('bill.pdf', 50))` from the Livewire chain, and remove these two lines at the end of the test:

```php
        $invoice = \App\Models\PurchaseInvoice::where('supplier_invoice_number', 'SUP-2026-045')->firstOrFail();
        $this->assertNotNull($invoice->source_document_path);
        Storage::disk('google')->assertExists($invoice->source_document_path);
```

The `use Illuminate\Support\Facades\Storage;` import and `Storage::fake('google');` calls in other tests in this file can remain (they don't reference the removed field).

Modify `tests/Feature/PurchaseInvoiceShowTest.php` — remove the two tests `test_it_downloads_the_attached_source_document` and `test_document_download_requires_view_permission` (both exercised the now-removed `purchase-invoices.document` route; equivalent coverage now lives in `DocumentManagerTest`). The `use Illuminate\Support\Facades\Storage;` import can be removed since no remaining test in this file uses it.

- [ ] **Step 14: Write a test proving the data migration itself works**

Because `RefreshDatabase` runs every migration (including the Step 8 "drop column" migration) before each test, `purchase_invoices.source_document_path` no longer exists by the time any test runs — so this migration's copy logic must be tested in isolation: temporarily re-add the column, seed a raw row through it, run the migration's `up()` directly, assert the copy happened, then drop the column again so the schema matches its final state for the rest of the suite.

Create `tests/Feature/PurchaseInvoiceSourceDocumentMigrationTest.php`:

```php
<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Partner;
use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class PurchaseInvoiceSourceDocumentMigrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_migration_copies_source_document_path_into_a_document_row(): void
    {
        $company = Company::factory()->create();
        $partner = Partner::factory()->for($company)->create();
        $admin = User::factory()->create();

        Schema::table('purchase_invoices', function (Blueprint $table) {
            $table->string('source_document_path')->nullable();
        });

        $invoiceId = DB::table('purchase_invoices')->insertGetId([
            'company_id' => $company->id,
            'partner_id' => $partner->id,
            'supplier_invoice_number' => 'SUP-MIGRATE-1',
            'invoice_date' => now()->toDateString(),
            'due_date' => now()->toDateString(),
            'status' => 'draft',
            'source_document_path' => 'purchase-invoices/1/1/bill.pdf',
            'created_by' => $admin->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        (require database_path('migrations/2026_07_22_090100_migrate_purchase_invoice_source_documents.php'))->up();

        $this->assertDatabaseHas('documents', [
            'documentable_type' => 'purchase_invoice',
            'documentable_id' => $invoiceId,
            'path' => 'purchase-invoices/1/1/bill.pdf',
            'category' => 'Invoice',
        ]);

        // Restore final schema state (column-less) for the rest of the suite.
        Schema::table('purchase_invoices', function (Blueprint $table) {
            $table->dropColumn('source_document_path');
        });
    }
}
```

Run: `php artisan test --filter=PurchaseInvoiceSourceDocumentMigrationTest`
Expected: PASS.

- [ ] **Step 15: Run tests to verify everything passes**

Run: `php artisan test --filter=DocumentManagerTest`
Expected: PASS (5 tests).

Run: `php artisan test --filter=PurchaseInvoiceFormTest`
Expected: PASS.

Run: `php artisan test --filter=PurchaseInvoiceShowTest`
Expected: PASS (2 remaining tests).

Run: `php artisan test`
Expected: full suite PASS.

- [ ] **Step 16: Commit**

```bash
git add app/Livewire/DocumentManager.php resources/views/livewire/document-manager.blade.php app/Http/Controllers/DocumentController.php database/migrations/2026_07_22_090100_migrate_purchase_invoice_source_documents.php database/migrations/2026_07_22_090200_drop_source_document_path_from_purchase_invoices_table.php routes/web.php app/Models/PurchaseInvoice.php database/factories/PurchaseInvoiceFactory.php app/Livewire/Invoicing/PurchaseInvoiceForm.php resources/views/livewire/invoicing/purchase-invoice-form.blade.php resources/views/livewire/invoicing/purchase-invoice-show.blade.php tests/Feature/PurchaseInvoiceFormTest.php tests/Feature/PurchaseInvoiceShowTest.php tests/Feature/DocumentManagerTest.php tests/Feature/PurchaseInvoiceSourceDocumentMigrationTest.php
git add -u app/Http/Controllers/PurchaseInvoiceDocumentController.php
git commit -m "Add DocumentManager component and migrate Phase 3b purchase invoice documents"
```

---

### Task 3: Wire documents into Sales Invoices

**Files:**
- Modify: `resources/views/livewire/invoicing/sales-invoice-show.blade.php`
- Test: `tests/Feature/SalesInvoiceDocumentsTest.php`

**Interfaces:**
- Consumes: `App\Livewire\DocumentManager` (Task 2), `documents()` on `SalesInvoice` (Task 1).

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/SalesInvoiceDocumentsTest.php`:

```php
<?php

namespace Tests\Feature;

use App\Livewire\DocumentManager;
use App\Models\Company;
use App\Models\SalesInvoice;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class SalesInvoiceDocumentsTest extends TestCase
{
    use RefreshDatabase;

    public function setUp(): void
    {
        parent::setUp();
        Role::findOrCreate('admin');
        Role::findOrCreate('client');
    }

    public function test_a_client_can_upload_a_document_to_their_own_sales_invoice(): void
    {
        Storage::fake('google');
        $company = Company::factory()->create();
        $invoice = SalesInvoice::factory()->for($company)->create();
        $client = User::factory()->create(['company_id' => $company->id]);
        $client->assignRole('client');
        $this->actingAs($client);

        Livewire::test(DocumentManager::class, ['documentable' => $invoice])
            ->set('newFile', UploadedFile::fake()->create('delivery-note.pdf', 20))
            ->set('newCategory', 'Contract')
            ->call('upload')
            ->assertHasNoErrors();

        $this->assertDatabaseHas('documents', [
            'documentable_type' => 'sales_invoice',
            'documentable_id' => $invoice->id,
        ]);
    }

    public function test_the_show_page_renders_the_document_manager(): void
    {
        $company = Company::factory()->create();
        $invoice = SalesInvoice::factory()->for($company)->create();
        $admin = User::factory()->create();
        $admin->assignRole('admin');
        $this->actingAs($admin);

        $this->get(route('sales-invoices.show', [$company, $invoice]))
            ->assertOk()
            ->assertSeeLivewire('document-manager');
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --filter=SalesInvoiceDocumentsTest`
Expected: FAIL on `test_the_show_page_renders_the_document_manager` (component not embedded yet); the upload test passes already since `DocumentManager` and the `documents()` relation already exist from Tasks 1–2 (this is expected — it proves the reusable component works before wiring the view).

- [ ] **Step 3: Wire `DocumentManager` into `SalesInvoiceShow`**

Modify `resources/views/livewire/invoicing/sales-invoice-show.blade.php` — add this line just before the final closing `</div>`:

```blade
    <livewire:document-manager :documentable="$invoice" />
```

- [ ] **Step 4: Run test to verify it passes**

Run: `php artisan test --filter=SalesInvoiceDocumentsTest`
Expected: PASS (2 tests).

- [ ] **Step 5: Commit**

```bash
git add resources/views/livewire/invoicing/sales-invoice-show.blade.php tests/Feature/SalesInvoiceDocumentsTest.php
git commit -m "Wire DocumentManager into sales invoice show page"
```

---

### Task 4: Wire documents into Journal Entries (edit mode only)

**Files:**
- Modify: `resources/views/livewire/accounting/journal-entry-form.blade.php`
- Test: `tests/Feature/JournalEntryDocumentsTest.php`

**Interfaces:**
- Consumes: `App\Livewire\DocumentManager` (Task 2), `documents()` on `JournalEntry` (Task 1).

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/JournalEntryDocumentsTest.php`:

```php
<?php

namespace Tests\Feature;

use App\Livewire\DocumentManager;
use App\Models\Company;
use App\Models\JournalEntry;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class JournalEntryDocumentsTest extends TestCase
{
    use RefreshDatabase;

    public function setUp(): void
    {
        parent::setUp();
        Role::findOrCreate('admin');
        Role::findOrCreate('client');
    }

    public function test_a_client_cannot_upload_a_document_to_a_journal_entry(): void
    {
        $company = Company::factory()->create();
        $entry = JournalEntry::factory()->for($company)->create();
        $client = User::factory()->create(['company_id' => $company->id]);
        $client->assignRole('client');
        $this->actingAs($client);

        Livewire::test(DocumentManager::class, ['documentable' => $entry])
            ->call('upload')
            ->assertForbidden();
    }

    public function test_the_edit_page_for_an_existing_entry_renders_the_document_manager(): void
    {
        $company = Company::factory()->create();
        $entry = JournalEntry::factory()->for($company)->create();
        $admin = User::factory()->create();
        $admin->assignRole('admin');
        $this->actingAs($admin);

        $this->get(route('accounting.journal-entries.edit', [$company, $entry]))
            ->assertOk()
            ->assertSeeLivewire('document-manager');
    }

    public function test_the_new_entry_page_does_not_render_the_document_manager(): void
    {
        $company = Company::factory()->create();
        $admin = User::factory()->create();
        $admin->assignRole('admin');
        $this->actingAs($admin);

        $this->get(route('accounting.journal-entries.create', $company))
            ->assertOk()
            ->assertDontSeeLivewire('document-manager');
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --filter=JournalEntryDocumentsTest`
Expected: FAIL on the two page-rendering tests (component not embedded yet).

- [ ] **Step 3: Wire `DocumentManager` into `JournalEntryForm`, edit mode only**

Modify `resources/views/livewire/accounting/journal-entry-form.blade.php` — add this block just before the final closing `</div>` (after the closing `</form>` tag):

```blade
    @if ($journalEntry)
        <livewire:document-manager :documentable="$journalEntry" />
    @endif
```

- [ ] **Step 4: Run test to verify it passes**

Run: `php artisan test --filter=JournalEntryDocumentsTest`
Expected: PASS (3 tests).

- [ ] **Step 5: Commit**

```bash
git add resources/views/livewire/accounting/journal-entry-form.blade.php tests/Feature/JournalEntryDocumentsTest.php
git commit -m "Wire DocumentManager into journal entry edit page"
```

---

### Task 5: `PartnerShow` page and documents on Partners

**Files:**
- Create: `app/Livewire/PartnerShow.php`
- Create: `resources/views/livewire/partner-show.blade.php`
- Modify: `routes/web.php`
- Modify: `resources/views/livewire/partner-index.blade.php`
- Test: `tests/Feature/PartnerShowTest.php`

**Interfaces:**
- Consumes: `App\Livewire\DocumentManager` (Task 2), `documents()` on `Partner` (Task 1), `PartnerPolicy` (existing).
- Produces: route `partners.show` (`/companies/{company}/partners/{partner}`).

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/PartnerShowTest.php`:

```php
<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Partner;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class PartnerShowTest extends TestCase
{
    use RefreshDatabase;

    public function setUp(): void
    {
        parent::setUp();
        Role::findOrCreate('admin');
        Role::findOrCreate('client');
    }

    public function test_it_shows_the_partners_details_and_document_manager(): void
    {
        $company = Company::factory()->create();
        $partner = Partner::factory()->for($company)->create(['name' => 'Acme Supplies']);
        $admin = User::factory()->create();
        $admin->assignRole('admin');
        $this->actingAs($admin);

        $this->get(route('partners.show', [$company, $partner]))
            ->assertOk()
            ->assertSee('Acme Supplies')
            ->assertSeeLivewire('document-manager');
    }

    public function test_a_client_cannot_view_another_companys_partner(): void
    {
        $ownCompany = Company::factory()->create();
        $otherCompany = Company::factory()->create();
        $partner = Partner::factory()->for($otherCompany)->create();
        $client = User::factory()->create(['company_id' => $ownCompany->id]);
        $client->assignRole('client');
        $this->actingAs($client);

        $this->get(route('partners.show', [$otherCompany, $partner]))->assertForbidden();
    }

    public function test_the_partner_index_links_to_the_show_page(): void
    {
        $company = Company::factory()->create();
        $partner = Partner::factory()->for($company)->create();
        $admin = User::factory()->create();
        $admin->assignRole('admin');
        $this->actingAs($admin);

        $this->get(route('partners.index', $company))
            ->assertOk()
            ->assertSee(route('partners.show', [$company, $partner]), false);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --filter=PartnerShowTest`
Expected: FAIL — route `partners.show` not defined.

- [ ] **Step 3: Create the `PartnerShow` Livewire component**

Create `app/Livewire/PartnerShow.php`:

```php
<?php

namespace App\Livewire;

use App\Models\Company;
use App\Models\Partner;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Layout;
use Livewire\Component;

#[Layout('layouts.app')]
class PartnerShow extends Component
{
    public Company $company;

    public Partner $partner;

    public function mount(Company $company, Partner $partner): void
    {
        Gate::authorize('view', $partner);

        if ($partner->company_id !== $company->id) {
            abort(404);
        }

        $this->company = $company;
        $this->partner = $partner;
    }

    public function render()
    {
        return view('livewire.partner-show');
    }
}
```

- [ ] **Step 4: Create the `PartnerShow` view**

Create `resources/views/livewire/partner-show.blade.php`:

```blade
<div>
    <h1 class="text-2xl font-bold text-gray-800 mb-1">{{ $partner->name }}</h1>
    <p class="text-sm text-gray-500 mb-4">{{ $company->name }}</p>

    <div class="bg-white shadow rounded-md p-4 mb-4 text-sm space-y-1">
        <div>Tax ID: {{ $partner->tax_id ?? '—' }}</div>
        <div>Email: {{ $partner->email ?? '—' }}</div>
        <div>Phone: {{ $partner->phone ?? '—' }}</div>
        <div>Address: {{ $partner->address ?? '—' }}</div>
    </div>

    <livewire:document-manager :documentable="$partner" />
</div>
```

- [ ] **Step 5: Register the `partners.show` route**

Modify `routes/web.php` — add the import `use App\Livewire\PartnerShow;` and add this line inside the existing `partners.` group:

```php
    Route::get('/partners/{partner}', [PartnerShow::class, '__invoke'])->name('show');
```

- [ ] **Step 6: Link to the show page from the partner index**

Modify `resources/views/livewire/partner-index.blade.php` — add a new column header after `<th class="py-2 px-4">Phone</th>`:

```blade
                <th class="py-2 px-4"></th>
```

And add a new cell in the row loop, after the phone `<td>`:

```blade
                    <td class="py-2 px-4"><a href="{{ route('partners.show', [$company, $partner]) }}" class="text-indigo-600 hover:underline">Documents</a></td>
```

Also update the empty-state colspan from `colspan="4"` to `colspan="5"`.

- [ ] **Step 7: Run test to verify it passes**

Run: `php artisan test --filter=PartnerShowTest`
Expected: PASS (3 tests).

- [ ] **Step 8: Commit**

```bash
git add app/Livewire/PartnerShow.php resources/views/livewire/partner-show.blade.php routes/web.php resources/views/livewire/partner-index.blade.php tests/Feature/PartnerShowTest.php
git commit -m "Add PartnerShow page with document management"
```

---

### Task 6: Company-wide Documents browser

**Files:**
- Create: `app/Livewire/DocumentIndex.php`
- Create: `resources/views/livewire/document-index.blade.php`
- Modify: `routes/web.php`
- Modify: `resources/views/livewire/company-index.blade.php`
- Test: `tests/Feature/DocumentIndexTest.php`

**Interfaces:**
- Consumes: `Document::CATEGORIES` (Task 1), documents created in Tasks 2–5.
- Produces: route `documents.index` (`/companies/{company}/documents`).

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/DocumentIndexTest.php`:

```php
<?php

namespace Tests\Feature;

use App\Livewire\DocumentIndex;
use App\Models\Company;
use App\Models\Document;
use App\Models\JournalEntry;
use App\Models\PurchaseInvoice;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class DocumentIndexTest extends TestCase
{
    use RefreshDatabase;

    public function setUp(): void
    {
        parent::setUp();
        Role::findOrCreate('admin');
        Role::findOrCreate('client');
    }

    public function test_it_lists_documents_across_entity_types_for_the_company(): void
    {
        $company = Company::factory()->create();
        $invoice = PurchaseInvoice::factory()->for($company)->create();
        $entry = JournalEntry::factory()->for($company)->create();
        Document::factory()->for($invoice, 'documentable')->create(['company_id' => $company->id, 'category' => 'Invoice', 'original_filename' => 'bill.pdf']);
        Document::factory()->for($entry, 'documentable')->create(['company_id' => $company->id, 'category' => 'Bank Statement', 'original_filename' => 'statement.pdf']);
        $admin = User::factory()->create();
        $admin->assignRole('admin');
        $this->actingAs($admin);

        Livewire::test(DocumentIndex::class, ['company' => $company])
            ->assertSee('bill.pdf')
            ->assertSee('statement.pdf');
    }

    public function test_it_excludes_documents_from_another_company(): void
    {
        $company = Company::factory()->create();
        $otherCompany = Company::factory()->create();
        $otherInvoice = PurchaseInvoice::factory()->for($otherCompany)->create();
        Document::factory()->for($otherInvoice, 'documentable')->create(['company_id' => $otherCompany->id, 'original_filename' => 'other-company-file.pdf']);
        $admin = User::factory()->create();
        $admin->assignRole('admin');
        $this->actingAs($admin);

        Livewire::test(DocumentIndex::class, ['company' => $company])
            ->assertDontSee('other-company-file.pdf');
    }

    public function test_it_filters_by_category(): void
    {
        $company = Company::factory()->create();
        $invoice = PurchaseInvoice::factory()->for($company)->create();
        Document::factory()->for($invoice, 'documentable')->create(['company_id' => $company->id, 'category' => 'Invoice', 'original_filename' => 'invoice-doc.pdf']);
        Document::factory()->for($invoice, 'documentable')->create(['company_id' => $company->id, 'category' => 'Contract', 'original_filename' => 'contract-doc.pdf']);
        $admin = User::factory()->create();
        $admin->assignRole('admin');
        $this->actingAs($admin);

        Livewire::test(DocumentIndex::class, ['company' => $company])
            ->set('categoryFilter', 'Invoice')
            ->assertSee('invoice-doc.pdf')
            ->assertDontSee('contract-doc.pdf');
    }

    public function test_a_client_cannot_view_another_companys_documents_index(): void
    {
        $ownCompany = Company::factory()->create();
        $otherCompany = Company::factory()->create();
        $client = User::factory()->create(['company_id' => $ownCompany->id]);
        $client->assignRole('client');
        $this->actingAs($client);

        Livewire::test(DocumentIndex::class, ['company' => $otherCompany])
            ->assertForbidden();
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --filter=DocumentIndexTest`
Expected: FAIL — `Class "App\Livewire\DocumentIndex" not found`.

- [ ] **Step 3: Create the `DocumentIndex` Livewire component**

Create `app/Livewire/DocumentIndex.php`:

```php
<?php

namespace App\Livewire;

use App\Models\Company;
use App\Models\Document;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Layout;
use Livewire\Component;

#[Layout('layouts.app')]
class DocumentIndex extends Component
{
    public Company $company;

    public string $categoryFilter = '';

    public string $typeFilter = '';

    public string $fromDate = '';

    public string $toDate = '';

    public function mount(Company $company): void
    {
        Gate::authorize('view', $company);

        $this->company = $company;
    }

    public function render()
    {
        $query = Document::where('company_id', $this->company->id)->with(['documentable', 'uploader'])->latest();

        if ($this->categoryFilter !== '') {
            $query->where('category', $this->categoryFilter);
        }

        if ($this->typeFilter !== '') {
            $query->where('documentable_type', $this->typeFilter);
        }

        if ($this->fromDate !== '') {
            $query->whereDate('created_at', '>=', $this->fromDate);
        }

        if ($this->toDate !== '') {
            $query->whereDate('created_at', '<=', $this->toDate);
        }

        return view('livewire.document-index', [
            'documents' => $query->get(),
            'categories' => Document::CATEGORIES,
            'types' => [
                'purchase_invoice' => 'Purchase Invoice',
                'sales_invoice' => 'Sales Invoice',
                'journal_entry' => 'Journal Entry',
                'partner' => 'Partner',
            ],
        ]);
    }
}
```

- [ ] **Step 4: Create the `DocumentIndex` view**

Create `resources/views/livewire/document-index.blade.php`:

```blade
<div>
    <h1 class="text-2xl font-bold text-gray-800 mb-4">Documents — {{ $company->name }}</h1>

    <div class="bg-white shadow rounded-md p-4 mb-4 flex flex-wrap gap-3 items-end">
        <div>
            <x-input-label for="categoryFilter" value="Category" />
            <select id="categoryFilter" wire:model.live="categoryFilter" class="border-gray-300 rounded-md text-sm">
                <option value="">All</option>
                @foreach ($categories as $category)
                    <option value="{{ $category }}">{{ $category }}</option>
                @endforeach
            </select>
        </div>
        <div>
            <x-input-label for="typeFilter" value="Record type" />
            <select id="typeFilter" wire:model.live="typeFilter" class="border-gray-300 rounded-md text-sm">
                <option value="">All</option>
                @foreach ($types as $key => $label)
                    <option value="{{ $key }}">{{ $label }}</option>
                @endforeach
            </select>
        </div>
        <div>
            <x-input-label for="fromDate" value="From" />
            <x-text-input id="fromDate" type="date" wire:model.live="fromDate" class="w-full" />
        </div>
        <div>
            <x-input-label for="toDate" value="To" />
            <x-text-input id="toDate" type="date" wire:model.live="toDate" class="w-full" />
        </div>
    </div>

    <table class="min-w-full divide-y divide-gray-200 bg-white shadow rounded-md">
        <thead>
            <tr class="text-left text-sm text-gray-500">
                <th class="py-2 px-4">File</th>
                <th class="py-2 px-4">Category</th>
                <th class="py-2 px-4">Record</th>
                <th class="py-2 px-4">Uploaded by</th>
                <th class="py-2 px-4">Date</th>
            </tr>
        </thead>
        <tbody class="divide-y divide-gray-100">
            @forelse ($documents as $document)
                @php
                    $recordUrl = match ($document->documentable_type) {
                        'purchase_invoice' => route('purchase-invoices.show', [$company, $document->documentable_id]),
                        'sales_invoice' => route('sales-invoices.show', [$company, $document->documentable_id]),
                        'journal_entry' => route('accounting.journal-entries.edit', [$company, $document->documentable_id]),
                        'partner' => route('partners.show', [$company, $document->documentable_id]),
                        default => null,
                    };
                @endphp
                <tr class="text-sm">
                    <td class="py-2 px-4">
                        <a href="{{ route('documents.download', [$company, $document]) }}" class="text-indigo-600 hover:underline">{{ $document->original_filename }}</a>
                    </td>
                    <td class="py-2 px-4">{{ $document->category }}</td>
                    <td class="py-2 px-4">
                        @if ($recordUrl)
                            <a href="{{ $recordUrl }}" class="text-indigo-600 hover:underline">{{ $types[$document->documentable_type] }}</a>
                        @endif
                    </td>
                    <td class="py-2 px-4">{{ $document->uploader?->name }}</td>
                    <td class="py-2 px-4">{{ $document->created_at->toDateString() }}</td>
                </tr>
            @empty
                <tr><td colspan="5" class="py-4 px-4 text-gray-500">No documents yet.</td></tr>
            @endforelse
        </tbody>
    </table>
</div>
```

- [ ] **Step 5: Register the `documents.index` route**

Modify `routes/web.php` — add the import `use App\Livewire\DocumentIndex;` and add this line inside the existing `documents.` group (created in Task 2), before the `download` route:

```php
    Route::get('/documents', [DocumentIndex::class, '__invoke'])->name('index');
```

- [ ] **Step 6: Link to the Documents browser from the companies list**

Modify `resources/views/livewire/company-index.blade.php` — add this line inside the `Invoicing:` links block (after the "New Purchase Invoice" link):

```blade
                        <a href="{{ route('documents.index', $company) }}" class="text-indigo-600 hover:underline">Documents</a>
```

- [ ] **Step 7: Run test to verify it passes**

Run: `php artisan test --filter=DocumentIndexTest`
Expected: PASS (4 tests).

- [ ] **Step 8: Run the full test suite**

Run: `php artisan test`
Expected: full suite PASS, no regressions.

- [ ] **Step 9: Commit**

```bash
git add app/Livewire/DocumentIndex.php resources/views/livewire/document-index.blade.php routes/web.php resources/views/livewire/company-index.blade.php tests/Feature/DocumentIndexTest.php
git commit -m "Add company-wide Documents browser"
```
