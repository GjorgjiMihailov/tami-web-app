# Фаза 8в: Прифаќање на влезни е-фактури — имплементациски план

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Discover incoming purchase e-invoices from УЈП (efakturatest.ujp.gov.mk), let the accountant accept (auto-creating a draft `PurchaseInvoice`) or reject them, and download the official PDF for accepted ones — mirroring the sales-invoice send/status/PDF pattern already shipped in Phase 8b, but for the reverse direction.

**Architecture:** A new `incoming_efaktura_documents` "inbox" table holds every discovered EUID + its full raw payload, independent of `purchase_invoices`. Discovery, accept, reject, and PDF-fetch are all manually-triggered browser flows (never a background job) because every signed call requires the physical SafeNet hardware token via the existing local .NET bridge (`http://127.0.0.1:9847`). Accepting a document builds a `draft` `PurchaseInvoice` (auto-creating the supplier `Partner` if needed) that the accountant reviews and confirms exactly like a manually-entered purchase invoice.

**Tech Stack:** Laravel 11, Livewire 3, Alpine.js (`@script` blocks), PHPUnit (class-based tests, `RefreshDatabase`), existing `EfakturaJwsService`/local signing bridge from Phase 8b.

## Global Constraints

- Only `own`-mode companies (own registered hardware token) can use any part of this feature — `firm`-mode is rejected with a 422, same boundary as every 8b-ii endpoint (`Company::EFAKTURA_MODE_OWN`).
- Every signed operation is a manual button click, never automatic/cron — the physical token + SafeNet PIN popup cannot be triggered unattended.
- УЈП document-management endpoints for this document family live under `/einvoice_api/api/v1/documents/purchase-invoice/...` (confirmed base path pattern from the sales-invoice status/PDF work in this same project — see `EfakturaJwsService::STATUS_REFRESH_PATH`/`PDF_FETCH_PATH` for the sales-invoice precedent) — **not** `/JSONReceiver/`.
- Exact request/response shapes for `ids`, `payload/list`, and `current-status` are **not yet live-tested** — they are educated guesses from `efakturawiki.ujp.gov.mk`'s endpoint list, following the same shape conventions as the already-verified sales-invoice `invoices-status`/`pdf` endpoints. Task 12 (live verification) is expected to need format-iteration rounds, exactly like every prior е-Фактура endpoint in this project (Task 18, Task 8).
- УЈП reject-reason shifrarnik (`O-1`..`O-7`) was supplied verbatim by the user from the official шифрарници.pdf on 2026-08-06 — **do not retype/re-derive it**, copy the constant defined in Task 1 exactly, including its mixed Latin/Cyrillic "O" script (see that task's comment).
- `purchase_invoice_lines` has no `vat_treatment` column (unlike `sales_invoice_lines`) — only a flat `vat_rate`. The reverse ДДВ mapping (Task 4) therefore returns a plain rate string, not a treatment category.
- Money/date formatting in Blade views uses `\App\Support\Format::money()` / `\App\Support\Format::date()` (existing helpers, follow the pattern in `sales-invoice-index.blade.php`).
- Alpine `@script` blocks evaluate their content as a single expression — a top-level `function name(){}` is invisible outside itself (real bug hit and fixed in Phase 8b-ii). Every helper function inside a `@script` block **must** be declared as `const name = (...) => {...}`, never `function name(){}`.

---

## Task 1: `incoming_efaktura_documents` table, model, and factory

**Files:**
- Create: `database/migrations/2026_08_06_100000_create_incoming_efaktura_documents_table.php`
- Create: `app/Models/IncomingEfakturaDocument.php`
- Create: `database/factories/IncomingEfakturaDocumentFactory.php`
- Test: `tests/Unit/Models/IncomingEfakturaDocumentTest.php`

**Interfaces:**
- Produces: `IncomingEfakturaDocument` model with constants `DECISION_ACCEPTED = 'accepted'`, `DECISION_REJECTED = 'rejected'`, `REJECT_REASON_OTHER = "\u{041E}-7"`, and `REJECT_REASONS` (array, code => label); relations `company(): BelongsTo`, `purchaseInvoice(): BelongsTo`, `decidedBy(): BelongsTo`; casts `payload_json` to `array`.

- [ ] **Step 1: Write the migration**

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('incoming_efaktura_documents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->string('euid');
            $table->string('status_code', 10)->nullable();
            $table->string('status_name')->nullable();
            $table->string('doc_number')->nullable();
            $table->date('doc_date')->nullable();
            $table->string('seller_name')->nullable();
            $table->string('seller_tax_id')->nullable();
            $table->decimal('total_amount', 15, 2)->nullable();
            $table->json('payload_json');
            $table->timestamp('discovered_at');
            $table->string('decision', 20)->nullable();
            $table->timestamp('decided_at')->nullable();
            $table->foreignId('decided_by')->nullable()->constrained('users');
            $table->string('reject_reason_code', 10)->nullable();
            $table->string('reject_comment')->nullable();
            $table->foreignId('purchase_invoice_id')->nullable()->constrained();
            $table->string('efaktura_pdf_path')->nullable();
            $table->timestamps();

            $table->unique(['company_id', 'euid']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('incoming_efaktura_documents');
    }
};
```

- [ ] **Step 2: Run the migration**

Run: `php artisan migrate`
Expected: `incoming_efaktura_documents` table created, no errors.

- [ ] **Step 3: Write the model**

```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class IncomingEfakturaDocument extends Model
{
    use HasFactory;

    public const DECISION_ACCEPTED = 'accepted';

    public const DECISION_REJECTED = 'rejected';

    // "O-7" here uses the Cyrillic О (U+041E), matching the УЈП shifrarnik below — kept as an
    // explicit unicode escape so it can never silently drift to the Latin "O" via editor
    // encoding, the same precaution as the "МК" VAT-number prefix in EfakturaDocumentBuilder.
    public const REJECT_REASON_OTHER = "\u{041E}-7";

    // УЈП shifrarnik "ПРИЧИНИ ЗА ОДБИВАЊЕ НА Е-ФАКТУРА", captured verbatim from the official
    // шифрарници.pdf, supplied directly by the user 2026-08-06 — not re-typed/guessed from
    // memory. NOTE the mixed script: O-1 through O-5 use the Latin letter O, while О-6 and О-7
    // use the Cyrillic letter О (U+041E). This is exactly the kind of MK/МК homoglyph trap this
    // project has been burned by before (see EfakturaDocumentBuilder::buildParty()) — preserve
    // verbatim, do NOT "fix" the apparent inconsistency.
    public const REJECT_REASONS = [
        'O-1' => 'Погрешно пресметан ДДВ (несоодветна даночна основа, ДДВ стапка, неправилен даночен индикатор и сл.)',
        'O-2' => 'Грешка во нарачка (количина, цена, опис на промет и друго)',
        'O-3' => 'Погрешни податоци за купувач (едб, назив, адреса и друго)',
        'O-4' => 'Прометот не е извршен',
        'O-5' => 'Дупликат фактура',
        "\u{041E}-6" => 'Погрешен датум (издавање/промет)',
        self::REJECT_REASON_OTHER => '*Друго (внес на слободен текст)',
    ];

    protected $fillable = [
        'company_id', 'euid', 'status_code', 'status_name',
        'doc_number', 'doc_date', 'seller_name', 'seller_tax_id', 'total_amount',
        'payload_json', 'discovered_at', 'decision', 'decided_at', 'decided_by',
        'reject_reason_code', 'reject_comment', 'purchase_invoice_id', 'efaktura_pdf_path',
    ];

    protected function casts(): array
    {
        return [
            'doc_date' => 'date',
            'total_amount' => 'decimal:2',
            'payload_json' => 'array',
            'discovered_at' => 'datetime',
            'decided_at' => 'datetime',
        ];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function purchaseInvoice(): BelongsTo
    {
        return $this->belongsTo(PurchaseInvoice::class);
    }

    public function decidedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'decided_by');
    }
}
```

- [ ] **Step 4: Write the factory**

```php
<?php

namespace Database\Factories;

use App\Models\Company;
use Illuminate\Database\Eloquent\Factories\Factory;

class IncomingEfakturaDocumentFactory extends Factory
{
    public function definition(): array
    {
        return [
            'company_id' => Company::factory(),
            'euid' => $this->faker->uuid(),
            'status_code' => '01',
            'status_name' => 'Испратена (Нова)',
            'doc_number' => $this->faker->numerify('####-##'),
            'doc_date' => now()->toDateString(),
            'seller_name' => $this->faker->company(),
            'seller_tax_id' => $this->faker->numerify('#############'),
            'total_amount' => 1000,
            'payload_json' => [],
            'discovered_at' => now(),
        ];
    }
}
```

- [ ] **Step 5: Write the test**

```php
<?php

namespace Tests\Unit\Models;

use App\Models\Company;
use App\Models\IncomingEfakturaDocument;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class IncomingEfakturaDocumentTest extends TestCase
{
    use RefreshDatabase;

    public function test_belongs_to_company(): void
    {
        $company = Company::factory()->create();
        $document = IncomingEfakturaDocument::factory()->for($company)->create();

        $this->assertTrue($document->company->is($company));
    }

    public function test_payload_json_casts_to_array(): void
    {
        $document = IncomingEfakturaDocument::factory()->create([
            'payload_json' => ['document' => ['header' => ['docNumber' => '2026-1']]],
        ]);

        $this->assertSame('2026-1', $document->fresh()->payload_json['document']['header']['docNumber']);
    }

    public function test_decision_defaults_to_null(): void
    {
        $document = IncomingEfakturaDocument::factory()->create();

        $this->assertNull($document->decision);
    }

    public function test_reject_reasons_constant_preserves_mixed_script_codes(): void
    {
        $this->assertArrayHasKey('O-1', IncomingEfakturaDocument::REJECT_REASONS);
        $this->assertArrayHasKey("\u{041E}-6", IncomingEfakturaDocument::REJECT_REASONS);
        $this->assertSame("\u{041E}-7", IncomingEfakturaDocument::REJECT_REASON_OTHER);
        $this->assertArrayHasKey(IncomingEfakturaDocument::REJECT_REASON_OTHER, IncomingEfakturaDocument::REJECT_REASONS);
    }
}
```

- [ ] **Step 6: Run the tests**

Run: `php artisan test tests/Unit/Models/IncomingEfakturaDocumentTest.php`
Expected: 4 passed.

- [ ] **Step 7: Commit**

```bash
git add database/migrations/2026_08_06_100000_create_incoming_efaktura_documents_table.php app/Models/IncomingEfakturaDocument.php database/factories/IncomingEfakturaDocumentFactory.php tests/Unit/Models/IncomingEfakturaDocumentTest.php
git commit -m "feat: add incoming_efaktura_documents table and model"
```

---

## Task 2: `companies.efaktura_purchase_last_checked_at`

**Files:**
- Create: `database/migrations/2026_08_06_100100_add_efaktura_purchase_last_checked_at_to_companies_table.php`
- Modify: `app/Models/Company.php`
- Test: `tests/Unit/Models/CompanyIncomingEfakturaTest.php`

**Interfaces:**
- Consumes: `IncomingEfakturaDocument` (Task 1)
- Produces: `Company::incomingEfakturaDocuments(): HasMany`, `Company` cast `efaktura_purchase_last_checked_at` => `datetime`, fillable.

- [ ] **Step 1: Write the migration**

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            $table->timestamp('efaktura_purchase_last_checked_at')->nullable()->after('efaktura_token_registered_at');
        });
    }

    public function down(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            $table->dropColumn('efaktura_purchase_last_checked_at');
        });
    }
};
```

- [ ] **Step 2: Run the migration**

Run: `php artisan migrate`
Expected: column added, no errors.

- [ ] **Step 3: Modify `app/Models/Company.php`**

Change the `$fillable` array to add `'efaktura_purchase_last_checked_at'` after `'efaktura_token_registered_at'`:

```php
    protected $fillable = [
        'name', 'short_name', 'tax_id', 'registration_number', 'nkd_code', 'nkd_name',
        'email', 'phone', 'address', 'street_address', 'street_number', 'postal_code', 'city',
        'website', 'director_name', 'director_phone', 'director_email',
        'logo_path', 'logo_position', 'is_vat_registered', 'invoice_footer_note',
        'efaktura_credential_mode', 'efaktura_eujp_id', 'efaktura_firm_access_status',
        'efaktura_firm_access_decided_by', 'efaktura_firm_access_decided_at',
        'efaktura_token_serial_number', 'efaktura_token_subject_name',
        'efaktura_token_not_before', 'efaktura_token_not_after', 'efaktura_token_registered_at',
        'efaktura_purchase_last_checked_at',
    ];
```

Change `casts()` to also include `'efaktura_purchase_last_checked_at' => 'datetime'`:

```php
    protected function casts(): array
    {
        return [
            'is_vat_registered' => 'boolean',
            'efaktura_token_not_before' => 'datetime',
            'efaktura_token_not_after' => 'datetime',
            'efaktura_token_registered_at' => 'datetime',
            'efaktura_purchase_last_checked_at' => 'datetime',
        ];
    }
```

Add this relation method near `bankAccounts()` (no new `use` import needed — `IncomingEfakturaDocument` is in the same `App\Models` namespace):

```php
    public function incomingEfakturaDocuments(): HasMany
    {
        return $this->hasMany(IncomingEfakturaDocument::class);
    }
```

- [ ] **Step 4: Write the test**

```php
<?php

namespace Tests\Unit\Models;

use App\Models\Company;
use App\Models\IncomingEfakturaDocument;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CompanyIncomingEfakturaTest extends TestCase
{
    use RefreshDatabase;

    public function test_efaktura_purchase_last_checked_at_casts_to_datetime(): void
    {
        $company = Company::factory()->create(['efaktura_purchase_last_checked_at' => '2026-08-01 10:00:00']);

        $this->assertInstanceOf(\Illuminate\Support\Carbon::class, $company->fresh()->efaktura_purchase_last_checked_at);
    }

    public function test_has_many_incoming_efaktura_documents(): void
    {
        $company = Company::factory()->create();
        IncomingEfakturaDocument::factory()->for($company)->count(2)->create();

        $this->assertCount(2, $company->incomingEfakturaDocuments);
    }
}
```

- [ ] **Step 5: Run the tests**

Run: `php artisan test tests/Unit/Models/CompanyIncomingEfakturaTest.php`
Expected: 2 passed.

- [ ] **Step 6: Commit**

```bash
git add database/migrations/2026_08_06_100100_add_efaktura_purchase_last_checked_at_to_companies_table.php app/Models/Company.php tests/Unit/Models/CompanyIncomingEfakturaTest.php
git commit -m "feat: add efaktura_purchase_last_checked_at to companies"
```

---

## Task 3: `purchase_invoice_lines.needs_review`

**Files:**
- Create: `database/migrations/2026_08_06_100200_add_needs_review_to_purchase_invoice_lines_table.php`
- Modify: `app/Models/PurchaseInvoiceLine.php`
- Test: `tests/Unit/Models/PurchaseInvoiceLineNeedsReviewTest.php`

**Interfaces:**
- Produces: `PurchaseInvoiceLine` fillable `needs_review`, cast to boolean, default `false`.

- [ ] **Step 1: Write the migration**

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('purchase_invoice_lines', function (Blueprint $table) {
            $table->boolean('needs_review')->default(false)->after('vat_deductible');
        });
    }

    public function down(): void
    {
        Schema::table('purchase_invoice_lines', function (Blueprint $table) {
            $table->dropColumn('needs_review');
        });
    }
};
```

- [ ] **Step 2: Run the migration**

Run: `php artisan migrate`
Expected: column added, no errors.

- [ ] **Step 3: Modify `app/Models/PurchaseInvoiceLine.php`**

Change the `$fillable` array to:

```php
    protected $fillable = ['purchase_invoice_id', 'item_id', 'account_id', 'stock_movement_id', 'description', 'quantity', 'unit_price', 'vat_rate', 'vat_deductible', 'needs_review'];
```

Change `casts()` to also include `'needs_review' => 'boolean'`:

```php
    protected function casts(): array
    {
        return [
            'quantity' => 'decimal:3',
            'unit_price' => 'decimal:2',
            'vat_rate' => 'decimal:2',
            'vat_deductible' => 'boolean',
            'needs_review' => 'boolean',
        ];
    }
```

- [ ] **Step 4: Write the test**

```php
<?php

namespace Tests\Unit\Models;

use App\Models\PurchaseInvoiceLine;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PurchaseInvoiceLineNeedsReviewTest extends TestCase
{
    use RefreshDatabase;

    public function test_needs_review_defaults_to_false(): void
    {
        $line = PurchaseInvoiceLine::factory()->create();

        $this->assertFalse($line->fresh()->needs_review);
    }

    public function test_needs_review_can_be_set_true(): void
    {
        $line = PurchaseInvoiceLine::factory()->create(['needs_review' => true]);

        $this->assertTrue($line->fresh()->needs_review);
    }
}
```

- [ ] **Step 5: Run the tests**

Run: `php artisan test tests/Unit/Models/PurchaseInvoiceLineNeedsReviewTest.php`
Expected: 2 passed.

- [ ] **Step 6: Commit**

```bash
git add database/migrations/2026_08_06_100200_add_needs_review_to_purchase_invoice_lines_table.php app/Models/PurchaseInvoiceLine.php tests/Unit/Models/PurchaseInvoiceLineNeedsReviewTest.php
git commit -m "feat: add needs_review flag to purchase invoice lines"
```

---

## Task 4: `EfakturaTaxIndicator::fromCode()` reverse mapping

**Files:**
- Modify: `app/Services/Efaktura/EfakturaTaxIndicator.php`
- Test: `tests/Unit/Services/Efaktura/EfakturaTaxIndicatorTest.php`

**Interfaces:**
- Produces: `EfakturaTaxIndicator::fromCode(string $code): ?string` — returns the `vat_rate` string (e.g. `'18.00'`) for a known УЈП tax indicator code, or `null` for an unrecognized/unsupported code (member-32 reverse-charge, "not subject to tax", or any other code not in the forward table).

- [ ] **Step 1: Add failing tests to the existing test file**

Append these methods to `tests/Unit/Services/Efaktura/EfakturaTaxIndicatorTest.php` (inside the existing `EfakturaTaxIndicatorTest` class, before the final closing `}`):

```php
    public function test_from_code_maps_ddv_a_to_18_percent(): void
    {
        $this->assertSame('18.00', EfakturaTaxIndicator::fromCode('DDV-A'));
    }

    public function test_from_code_maps_ddv_v_to_10_percent(): void
    {
        $this->assertSame('10.00', EfakturaTaxIndicator::fromCode('DDV-V'));
    }

    public function test_from_code_maps_ddv_b_to_5_percent(): void
    {
        $this->assertSame('5.00', EfakturaTaxIndicator::fromCode('DDV-B'));
    }

    public function test_from_code_maps_ddv_g_and_exempt_codes_to_0_percent(): void
    {
        $this->assertSame('0.00', EfakturaTaxIndicator::fromCode('DDV-G'));
        $this->assertSame('0.00', EfakturaTaxIndicator::fromCode('DDV-7-I'));
        $this->assertSame('0.00', EfakturaTaxIndicator::fromCode('DDV-8'));
        $this->assertSame('0.00', EfakturaTaxIndicator::fromCode('DDV-9'));
    }

    public function test_from_code_returns_null_for_an_unsupported_member_32_code(): void
    {
        // DDV-11-A (member-32-а reverse charge) is a real code a supplier could legally send
        // on an incoming invoice — this app doesn't model reverse-charge, so it must come back
        // null (caller flags the line needs_review) rather than throw or silently default.
        $this->assertNull(EfakturaTaxIndicator::fromCode('DDV-11-A'));
    }

    public function test_from_code_returns_null_for_an_unknown_code(): void
    {
        $this->assertNull(EfakturaTaxIndicator::fromCode('NOT-A-REAL-CODE'));
    }
```

- [ ] **Step 2: Run the tests to verify they fail**

Run: `php artisan test tests/Unit/Services/Efaktura/EfakturaTaxIndicatorTest.php`
Expected: FAIL with "Call to undefined method App\Services\Efaktura\EfakturaTaxIndicator::fromCode()".

- [ ] **Step 3: Implement `fromCode()`**

Add this public static method to `app/Services/Efaktura/EfakturaTaxIndicator.php`, after `percent()`:

```php
    /**
     * Reverse of code(): maps a УЈП tax indicator code back onto a flat vat_rate string, for
     * incoming purchase invoices. purchase_invoice_lines has no vat_treatment column (unlike
     * sales_invoice_lines) — only vat_rate — so exempt/export codes all collapse to '0.00'.
     * Returns null for any code with no supported mapping (member-32/32-а reverse-charge,
     * "not subject to tax", or anything else not in the forward table) — the caller is
     * responsible for flagging such a line for manual review rather than guessing a rate.
     */
    public static function fromCode(string $code): ?string
    {
        return match ($code) {
            'DDV-A' => '18.00',
            'DDV-V' => '10.00',
            'DDV-B' => '5.00',
            'DDV-G', 'DDV-7-I', 'DDV-8', 'DDV-9' => '0.00',
            default => null,
        };
    }
```

- [ ] **Step 4: Run the tests to verify they pass**

Run: `php artisan test tests/Unit/Services/Efaktura/EfakturaTaxIndicatorTest.php`
Expected: all passed (previous 8 + new 6 = 14).

- [ ] **Step 5: Commit**

```bash
git add app/Services/Efaktura/EfakturaTaxIndicator.php tests/Unit/Services/Efaktura/EfakturaTaxIndicatorTest.php
git commit -m "feat: add reverse VAT-code-to-rate mapping for incoming invoices"
```

---

## Task 5: `EfakturaJwsService` purchase-invoice endpoints

**Files:**
- Modify: `app/Services/Efaktura/EfakturaJwsService.php`
- Test: `tests/Unit/Services/Efaktura/EfakturaJwsServiceTest.php`

**Interfaces:**
- Consumes: `EfakturaJwsService::postSignedRequest()` (existing private method), `EfakturaJwsService::buildSigningInputForPayload()` (existing, unchanged)
- Produces: `sendPurchaseInvoiceIds(Company $company, string $signingInput, string $signatureBase64Url): Response`, `sendPurchaseInvoicePayloadList(...)`, `sendPurchaseInvoiceStatus(...)`, `sendPurchaseInvoiceAcceptReject(...)`, `sendPurchaseInvoicePdfFetch(...)` — all same signature shape as the existing `sendStatusRefresh()`/`sendPdfFetch()`.

- [ ] **Step 1: Add failing tests to the existing test file**

Append these methods to `tests/Unit/Services/Efaktura/EfakturaJwsServiceTest.php` (inside the existing class, before the final closing `}`):

```php
    public function test_send_purchase_invoice_ids_posts_to_the_ids_endpoint(): void
    {
        Http::fake(['*' => Http::response(['euids' => []], 200)]);
        $company = Company::factory()->create([
            'tax_id' => '4030001234567', 'efaktura_eujp_id' => 'EUJP-1', 'efaktura_token_serial_number' => '1A2B3C',
        ]);

        $response = (new EfakturaJwsService)->sendPurchaseInvoiceIds($company, 'header.payload', 'c2ln');

        $this->assertTrue($response->successful());
        Http::assertSent(fn ($request) => $request->url() === rtrim(config('services.efaktura.base_url'), '/').'/einvoice_api/api/v1/documents/purchase-invoice/ids');
    }

    public function test_send_purchase_invoice_payload_list_posts_to_the_payload_list_endpoint(): void
    {
        Http::fake(['*' => Http::response(['documents' => []], 200)]);
        $company = Company::factory()->create([
            'tax_id' => '4030001234567', 'efaktura_eujp_id' => 'EUJP-1', 'efaktura_token_serial_number' => '1A2B3C',
        ]);

        $response = (new EfakturaJwsService)->sendPurchaseInvoicePayloadList($company, 'header.payload', 'c2ln');

        $this->assertTrue($response->successful());
        Http::assertSent(fn ($request) => $request->url() === rtrim(config('services.efaktura.base_url'), '/').'/einvoice_api/api/v1/documents/purchase-invoice/payload/list');
    }

    public function test_send_purchase_invoice_status_posts_to_the_current_status_endpoint(): void
    {
        Http::fake(['*' => Http::response(['invoices' => []], 200)]);
        $company = Company::factory()->create([
            'tax_id' => '4030001234567', 'efaktura_eujp_id' => 'EUJP-1', 'efaktura_token_serial_number' => '1A2B3C',
        ]);

        $response = (new EfakturaJwsService)->sendPurchaseInvoiceStatus($company, 'header.payload', 'c2ln');

        $this->assertTrue($response->successful());
        Http::assertSent(fn ($request) => $request->url() === rtrim(config('services.efaktura.base_url'), '/').'/einvoice_api/api/v1/documents/purchase-invoice/current-status');
    }

    public function test_send_purchase_invoice_accept_reject_posts_to_the_accept_reject_endpoint(): void
    {
        Http::fake(['*' => Http::response(['status' => 'ok'], 200)]);
        $company = Company::factory()->create([
            'tax_id' => '4030001234567', 'efaktura_eujp_id' => 'EUJP-1', 'efaktura_token_serial_number' => '1A2B3C',
        ]);

        $response = (new EfakturaJwsService)->sendPurchaseInvoiceAcceptReject($company, 'header.payload', 'c2ln');

        $this->assertTrue($response->successful());
        Http::assertSent(fn ($request) => $request->url() === rtrim(config('services.efaktura.base_url'), '/').'/einvoice_api/api/v1/documents/purchase-invoice/accept-reject');
    }

    public function test_send_purchase_invoice_pdf_fetch_posts_to_the_purchase_invoice_pdf_endpoint(): void
    {
        Http::fake(['*' => Http::response(['pdfBase64' => base64_encode('fake-pdf-bytes')], 200)]);
        $company = Company::factory()->create([
            'tax_id' => '4030001234567', 'efaktura_eujp_id' => 'EUJP-1', 'efaktura_token_serial_number' => '1A2B3C',
        ]);

        $response = (new EfakturaJwsService)->sendPurchaseInvoicePdfFetch($company, 'header.payload', 'c2ln');

        $this->assertTrue($response->successful());
        Http::assertSent(fn ($request) => $request->url() === rtrim(config('services.efaktura.base_url'), '/').'/einvoice_api/api/v1/documents/purchase-invoice/pdf');
    }
```

- [ ] **Step 2: Run the tests to verify they fail**

Run: `php artisan test tests/Unit/Services/Efaktura/EfakturaJwsServiceTest.php`
Expected: FAIL with "Call to undefined method ... sendPurchaseInvoiceIds()" (and similarly for the other four).

- [ ] **Step 3: Implement the new endpoint constants and methods**

In `app/Services/Efaktura/EfakturaJwsService.php`, add these constants after `PDF_FETCH_PATH`:

```php
    // Same /einvoice_api/ base confirmed for the sales-invoice status/PDF pair (see comment
    // above) — assumed to generalize to the whole "Управување со Документи" endpoint family per
    // efakturawiki.ujp.gov.mk's endpoint list, but NOT yet live-tested for these five specific
    // purchase-invoice paths. Response shapes are best guesses; see the controllers that call
    // these methods for exactly which JSON keys are assumed.
    private const PURCHASE_IDS_PATH = '/einvoice_api/api/v1/documents/purchase-invoice/ids';

    private const PURCHASE_PAYLOAD_LIST_PATH = '/einvoice_api/api/v1/documents/purchase-invoice/payload/list';

    private const PURCHASE_STATUS_PATH = '/einvoice_api/api/v1/documents/purchase-invoice/current-status';

    private const PURCHASE_ACCEPT_REJECT_PATH = '/einvoice_api/api/v1/documents/purchase-invoice/accept-reject';

    private const PURCHASE_PDF_PATH = '/einvoice_api/api/v1/documents/purchase-invoice/pdf';
```

Add these methods after `sendPdfFetch()`:

```php
    public function sendPurchaseInvoiceIds(Company $company, string $signingInput, string $signatureBase64Url): Response
    {
        return $this->postSignedRequest($company, self::PURCHASE_IDS_PATH, $signingInput, $signatureBase64Url);
    }

    public function sendPurchaseInvoicePayloadList(Company $company, string $signingInput, string $signatureBase64Url): Response
    {
        return $this->postSignedRequest($company, self::PURCHASE_PAYLOAD_LIST_PATH, $signingInput, $signatureBase64Url);
    }

    public function sendPurchaseInvoiceStatus(Company $company, string $signingInput, string $signatureBase64Url): Response
    {
        return $this->postSignedRequest($company, self::PURCHASE_STATUS_PATH, $signingInput, $signatureBase64Url);
    }

    public function sendPurchaseInvoiceAcceptReject(Company $company, string $signingInput, string $signatureBase64Url): Response
    {
        return $this->postSignedRequest($company, self::PURCHASE_ACCEPT_REJECT_PATH, $signingInput, $signatureBase64Url);
    }

    public function sendPurchaseInvoicePdfFetch(Company $company, string $signingInput, string $signatureBase64Url): Response
    {
        return $this->postSignedRequest($company, self::PURCHASE_PDF_PATH, $signingInput, $signatureBase64Url);
    }
```

- [ ] **Step 4: Run the tests to verify they pass**

Run: `php artisan test tests/Unit/Services/Efaktura/EfakturaJwsServiceTest.php`
Expected: all passed.

- [ ] **Step 5: Commit**

```bash
git add app/Services/Efaktura/EfakturaJwsService.php tests/Unit/Services/Efaktura/EfakturaJwsServiceTest.php
git commit -m "feat: add EfakturaJwsService methods for purchase-invoice endpoints"
```

---

## Task 6: `IncomingPurchaseInvoiceBuilder` service

**Files:**
- Create: `app/Services/Efaktura/IncomingPurchaseInvoiceBuilder.php`
- Test: `tests/Unit/Services/Efaktura/IncomingPurchaseInvoiceBuilderTest.php`

**Interfaces:**
- Consumes: `EfakturaTaxIndicator::fromCode()` (Task 4), `Partner` model, `PurchaseInvoice` model
- Produces: `IncomingPurchaseInvoiceBuilder::build(Company $company, array $payload, User $decidedBy): PurchaseInvoice` — `$payload` is the decoded `payload_json` array (shape: `['document' => ['header' => [...], 'seller' => [...], 'docPayment' => [...], 'docItems' => [...]]]`, mirroring `EfakturaDocumentBuilder::build()`'s output shape). Creates (or reuses) a `Partner` by normalized `tax_id`, creates a `draft` `PurchaseInvoice` with lines, `item_id`/`account_id` always null, `needs_review` set per line when the tax indicator doesn't map.

- [ ] **Step 1: Write the failing tests**

```php
<?php

namespace Tests\Unit\Services\Efaktura;

use App\Models\Company;
use App\Models\Partner;
use App\Models\User;
use App\Services\Efaktura\IncomingPurchaseInvoiceBuilder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class IncomingPurchaseInvoiceBuilderTest extends TestCase
{
    use RefreshDatabase;

    private function samplePayload(array $overrides = []): array
    {
        $base = [
            'document' => [
                'header' => ['docNumber' => 'SUP-2026-1', 'docDate' => '2026-08-01'],
                'seller' => [
                    'sellerTin' => '4030009998887',
                    'sellerVatNumber' => "\u{041C}\u{041A}4030009998887",
                    'sellerName' => 'Добавувач ДООЕЛ Скопје',
                    'sellerAddress' => [
                        'streetAddress' => 'Партизанска', 'streetNumber' => '10',
                        'postalCode' => '1000', 'city' => 'СКОПЈЕ',
                    ],
                ],
                'docPayment' => ['docPaymentTypeDueDate' => '2026-08-15'],
                'docItems' => [
                    [
                        'docItemDesc' => 'Канцелариски материјал', 'docItemQty' => 2,
                        'docItemUnitPriceWoVat' => 500, 'docItemTaxIndicator' => 'DDV-A',
                    ],
                ],
            ],
        ];

        return array_replace_recursive($base, $overrides);
    }

    public function test_build_creates_a_draft_purchase_invoice_with_lines(): void
    {
        $company = Company::factory()->create();
        $user = User::factory()->create();

        $invoice = (new IncomingPurchaseInvoiceBuilder)->build($company, $this->samplePayload(), $user);

        $this->assertSame('draft', $invoice->status);
        $this->assertSame('SUP-2026-1', $invoice->supplier_invoice_number);
        $this->assertSame('2026-08-01', $invoice->invoice_date->toDateString());
        $this->assertSame('2026-08-15', $invoice->due_date->toDateString());
        $this->assertCount(1, $invoice->lines);
        $line = $invoice->lines->first();
        $this->assertSame('Канцелариски материјал', $line->description);
        $this->assertSame('2.000', $line->quantity);
        $this->assertSame('500.00', $line->unit_price);
        $this->assertSame('18.00', $line->vat_rate);
        $this->assertNull($line->item_id);
        $this->assertNull($line->account_id);
        $this->assertFalse($line->needs_review);
    }

    public function test_build_creates_a_new_partner_when_tax_id_is_unknown(): void
    {
        $company = Company::factory()->create();
        $user = User::factory()->create();

        $invoice = (new IncomingPurchaseInvoiceBuilder)->build($company, $this->samplePayload(), $user);

        $partner = $invoice->partner;
        $this->assertSame('4030009998887', $partner->tax_id);
        $this->assertSame('Добавувач ДООЕЛ Скопје', $partner->name);
        $this->assertSame('Партизанска', $partner->street_address);
        $this->assertSame('СКОПЈЕ', $partner->city);
        $this->assertTrue($partner->is_vat_registered);
    }

    public function test_build_reuses_an_existing_partner_matched_by_normalized_tax_id(): void
    {
        $company = Company::factory()->create();
        $user = User::factory()->create();
        // Existing partner's tax_id has a stray Cyrillic "МК" prefix baked in — same real-world
        // dirty-data pattern already fixed once for outgoing invoices in EfakturaDocumentBuilder.
        $existingPartner = Partner::factory()->for($company)->create(['tax_id' => "\u{041C}\u{041A}4030009998887"]);

        $invoice = (new IncomingPurchaseInvoiceBuilder)->build($company, $this->samplePayload(), $user);

        $this->assertTrue($invoice->partner->is($existingPartner));
        $this->assertSame(1, Partner::where('company_id', $company->id)->count());
    }

    public function test_build_flags_a_line_needing_review_for_an_unsupported_tax_indicator(): void
    {
        $company = Company::factory()->create();
        $user = User::factory()->create();
        $payload = $this->samplePayload([
            'document' => ['docItems' => [['docItemTaxIndicator' => 'DDV-11-A']]],
        ]);

        $invoice = (new IncomingPurchaseInvoiceBuilder)->build($company, $payload, $user);

        $line = $invoice->lines->first();
        $this->assertSame('0.00', $line->vat_rate);
        $this->assertTrue($line->needs_review);
    }

    public function test_build_falls_back_to_doc_date_when_due_date_is_missing(): void
    {
        $company = Company::factory()->create();
        $user = User::factory()->create();
        $payload = $this->samplePayload(['document' => ['docPayment' => ['docPaymentTypeDueDate' => null]]]);

        $invoice = (new IncomingPurchaseInvoiceBuilder)->build($company, $payload, $user);

        $this->assertSame('2026-08-01', $invoice->due_date->toDateString());
    }
}
```

- [ ] **Step 2: Run the tests to verify they fail**

Run: `php artisan test tests/Unit/Services/Efaktura/IncomingPurchaseInvoiceBuilderTest.php`
Expected: FAIL with "Class App\Services\Efaktura\IncomingPurchaseInvoiceBuilder not found".

- [ ] **Step 3: Implement the service**

```php
<?php

namespace App\Services\Efaktura;

use App\Models\Company;
use App\Models\Partner;
use App\Models\PurchaseInvoice;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class IncomingPurchaseInvoiceBuilder
{
    public function build(Company $company, array $payload, User $decidedBy): PurchaseInvoice
    {
        $document = $payload['document'];
        $header = $document['header'];
        $seller = $document['seller'];
        $docPayment = $document['docPayment'] ?? [];

        return DB::transaction(function () use ($company, $document, $header, $seller, $docPayment, $decidedBy) {
            $partner = $this->findOrCreatePartner($company, $seller);

            $invoice = PurchaseInvoice::create([
                'company_id' => $company->id,
                'partner_id' => $partner->id,
                'warehouse_id' => null,
                'supplier_invoice_number' => $header['docNumber'],
                'invoice_date' => $header['docDate'],
                'due_date' => $docPayment['docPaymentTypeDueDate'] ?? $header['docDate'],
                'status' => 'draft',
                'notes' => null,
                'created_by' => $decidedBy->id,
            ]);

            foreach ($document['docItems'] as $item) {
                $invoice->lines()->create($this->buildLine($item));
            }

            return $invoice->fresh(['lines', 'partner']);
        });
    }

    private function findOrCreatePartner(Company $company, array $seller): Partner
    {
        // Strip a leading MK/МК typed directly into the УЈП-supplied tax id, same normalization
        // EfakturaDocumentBuilder::buildParty() applies for outgoing invoices — a supplier's own
        // registry data can carry the same dirty-data pattern this app already hit once.
        $taxId = preg_replace('/^(mk|мк)/iu', '', (string) ($seller['sellerTin'] ?? ''));

        $partner = Partner::where('company_id', $company->id)->where('tax_id', $taxId)->first();

        if ($partner) {
            return $partner;
        }

        $address = $seller['sellerAddress'] ?? [];

        return Partner::create([
            'company_id' => $company->id,
            'name' => $seller['sellerName'] ?? $taxId,
            'type' => 'legal_entity',
            'tax_id' => $taxId,
            'is_vat_registered' => filled($seller['sellerVatNumber'] ?? null),
            'vat_number' => $seller['sellerVatNumber'] ?? null,
            'street_address' => $address['streetAddress'] ?? null,
            'street_number' => $address['streetNumber'] ?? null,
            'postal_code' => $address['postalCode'] ?? null,
            'city' => $address['city'] ?? null,
        ]);
    }

    private function buildLine(array $item): array
    {
        $code = $item['docItemTaxIndicator'] ?? null;
        $vatRate = $code ? EfakturaTaxIndicator::fromCode($code) : null;

        return [
            'item_id' => null,
            'account_id' => null,
            'description' => $item['docItemDesc'] ?? '',
            'quantity' => $item['docItemQty'] ?? 1,
            'unit_price' => $item['docItemUnitPriceWoVat'] ?? 0,
            'vat_rate' => $vatRate ?? '0.00',
            'vat_deductible' => true,
            'needs_review' => $vatRate === null,
        ];
    }
}
```

- [ ] **Step 4: Run the tests to verify they pass**

Run: `php artisan test tests/Unit/Services/Efaktura/IncomingPurchaseInvoiceBuilderTest.php`
Expected: 5 passed.

- [ ] **Step 5: Commit**

```bash
git add app/Services/Efaktura/IncomingPurchaseInvoiceBuilder.php tests/Unit/Services/Efaktura/IncomingPurchaseInvoiceBuilderTest.php
git commit -m "feat: add IncomingPurchaseInvoiceBuilder to create draft purchase invoices from UJP payloads"
```

---

## Task 7: `EfakturaIncomingDiscoveryController` (ids → payload/list → status)

**Files:**
- Create: `app/Http/Controllers/EfakturaIncomingDiscoveryController.php`
- Modify: `routes/web.php`
- Test: `tests/Feature/EfakturaIncomingDiscoveryControllerTest.php`

**Interfaces:**
- Consumes: `EfakturaJwsService::buildSigningInputForPayload()`, `sendPurchaseInvoiceIds()`, `sendPurchaseInvoicePayloadList()`, `sendPurchaseInvoiceStatus()` (Task 5), `IncomingEfakturaDocument` (Task 1), `Company::efaktura_purchase_last_checked_at` (Task 2)
- Produces: routes `incoming-efaktura.discover.ids.signing-input`, `incoming-efaktura.discover.ids`, `incoming-efaktura.discover.payload.signing-input`, `incoming-efaktura.discover.payload`, `incoming-efaktura.discover.status.signing-input`, `incoming-efaktura.discover.status`

This is a three-step signed flow (three separate bridge `/sign` calls, one per endpoint) because each УЈП endpoint signs a different JSON body: `ids` signs `{requestTimestamp, dateFrom, dateTo}`, `payload/list` signs `{requestTimestamp, euids}`, `current-status` signs `{requestTimestamp, dateFrom, dateTo}` again. `ids` returns every EUID in range (new and already-known); the controller diffs against `incoming_efaktura_documents` to find which are new before the frontend calls `payload/list` (skipped entirely if nothing new). `current-status` always runs, refreshing status for new **and** previously-discovered-but-undecided rows in the same date range.

- [ ] **Step 1: Write the failing tests**

```php
<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\IncomingEfakturaDocument;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class EfakturaIncomingDiscoveryControllerTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Role::findOrCreate('admin');
        Role::findOrCreate('client');
    }

    private function makeOwnModeCompany(): Company
    {
        return Company::factory()->create([
            'tax_id' => '4030001234567',
            'efaktura_credential_mode' => Company::EFAKTURA_MODE_OWN,
            'efaktura_eujp_id' => 'EUJP-1',
            'efaktura_token_serial_number' => '1A2B3C',
        ]);
    }

    private function admin(): User
    {
        $admin = User::factory()->create();
        $admin->assignRole('admin');

        return $admin;
    }

    public function test_ids_signing_input_returns_a_token(): void
    {
        $company = $this->makeOwnModeCompany();

        $response = $this->actingAs($this->admin())->postJson(
            route('incoming-efaktura.discover.ids.signing-input', $company),
            ['certificateBase64' => base64_encode('fake-cert')]
        );

        $response->assertOk()->assertJsonStructure(['token', 'signingInput']);
    }

    public function test_ids_returns_only_new_euids_and_updates_last_checked_at(): void
    {
        Http::fake(['*' => Http::response(['euids' => ['euid-1', 'euid-2']], 200)]);
        $company = $this->makeOwnModeCompany();
        IncomingEfakturaDocument::factory()->for($company)->create(['euid' => 'euid-1']);

        $signingResponse = $this->actingAs($this->admin())->postJson(
            route('incoming-efaktura.discover.ids.signing-input', $company),
            ['certificateBase64' => base64_encode('fake-cert')]
        )->json();

        $response = $this->actingAs($this->admin())->postJson(
            route('incoming-efaktura.discover.ids', $company),
            ['token' => $signingResponse['token'], 'signature' => 'ZmFrZS1zaWc']
        );

        $response->assertOk()->assertJson(['newEuids' => ['euid-2']]);
        $this->assertNotNull($company->fresh()->efaktura_purchase_last_checked_at);
    }

    public function test_payload_creates_new_incoming_documents_from_returned_documents(): void
    {
        Http::fake(['*' => Http::response(['documents' => [
            ['euid' => 'euid-2', 'document' => [
                'header' => ['docNumber' => 'SUP-1', 'docDate' => '2026-08-01'],
                'seller' => ['sellerName' => 'Добавувач', 'sellerTin' => '4030009998887'],
                'docTotals' => ['docGrossAmount' => 590],
                'docItems' => [],
            ]],
        ]], 200)]);
        $company = $this->makeOwnModeCompany();

        $signingResponse = $this->actingAs($this->admin())->postJson(
            route('incoming-efaktura.discover.payload.signing-input', $company),
            ['certificateBase64' => base64_encode('fake-cert'), 'euids' => ['euid-2']]
        )->json();

        $response = $this->actingAs($this->admin())->postJson(
            route('incoming-efaktura.discover.payload', $company),
            ['token' => $signingResponse['token'], 'signature' => 'ZmFrZS1zaWc']
        );

        $response->assertOk()->assertJson(['status' => 'discovered', 'created' => 1]);
        $document = IncomingEfakturaDocument::where('company_id', $company->id)->where('euid', 'euid-2')->first();
        $this->assertNotNull($document);
        $this->assertSame('SUP-1', $document->doc_number);
        $this->assertSame('Добавувач', $document->seller_name);
        $this->assertSame('590.00', $document->total_amount);
    }

    public function test_status_updates_matching_documents_by_euid(): void
    {
        Http::fake(['*' => Http::response(['invoices' => [
            ['euid' => 'euid-1', 'statusCode' => '01', 'statusName' => 'Испратена (Нова)'],
        ]], 200)]);
        $company = $this->makeOwnModeCompany();
        $document = IncomingEfakturaDocument::factory()->for($company)->create(['euid' => 'euid-1', 'status_code' => null]);

        $signingResponse = $this->actingAs($this->admin())->postJson(
            route('incoming-efaktura.discover.status.signing-input', $company),
            ['certificateBase64' => base64_encode('fake-cert'), 'dateFrom' => '2026-01-01', 'dateTo' => '2026-08-06']
        )->json();

        $response = $this->actingAs($this->admin())->postJson(
            route('incoming-efaktura.discover.status', $company),
            ['token' => $signingResponse['token'], 'signature' => 'ZmFrZS1zaWc']
        );

        $response->assertOk()->assertJson(['status' => 'refreshed', 'updated' => 1]);
        $this->assertSame('01', $document->fresh()->status_code);
    }

    public function test_firm_mode_company_is_rejected(): void
    {
        $company = Company::factory()->create(['efaktura_credential_mode' => Company::EFAKTURA_MODE_FIRM]);

        $response = $this->actingAs($this->admin())->postJson(
            route('incoming-efaktura.discover.ids.signing-input', $company),
            ['certificateBase64' => base64_encode('fake-cert')]
        );

        $response->assertStatus(422);
    }

    public function test_client_role_is_forbidden(): void
    {
        $company = $this->makeOwnModeCompany();
        $client = User::factory()->create(['company_id' => $company->id]);
        $client->assignRole('client');

        $response = $this->actingAs($client)->postJson(
            route('incoming-efaktura.discover.ids.signing-input', $company),
            ['certificateBase64' => base64_encode('fake-cert')]
        );

        $response->assertStatus(403);
    }
}
```

- [ ] **Step 2: Run the tests to verify they fail**

Run: `php artisan test tests/Feature/EfakturaIncomingDiscoveryControllerTest.php`
Expected: FAIL — routes don't exist yet (404s / route-not-found errors).

- [ ] **Step 3: Write the controller**

```php
<?php

namespace App\Http\Controllers;

use App\Models\Company;
use App\Models\IncomingEfakturaDocument;
use App\Services\Efaktura\EfakturaJwsService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;

class EfakturaIncomingDiscoveryController extends Controller
{
    public function idsSigningInput(Request $request, Company $company, EfakturaJwsService $jwsService)
    {
        $this->authorizeDiscovery($company);

        $validated = $request->validate(['certificateBase64' => 'required|string']);

        $dateFrom = $company->efaktura_purchase_last_checked_at
            ? $company->efaktura_purchase_last_checked_at->timezone('Europe/Skopje')->toDateString()
            : now()->startOfYear()->toDateString();
        $dateTo = now()->timezone('Europe/Skopje')->toDateString();

        $payload = [
            'requestTimestamp' => now()->timezone('Europe/Skopje')->format('Y-m-d\TH:i:s'),
            'dateFrom' => $dateFrom,
            'dateTo' => $dateTo,
        ];
        $result = $jwsService->buildSigningInputForPayload($payload, $validated['certificateBase64']);

        $token = (string) Str::uuid();
        Cache::put("efaktura-incoming-ids:{$token}", [
            'company_id' => $company->id,
            'signing_input' => $result['signingInput'],
            'date_from' => $dateFrom,
            'date_to' => $dateTo,
        ], now()->addMinutes(10));

        return response()->json(['token' => $token, 'signingInput' => $result['signingInput']]);
    }

    public function ids(Request $request, Company $company, EfakturaJwsService $jwsService)
    {
        $this->authorizeDiscovery($company);

        $validated = $request->validate(['token' => 'required|string', 'signature' => 'required|string']);

        $cached = Cache::pull("efaktura-incoming-ids:{$validated['token']}");
        if (! $cached || $cached['company_id'] !== $company->id) {
            return response()->json(['error' => 'expired_or_invalid_token'], 410);
        }

        try {
            $response = $jwsService->sendPurchaseInvoiceIds($company, $cached['signing_input'], $validated['signature']);
        } catch (\Illuminate\Http\Client\ConnectionException $e) {
            return response()->json(['error' => 'ujp_unreachable', 'message' => 'Не можам да се поврзам со серверот на УЈП — провери ја интернет-врската или обиди се подоцна.'], 503);
        }

        if (! $response->successful()) {
            return response()->json(['error' => 'ujp_rejected', 'status' => $response->status(), 'body' => $response->body()], 422);
        }

        // Response shape ("euids" array of strings) is a best guess — not yet confirmed live.
        // If Task 12 finds a different shape, this is the only place that needs to change.
        $allEuids = $response->json('euids', []);
        $knownEuids = IncomingEfakturaDocument::where('company_id', $company->id)->whereIn('euid', $allEuids)->pluck('euid')->all();
        $newEuids = array_values(array_diff($allEuids, $knownEuids));

        $company->update(['efaktura_purchase_last_checked_at' => now()]);

        return response()->json(['newEuids' => $newEuids, 'dateFrom' => $cached['date_from'], 'dateTo' => $cached['date_to']]);
    }

    public function payloadSigningInput(Request $request, Company $company, EfakturaJwsService $jwsService)
    {
        $this->authorizeDiscovery($company);

        $validated = $request->validate([
            'certificateBase64' => 'required|string',
            'euids' => 'required|array|min:1',
            'euids.*' => 'string',
        ]);

        $payload = [
            'requestTimestamp' => now()->timezone('Europe/Skopje')->format('Y-m-d\TH:i:s'),
            'euids' => $validated['euids'],
        ];
        $result = $jwsService->buildSigningInputForPayload($payload, $validated['certificateBase64']);

        $token = (string) Str::uuid();
        Cache::put("efaktura-incoming-payload:{$token}", [
            'company_id' => $company->id,
            'signing_input' => $result['signingInput'],
        ], now()->addMinutes(10));

        return response()->json(['token' => $token, 'signingInput' => $result['signingInput']]);
    }

    public function payload(Request $request, Company $company, EfakturaJwsService $jwsService)
    {
        $this->authorizeDiscovery($company);

        $validated = $request->validate(['token' => 'required|string', 'signature' => 'required|string']);

        $cached = Cache::pull("efaktura-incoming-payload:{$validated['token']}");
        if (! $cached || $cached['company_id'] !== $company->id) {
            return response()->json(['error' => 'expired_or_invalid_token'], 410);
        }

        try {
            $response = $jwsService->sendPurchaseInvoicePayloadList($company, $cached['signing_input'], $validated['signature']);
        } catch (\Illuminate\Http\Client\ConnectionException $e) {
            return response()->json(['error' => 'ujp_unreachable', 'message' => 'Не можам да се поврзам со серверот на УЈП — провери ја интернет-врската или обиди се подоцна.'], 503);
        }

        if (! $response->successful()) {
            return response()->json(['error' => 'ujp_rejected', 'status' => $response->status(), 'body' => $response->body()], 422);
        }

        // Response shape ("documents" array of {euid, document}) is a best guess — not yet
        // confirmed live. If Task 12 finds a different shape, this is the only place that needs
        // to change.
        $items = $response->json('documents', []);
        $created = 0;

        foreach ($items as $item) {
            $euid = $item['euid'] ?? null;
            $document = $item['document'] ?? null;
            if (! $euid || ! $document || IncomingEfakturaDocument::where('company_id', $company->id)->where('euid', $euid)->exists()) {
                continue;
            }

            $header = $document['header'] ?? [];
            $seller = $document['seller'] ?? [];
            $totals = $document['docTotals'] ?? [];

            IncomingEfakturaDocument::create([
                'company_id' => $company->id,
                'euid' => $euid,
                'doc_number' => $header['docNumber'] ?? null,
                'doc_date' => $header['docDate'] ?? null,
                'seller_name' => $seller['sellerName'] ?? null,
                'seller_tax_id' => $seller['sellerTin'] ?? null,
                'total_amount' => $totals['docGrossAmount'] ?? null,
                'payload_json' => ['document' => $document],
                'discovered_at' => now(),
            ]);
            $created++;
        }

        return response()->json(['status' => 'discovered', 'created' => $created]);
    }

    public function statusSigningInput(Request $request, Company $company, EfakturaJwsService $jwsService)
    {
        $this->authorizeDiscovery($company);

        $validated = $request->validate([
            'certificateBase64' => 'required|string',
            'dateFrom' => 'required|date',
            'dateTo' => 'required|date',
        ]);

        $payload = [
            'requestTimestamp' => now()->timezone('Europe/Skopje')->format('Y-m-d\TH:i:s'),
            'dateFrom' => $validated['dateFrom'],
            'dateTo' => $validated['dateTo'],
        ];
        $result = $jwsService->buildSigningInputForPayload($payload, $validated['certificateBase64']);

        $token = (string) Str::uuid();
        Cache::put("efaktura-incoming-status:{$token}", [
            'company_id' => $company->id,
            'signing_input' => $result['signingInput'],
        ], now()->addMinutes(10));

        return response()->json(['token' => $token, 'signingInput' => $result['signingInput']]);
    }

    public function status(Request $request, Company $company, EfakturaJwsService $jwsService)
    {
        $this->authorizeDiscovery($company);

        $validated = $request->validate(['token' => 'required|string', 'signature' => 'required|string']);

        $cached = Cache::pull("efaktura-incoming-status:{$validated['token']}");
        if (! $cached || $cached['company_id'] !== $company->id) {
            return response()->json(['error' => 'expired_or_invalid_token'], 410);
        }

        try {
            $response = $jwsService->sendPurchaseInvoiceStatus($company, $cached['signing_input'], $validated['signature']);
        } catch (\Illuminate\Http\Client\ConnectionException $e) {
            return response()->json(['error' => 'ujp_unreachable', 'message' => 'Не можам да се поврзам со серверот на УЈП — провери ја интернет-врската или обиди се подоцна.'], 503);
        }

        if (! $response->successful()) {
            return response()->json(['error' => 'ujp_rejected', 'status' => $response->status(), 'body' => $response->body()], 422);
        }

        // Response shape ("invoices" array of {euid, statusCode, statusName}), mirroring the
        // already-verified sales-invoice status-refresh shape, is a best guess for this
        // purchase-invoice endpoint — not yet confirmed live.
        $items = $response->json('invoices', []);
        $updated = 0;

        foreach ($items as $item) {
            $euid = $item['euid'] ?? null;
            if (! $euid) {
                continue;
            }

            $document = IncomingEfakturaDocument::where('company_id', $company->id)->where('euid', $euid)->first();
            if (! $document) {
                continue;
            }

            $document->update([
                'status_code' => $item['statusCode'] ?? null,
                'status_name' => $item['statusName'] ?? null,
            ]);
            $updated++;
        }

        return response()->json(['status' => 'refreshed', 'updated' => $updated]);
    }

    private function authorizeDiscovery(Company $company): void
    {
        Gate::authorize('view', $company);
        abort_unless(auth()->user()->hasAnyRole(['admin', 'accountant']), 403);
        abort_unless(
            $company->efaktura_credential_mode === Company::EFAKTURA_MODE_OWN,
            422,
            'Откривање влезни е-фактури преку фирмениот сертификат сè уште не е поддржано.'
        );
    }
}
```

- [ ] **Step 4: Add routes**

In `routes/web.php`, add `use App\Http\Controllers\EfakturaIncomingDiscoveryController;` near the other `EfakturaXController` imports, and add this route group after the `purchase-invoices.` group:

```php
Route::middleware(['auth'])->prefix('companies/{company}/incoming-efaktura')->name('incoming-efaktura.')->group(function () {
    Route::post('/discover/ids/signing-input', [EfakturaIncomingDiscoveryController::class, 'idsSigningInput'])->name('discover.ids.signing-input');
    Route::post('/discover/ids', [EfakturaIncomingDiscoveryController::class, 'ids'])->name('discover.ids');
    Route::post('/discover/payload/signing-input', [EfakturaIncomingDiscoveryController::class, 'payloadSigningInput'])->name('discover.payload.signing-input');
    Route::post('/discover/payload', [EfakturaIncomingDiscoveryController::class, 'payload'])->name('discover.payload');
    Route::post('/discover/status/signing-input', [EfakturaIncomingDiscoveryController::class, 'statusSigningInput'])->name('discover.status.signing-input');
    Route::post('/discover/status', [EfakturaIncomingDiscoveryController::class, 'status'])->name('discover.status');
});
```

- [ ] **Step 5: Run the tests to verify they pass**

Run: `php artisan test tests/Feature/EfakturaIncomingDiscoveryControllerTest.php`
Expected: 7 passed.

- [ ] **Step 6: Commit**

```bash
git add app/Http/Controllers/EfakturaIncomingDiscoveryController.php routes/web.php tests/Feature/EfakturaIncomingDiscoveryControllerTest.php
git commit -m "feat: add incoming e-invoice discovery endpoints (ids, payload list, status)"
```

---

## Task 8: `EfakturaIncomingAcceptController`

**Files:**
- Create: `app/Http/Controllers/EfakturaIncomingAcceptController.php`
- Modify: `routes/web.php`
- Test: `tests/Feature/EfakturaIncomingAcceptControllerTest.php`

**Interfaces:**
- Consumes: `EfakturaJwsService::sendPurchaseInvoiceAcceptReject()` (Task 5), `IncomingPurchaseInvoiceBuilder::build()` (Task 6), `IncomingEfakturaDocument::DECISION_ACCEPTED` (Task 1)
- Produces: routes `incoming-efaktura.accept.signing-input`, `incoming-efaktura.accept`

- [ ] **Step 1: Write the failing tests**

```php
<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\IncomingEfakturaDocument;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class EfakturaIncomingAcceptControllerTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Role::findOrCreate('admin');
        Role::findOrCreate('client');
    }

    private function makeOwnModeCompany(): Company
    {
        return Company::factory()->create([
            'tax_id' => '4030001234567',
            'efaktura_credential_mode' => Company::EFAKTURA_MODE_OWN,
            'efaktura_eujp_id' => 'EUJP-1',
            'efaktura_token_serial_number' => '1A2B3C',
        ]);
    }

    private function makeUndecidedDocument(Company $company): IncomingEfakturaDocument
    {
        return IncomingEfakturaDocument::factory()->for($company)->create([
            'payload_json' => [
                'document' => [
                    'header' => ['docNumber' => 'SUP-1', 'docDate' => '2026-08-01'],
                    'seller' => ['sellerName' => 'Добавувач', 'sellerTin' => '4030009998887'],
                    'docPayment' => [],
                    'docItems' => [['docItemDesc' => 'Услуга', 'docItemQty' => 1, 'docItemUnitPriceWoVat' => 100, 'docItemTaxIndicator' => 'DDV-A']],
                ],
            ],
        ]);
    }

    private function admin(): User
    {
        $admin = User::factory()->create();
        $admin->assignRole('admin');

        return $admin;
    }

    public function test_signing_input_returns_a_token(): void
    {
        $company = $this->makeOwnModeCompany();
        $document = $this->makeUndecidedDocument($company);

        $response = $this->actingAs($this->admin())->postJson(
            route('incoming-efaktura.accept.signing-input', [$company, $document]),
            ['certificateBase64' => base64_encode('fake-cert')]
        );

        $response->assertOk()->assertJsonStructure(['token', 'signingInput']);
    }

    public function test_store_creates_a_draft_purchase_invoice_and_marks_the_document_accepted(): void
    {
        Http::fake(['*' => Http::response(['status' => 'ok'], 200)]);
        $company = $this->makeOwnModeCompany();
        $document = $this->makeUndecidedDocument($company);
        $admin = $this->admin();

        $signingResponse = $this->actingAs($admin)->postJson(
            route('incoming-efaktura.accept.signing-input', [$company, $document]),
            ['certificateBase64' => base64_encode('fake-cert')]
        )->json();

        $response = $this->actingAs($admin)->postJson(
            route('incoming-efaktura.accept', [$company, $document]),
            ['token' => $signingResponse['token'], 'signature' => 'ZmFrZS1zaWc']
        );

        $response->assertOk()->assertJsonStructure(['status', 'purchaseInvoiceId']);
        $document->refresh();
        $this->assertSame(IncomingEfakturaDocument::DECISION_ACCEPTED, $document->decision);
        $this->assertSame($admin->id, $document->decided_by);
        $this->assertNotNull($document->purchase_invoice_id);
        $this->assertSame('draft', $document->purchaseInvoice->status);
    }

    public function test_store_returns_422_when_already_decided(): void
    {
        Http::fake(['*' => Http::response(['status' => 'ok'], 200)]);
        $company = $this->makeOwnModeCompany();
        $document = $this->makeUndecidedDocument($company);
        $admin = $this->admin();

        $signingResponse = $this->actingAs($admin)->postJson(
            route('incoming-efaktura.accept.signing-input', [$company, $document]),
            ['certificateBase64' => base64_encode('fake-cert')]
        )->json();
        $document->update(['decision' => IncomingEfakturaDocument::DECISION_ACCEPTED]);

        $response = $this->actingAs($admin)->postJson(
            route('incoming-efaktura.accept', [$company, $document]),
            ['token' => $signingResponse['token'], 'signature' => 'ZmFrZS1zaWc']
        );

        $response->assertStatus(422);
    }

    public function test_client_role_is_forbidden(): void
    {
        $company = $this->makeOwnModeCompany();
        $document = $this->makeUndecidedDocument($company);
        $client = User::factory()->create(['company_id' => $company->id]);
        $client->assignRole('client');

        $response = $this->actingAs($client)->postJson(
            route('incoming-efaktura.accept.signing-input', [$company, $document]),
            ['certificateBase64' => base64_encode('fake-cert')]
        );

        $response->assertStatus(403);
    }
}
```

- [ ] **Step 2: Run the tests to verify they fail**

Run: `php artisan test tests/Feature/EfakturaIncomingAcceptControllerTest.php`
Expected: FAIL — route not found.

- [ ] **Step 3: Write the controller**

```php
<?php

namespace App\Http\Controllers;

use App\Models\Company;
use App\Models\IncomingEfakturaDocument;
use App\Services\Efaktura\EfakturaJwsService;
use App\Services\Efaktura\IncomingPurchaseInvoiceBuilder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;

class EfakturaIncomingAcceptController extends Controller
{
    public function signingInput(Request $request, Company $company, IncomingEfakturaDocument $incomingEfakturaDocument, EfakturaJwsService $jwsService)
    {
        $this->authorizeDecision($company, $incomingEfakturaDocument);

        $validated = $request->validate(['certificateBase64' => 'required|string']);

        $payload = [
            'requestTimestamp' => now()->timezone('Europe/Skopje')->format('Y-m-d\TH:i:s'),
            'euid' => $incomingEfakturaDocument->euid,
            'isAccepted' => true,
        ];
        $result = $jwsService->buildSigningInputForPayload($payload, $validated['certificateBase64']);

        $token = (string) Str::uuid();
        Cache::put("efaktura-incoming-accept:{$token}", [
            'company_id' => $company->id,
            'document_id' => $incomingEfakturaDocument->id,
            'signing_input' => $result['signingInput'],
        ], now()->addMinutes(10));

        return response()->json(['token' => $token, 'signingInput' => $result['signingInput']]);
    }

    public function store(Request $request, Company $company, IncomingEfakturaDocument $incomingEfakturaDocument, EfakturaJwsService $jwsService, IncomingPurchaseInvoiceBuilder $builder)
    {
        $this->authorizeDecision($company, $incomingEfakturaDocument);

        $validated = $request->validate(['token' => 'required|string', 'signature' => 'required|string']);

        $cached = Cache::pull("efaktura-incoming-accept:{$validated['token']}");
        if (! $cached || $cached['company_id'] !== $company->id || $cached['document_id'] !== $incomingEfakturaDocument->id) {
            return response()->json(['error' => 'expired_or_invalid_token'], 410);
        }

        try {
            $response = $jwsService->sendPurchaseInvoiceAcceptReject($company, $cached['signing_input'], $validated['signature']);
        } catch (\Illuminate\Http\Client\ConnectionException $e) {
            return response()->json(['error' => 'ujp_unreachable', 'message' => 'Не можам да се поврзам со серверот на УЈП — провери ја интернет-врската или обиди се подоцна.'], 503);
        }

        if (! $response->successful()) {
            return response()->json(['error' => 'ujp_rejected', 'status' => $response->status(), 'body' => $response->body()], 422);
        }

        $invoice = $builder->build($company, $incomingEfakturaDocument->payload_json, $request->user());

        $incomingEfakturaDocument->update([
            'decision' => IncomingEfakturaDocument::DECISION_ACCEPTED,
            'decided_at' => now(),
            'decided_by' => $request->user()->id,
            'purchase_invoice_id' => $invoice->id,
        ]);

        return response()->json(['status' => 'accepted', 'purchaseInvoiceId' => $invoice->id]);
    }

    private function authorizeDecision(Company $company, IncomingEfakturaDocument $incomingEfakturaDocument): void
    {
        Gate::authorize('view', $company);
        abort_if($incomingEfakturaDocument->company_id !== $company->id, 404);
        abort_unless(auth()->user()->hasAnyRole(['admin', 'accountant']), 403);
        abort_unless(
            $company->efaktura_credential_mode === Company::EFAKTURA_MODE_OWN,
            422,
            'Прифаќање влезна е-фактура преку фирмениот сертификат сè уште не е поддржано.'
        );
        abort_if($incomingEfakturaDocument->decision !== null, 422, 'Веќе е одлучено за оваа фактура.');
    }
}
```

- [ ] **Step 4: Add routes**

In `routes/web.php`, add `use App\Http\Controllers\EfakturaIncomingAcceptController;` and this route group after the discovery group from Task 7:

```php
Route::middleware(['auth'])->prefix('companies/{company}/incoming-efaktura/{incomingEfakturaDocument}')->name('incoming-efaktura.')->group(function () {
    Route::post('/accept/signing-input', [EfakturaIncomingAcceptController::class, 'signingInput'])->name('accept.signing-input');
    Route::post('/accept', [EfakturaIncomingAcceptController::class, 'store'])->name('accept');
});
```

- [ ] **Step 5: Run the tests to verify they pass**

Run: `php artisan test tests/Feature/EfakturaIncomingAcceptControllerTest.php`
Expected: 4 passed.

- [ ] **Step 6: Commit**

```bash
git add app/Http/Controllers/EfakturaIncomingAcceptController.php routes/web.php tests/Feature/EfakturaIncomingAcceptControllerTest.php
git commit -m "feat: add incoming e-invoice accept endpoint (creates draft purchase invoice)"
```

---

## Task 9: `EfakturaIncomingRejectController`

**Files:**
- Create: `app/Http/Controllers/EfakturaIncomingRejectController.php`
- Modify: `routes/web.php`
- Test: `tests/Feature/EfakturaIncomingRejectControllerTest.php`

**Interfaces:**
- Consumes: `EfakturaJwsService::sendPurchaseInvoiceAcceptReject()` (Task 5), `IncomingEfakturaDocument::REJECT_REASONS`/`REJECT_REASON_OTHER`/`DECISION_REJECTED` (Task 1)
- Produces: routes `incoming-efaktura.reject.signing-input`, `incoming-efaktura.reject`

- [ ] **Step 1: Write the failing tests**

```php
<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\IncomingEfakturaDocument;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class EfakturaIncomingRejectControllerTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Role::findOrCreate('admin');
        Role::findOrCreate('client');
    }

    private function makeOwnModeCompany(): Company
    {
        return Company::factory()->create([
            'tax_id' => '4030001234567',
            'efaktura_credential_mode' => Company::EFAKTURA_MODE_OWN,
            'efaktura_eujp_id' => 'EUJP-1',
            'efaktura_token_serial_number' => '1A2B3C',
        ]);
    }

    private function admin(): User
    {
        $admin = User::factory()->create();
        $admin->assignRole('admin');

        return $admin;
    }

    public function test_signing_input_requires_a_known_reason_code(): void
    {
        $company = $this->makeOwnModeCompany();
        $document = IncomingEfakturaDocument::factory()->for($company)->create();

        $response = $this->actingAs($this->admin())->postJson(
            route('incoming-efaktura.reject.signing-input', [$company, $document]),
            ['certificateBase64' => base64_encode('fake-cert'), 'reasonCode' => 'NOT-A-CODE']
        );

        $response->assertStatus(422);
    }

    public function test_signing_input_requires_a_comment_when_reason_is_other(): void
    {
        $company = $this->makeOwnModeCompany();
        $document = IncomingEfakturaDocument::factory()->for($company)->create();

        $response = $this->actingAs($this->admin())->postJson(
            route('incoming-efaktura.reject.signing-input', [$company, $document]),
            ['certificateBase64' => base64_encode('fake-cert'), 'reasonCode' => IncomingEfakturaDocument::REJECT_REASON_OTHER]
        );

        $response->assertStatus(422);
    }

    public function test_signing_input_returns_a_token_for_a_valid_reason(): void
    {
        $company = $this->makeOwnModeCompany();
        $document = IncomingEfakturaDocument::factory()->for($company)->create();

        $response = $this->actingAs($this->admin())->postJson(
            route('incoming-efaktura.reject.signing-input', [$company, $document]),
            ['certificateBase64' => base64_encode('fake-cert'), 'reasonCode' => 'O-4']
        );

        $response->assertOk()->assertJsonStructure(['token', 'signingInput']);
    }

    public function test_store_marks_the_document_rejected_with_reason(): void
    {
        Http::fake(['*' => Http::response(['status' => 'ok'], 200)]);
        $company = $this->makeOwnModeCompany();
        $document = IncomingEfakturaDocument::factory()->for($company)->create();
        $admin = $this->admin();

        $signingResponse = $this->actingAs($admin)->postJson(
            route('incoming-efaktura.reject.signing-input', [$company, $document]),
            ['certificateBase64' => base64_encode('fake-cert'), 'reasonCode' => 'O-4']
        )->json();

        $response = $this->actingAs($admin)->postJson(
            route('incoming-efaktura.reject', [$company, $document]),
            ['token' => $signingResponse['token'], 'signature' => 'ZmFrZS1zaWc']
        );

        $response->assertOk()->assertJson(['status' => 'rejected']);
        $document->refresh();
        $this->assertSame(IncomingEfakturaDocument::DECISION_REJECTED, $document->decision);
        $this->assertSame('O-4', $document->reject_reason_code);
        $this->assertNull($document->purchase_invoice_id);
    }

    public function test_client_role_is_forbidden(): void
    {
        $company = $this->makeOwnModeCompany();
        $document = IncomingEfakturaDocument::factory()->for($company)->create();
        $client = User::factory()->create(['company_id' => $company->id]);
        $client->assignRole('client');

        $response = $this->actingAs($client)->postJson(
            route('incoming-efaktura.reject.signing-input', [$company, $document]),
            ['certificateBase64' => base64_encode('fake-cert'), 'reasonCode' => 'O-4']
        );

        $response->assertStatus(403);
    }
}
```

- [ ] **Step 2: Run the tests to verify they fail**

Run: `php artisan test tests/Feature/EfakturaIncomingRejectControllerTest.php`
Expected: FAIL — route not found.

- [ ] **Step 3: Write the controller**

```php
<?php

namespace App\Http\Controllers;

use App\Models\Company;
use App\Models\IncomingEfakturaDocument;
use App\Services\Efaktura\EfakturaJwsService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class EfakturaIncomingRejectController extends Controller
{
    public function signingInput(Request $request, Company $company, IncomingEfakturaDocument $incomingEfakturaDocument, EfakturaJwsService $jwsService)
    {
        $this->authorizeDecision($company, $incomingEfakturaDocument);

        $validated = $request->validate([
            'certificateBase64' => 'required|string',
            'reasonCode' => ['required', Rule::in(array_keys(IncomingEfakturaDocument::REJECT_REASONS))],
            'comment' => [
                Rule::requiredIf($request->input('reasonCode') === IncomingEfakturaDocument::REJECT_REASON_OTHER),
                'nullable', 'string', 'max:255',
            ],
        ]);

        $payload = [
            'requestTimestamp' => now()->timezone('Europe/Skopje')->format('Y-m-d\TH:i:s'),
            'euid' => $incomingEfakturaDocument->euid,
            'isAccepted' => false,
            'rejectReasonCode' => $validated['reasonCode'],
            'comment' => $validated['comment'] ?? null,
        ];
        $result = $jwsService->buildSigningInputForPayload($payload, $validated['certificateBase64']);

        $token = (string) Str::uuid();
        Cache::put("efaktura-incoming-reject:{$token}", [
            'company_id' => $company->id,
            'document_id' => $incomingEfakturaDocument->id,
            'signing_input' => $result['signingInput'],
            'reason_code' => $validated['reasonCode'],
            'comment' => $validated['comment'] ?? null,
        ], now()->addMinutes(10));

        return response()->json(['token' => $token, 'signingInput' => $result['signingInput']]);
    }

    public function store(Request $request, Company $company, IncomingEfakturaDocument $incomingEfakturaDocument, EfakturaJwsService $jwsService)
    {
        $this->authorizeDecision($company, $incomingEfakturaDocument);

        $validated = $request->validate(['token' => 'required|string', 'signature' => 'required|string']);

        $cached = Cache::pull("efaktura-incoming-reject:{$validated['token']}");
        if (! $cached || $cached['company_id'] !== $company->id || $cached['document_id'] !== $incomingEfakturaDocument->id) {
            return response()->json(['error' => 'expired_or_invalid_token'], 410);
        }

        try {
            $response = $jwsService->sendPurchaseInvoiceAcceptReject($company, $cached['signing_input'], $validated['signature']);
        } catch (\Illuminate\Http\Client\ConnectionException $e) {
            return response()->json(['error' => 'ujp_unreachable', 'message' => 'Не можам да се поврзам со серверот на УЈП — провери ја интернет-врската или обиди се подоцна.'], 503);
        }

        if (! $response->successful()) {
            return response()->json(['error' => 'ujp_rejected', 'status' => $response->status(), 'body' => $response->body()], 422);
        }

        $incomingEfakturaDocument->update([
            'decision' => IncomingEfakturaDocument::DECISION_REJECTED,
            'decided_at' => now(),
            'decided_by' => $request->user()->id,
            'reject_reason_code' => $cached['reason_code'],
            'reject_comment' => $cached['comment'],
        ]);

        return response()->json(['status' => 'rejected']);
    }

    private function authorizeDecision(Company $company, IncomingEfakturaDocument $incomingEfakturaDocument): void
    {
        Gate::authorize('view', $company);
        abort_if($incomingEfakturaDocument->company_id !== $company->id, 404);
        abort_unless(auth()->user()->hasAnyRole(['admin', 'accountant']), 403);
        abort_unless(
            $company->efaktura_credential_mode === Company::EFAKTURA_MODE_OWN,
            422,
            'Одбивање влезна е-фактура преку фирмениот сертификат сè уште не е поддржано.'
        );
        abort_if($incomingEfakturaDocument->decision !== null, 422, 'Веќе е одлучено за оваа фактура.');
    }
}
```

- [ ] **Step 4: Add routes**

In `routes/web.php`, add `use App\Http\Controllers\EfakturaIncomingRejectController;` and extend the same `companies/{company}/incoming-efaktura/{incomingEfakturaDocument}` group added in Task 8:

```php
    Route::post('/reject/signing-input', [EfakturaIncomingRejectController::class, 'signingInput'])->name('reject.signing-input');
    Route::post('/reject', [EfakturaIncomingRejectController::class, 'store'])->name('reject');
```

- [ ] **Step 5: Run the tests to verify they pass**

Run: `php artisan test tests/Feature/EfakturaIncomingRejectControllerTest.php`
Expected: 5 passed.

- [ ] **Step 6: Commit**

```bash
git add app/Http/Controllers/EfakturaIncomingRejectController.php routes/web.php tests/Feature/EfakturaIncomingRejectControllerTest.php
git commit -m "feat: add incoming e-invoice reject endpoint"
```

---

## Task 10: `EfakturaIncomingPdfController`

**Files:**
- Create: `app/Http/Controllers/EfakturaIncomingPdfController.php`
- Modify: `routes/web.php`
- Test: `tests/Feature/EfakturaIncomingPdfControllerTest.php`

**Interfaces:**
- Consumes: `EfakturaJwsService::sendPurchaseInvoicePdfFetch()` (Task 5), `IncomingEfakturaDocument::DECISION_ACCEPTED` (Task 1)
- Produces: routes `incoming-efaktura.pdf.signing-input`, `incoming-efaktura.pdf.store`, `incoming-efaktura.pdf.download`

- [ ] **Step 1: Write the failing tests**

```php
<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\IncomingEfakturaDocument;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class EfakturaIncomingPdfControllerTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Role::findOrCreate('admin');
        Role::findOrCreate('client');
        Storage::fake('local');
    }

    private function makeOwnModeCompany(): Company
    {
        return Company::factory()->create([
            'tax_id' => '4030001234567',
            'efaktura_credential_mode' => Company::EFAKTURA_MODE_OWN,
            'efaktura_eujp_id' => 'EUJP-1',
            'efaktura_token_serial_number' => '1A2B3C',
        ]);
    }

    private function admin(): User
    {
        $admin = User::factory()->create();
        $admin->assignRole('admin');

        return $admin;
    }

    public function test_signing_input_returns_a_token_for_an_accepted_document(): void
    {
        $company = $this->makeOwnModeCompany();
        $document = IncomingEfakturaDocument::factory()->for($company)->create(['decision' => IncomingEfakturaDocument::DECISION_ACCEPTED]);

        $response = $this->actingAs($this->admin())->postJson(
            route('incoming-efaktura.pdf.signing-input', [$company, $document]),
            ['certificateBase64' => base64_encode('fake-cert')]
        );

        $response->assertOk()->assertJsonStructure(['token', 'signingInput']);
    }

    public function test_signing_input_rejects_a_document_not_yet_accepted(): void
    {
        $company = $this->makeOwnModeCompany();
        $document = IncomingEfakturaDocument::factory()->for($company)->create(['decision' => null]);

        $response = $this->actingAs($this->admin())->postJson(
            route('incoming-efaktura.pdf.signing-input', [$company, $document]),
            ['certificateBase64' => base64_encode('fake-cert')]
        );

        $response->assertStatus(422);
    }

    public function test_store_saves_the_pdf_and_download_serves_it(): void
    {
        Http::fake(['*' => Http::response(['pdfBase64' => base64_encode('fake-pdf-bytes')], 200)]);
        $company = $this->makeOwnModeCompany();
        $document = IncomingEfakturaDocument::factory()->for($company)->create([
            'decision' => IncomingEfakturaDocument::DECISION_ACCEPTED,
            'doc_number' => 'SUP-1',
        ]);
        $admin = $this->admin();

        $signingResponse = $this->actingAs($admin)->postJson(
            route('incoming-efaktura.pdf.signing-input', [$company, $document]),
            ['certificateBase64' => base64_encode('fake-cert')]
        )->json();

        $storeResponse = $this->actingAs($admin)->postJson(
            route('incoming-efaktura.pdf.store', [$company, $document]),
            ['token' => $signingResponse['token'], 'signature' => 'ZmFrZS1zaWc']
        );

        $storeResponse->assertOk()->assertJson(['status' => 'saved']);
        $this->assertNotNull($document->fresh()->efaktura_pdf_path);

        $downloadResponse = $this->actingAs($admin)->get(route('incoming-efaktura.pdf.download', [$company, $document]));
        $downloadResponse->assertOk();
    }

    public function test_client_role_is_forbidden(): void
    {
        $company = $this->makeOwnModeCompany();
        $document = IncomingEfakturaDocument::factory()->for($company)->create(['decision' => IncomingEfakturaDocument::DECISION_ACCEPTED]);
        $client = User::factory()->create(['company_id' => $company->id]);
        $client->assignRole('client');

        $response = $this->actingAs($client)->postJson(
            route('incoming-efaktura.pdf.signing-input', [$company, $document]),
            ['certificateBase64' => base64_encode('fake-cert')]
        );

        $response->assertStatus(403);
    }
}
```

- [ ] **Step 2: Run the tests to verify they fail**

Run: `php artisan test tests/Feature/EfakturaIncomingPdfControllerTest.php`
Expected: FAIL — route not found.

- [ ] **Step 3: Write the controller**

```php
<?php

namespace App\Http\Controllers;

use App\Models\Company;
use App\Models\IncomingEfakturaDocument;
use App\Services\Efaktura\EfakturaJwsService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class EfakturaIncomingPdfController extends Controller
{
    public function signingInput(Request $request, Company $company, IncomingEfakturaDocument $incomingEfakturaDocument, EfakturaJwsService $jwsService)
    {
        $this->authorizePdf($company, $incomingEfakturaDocument);

        $validated = $request->validate(['certificateBase64' => 'required|string']);

        $payload = [
            'requestTimestamp' => now()->timezone('Europe/Skopje')->format('Y-m-d\TH:i:s'),
            'euid' => $incomingEfakturaDocument->euid,
        ];
        $result = $jwsService->buildSigningInputForPayload($payload, $validated['certificateBase64']);

        $token = (string) Str::uuid();
        Cache::put("efaktura-incoming-pdf:{$token}", [
            'company_id' => $company->id,
            'document_id' => $incomingEfakturaDocument->id,
            'signing_input' => $result['signingInput'],
        ], now()->addMinutes(10));

        return response()->json(['token' => $token, 'signingInput' => $result['signingInput']]);
    }

    public function store(Request $request, Company $company, IncomingEfakturaDocument $incomingEfakturaDocument, EfakturaJwsService $jwsService)
    {
        $this->authorizePdf($company, $incomingEfakturaDocument);

        $validated = $request->validate(['token' => 'required|string', 'signature' => 'required|string']);

        $cached = Cache::pull("efaktura-incoming-pdf:{$validated['token']}");
        if (! $cached || $cached['company_id'] !== $company->id || $cached['document_id'] !== $incomingEfakturaDocument->id) {
            return response()->json(['error' => 'expired_or_invalid_token'], 410);
        }

        try {
            $response = $jwsService->sendPurchaseInvoicePdfFetch($company, $cached['signing_input'], $validated['signature']);
        } catch (\Illuminate\Http\Client\ConnectionException $e) {
            return response()->json(['error' => 'ujp_unreachable', 'message' => 'Не можам да се поврзам со серверот на УЈП — провери ја интернет-врската или обиди се подоцна.'], 503);
        }

        if (! $response->successful()) {
            return response()->json(['error' => 'ujp_rejected', 'status' => $response->status(), 'body' => $response->body()], 422);
        }

        $pdfBase64 = $response->json('pdfBase64');
        if (! $pdfBase64) {
            return response()->json(['error' => 'ujp_response_missing_pdf', 'body' => $response->body()], 422);
        }

        $path = "efaktura-pdfs/incoming/{$company->id}/{$incomingEfakturaDocument->id}.pdf";
        Storage::disk('local')->put($path, base64_decode($pdfBase64));
        $incomingEfakturaDocument->update(['efaktura_pdf_path' => $path]);

        return response()->json(['status' => 'saved']);
    }

    public function download(Company $company, IncomingEfakturaDocument $incomingEfakturaDocument)
    {
        Gate::authorize('view', $company);
        abort_if($incomingEfakturaDocument->company_id !== $company->id, 404);
        abort_unless($incomingEfakturaDocument->efaktura_pdf_path && Storage::disk('local')->exists($incomingEfakturaDocument->efaktura_pdf_path), 404);

        $filename = "vlezna-faktura-{$incomingEfakturaDocument->doc_number}.pdf";

        return Storage::disk('local')->download($incomingEfakturaDocument->efaktura_pdf_path, $filename);
    }

    private function authorizePdf(Company $company, IncomingEfakturaDocument $incomingEfakturaDocument): void
    {
        Gate::authorize('view', $company);
        abort_if($incomingEfakturaDocument->company_id !== $company->id, 404);
        abort_unless(auth()->user()->hasAnyRole(['admin', 'accountant']), 403);
        abort_unless(
            $company->efaktura_credential_mode === Company::EFAKTURA_MODE_OWN,
            422,
            'Преземање официјален ПДФ преку фирмениот сертификат сè уште не е поддржано.'
        );
        abort_if($incomingEfakturaDocument->efaktura_pdf_path && Storage::disk('local')->exists($incomingEfakturaDocument->efaktura_pdf_path), 422, 'ПДФ-от е веќе преземен.');
        abort_unless($incomingEfakturaDocument->decision === IncomingEfakturaDocument::DECISION_ACCEPTED, 422, 'Фактурата сè уште не е прифатена.');
    }
}
```

- [ ] **Step 4: Add routes**

In `routes/web.php`, add `use App\Http\Controllers\EfakturaIncomingPdfController;` and extend the same `companies/{company}/incoming-efaktura/{incomingEfakturaDocument}` group from Tasks 8-9:

```php
    Route::post('/pdf/signing-input', [EfakturaIncomingPdfController::class, 'signingInput'])->name('pdf.signing-input');
    Route::post('/pdf', [EfakturaIncomingPdfController::class, 'store'])->name('pdf.store');
    Route::get('/pdf/download', [EfakturaIncomingPdfController::class, 'download'])->name('pdf.download');
```

- [ ] **Step 5: Run the tests to verify they pass**

Run: `php artisan test tests/Feature/EfakturaIncomingPdfControllerTest.php`
Expected: 4 passed.

- [ ] **Step 6: Commit**

```bash
git add app/Http/Controllers/EfakturaIncomingPdfController.php routes/web.php tests/Feature/EfakturaIncomingPdfControllerTest.php
git commit -m "feat: add official PDF fetch-and-cache endpoint for accepted incoming invoices"
```

---

## Task 11: „Влезни е-Фактури" screen (Livewire + Blade + Alpine JS + sidebar link)

**Files:**
- Create: `app/Livewire/Invoicing/IncomingEfakturaIndex.php`
- Create: `resources/views/livewire/invoicing/incoming-efaktura-index.blade.php`
- Modify: `routes/web.php`
- Modify: `resources/views/livewire/layout/sidebar.blade.php`
- Test: `tests/Feature/IncomingEfakturaIndexTest.php`

**Interfaces:**
- Consumes: all six `incoming-efaktura.discover.*` routes (Task 7), `incoming-efaktura.accept*` (Task 8), `incoming-efaktura.reject*` (Task 9), `incoming-efaktura.pdf.*` (Task 10), `IncomingEfakturaDocument::REJECT_REASONS` (Task 1)
- Produces: route `incoming-efaktura.index`, page at `/companies/{company}/incoming-efaktura`

- [ ] **Step 1: Write the failing test**

```php
<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\IncomingEfakturaDocument;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class IncomingEfakturaIndexTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Role::findOrCreate('admin');
    }

    public function test_page_lists_incoming_documents(): void
    {
        $company = Company::factory()->create(['efaktura_credential_mode' => Company::EFAKTURA_MODE_OWN]);
        $document = IncomingEfakturaDocument::factory()->for($company)->create(['seller_name' => 'Тест Добавувач ДООЕЛ']);
        $admin = User::factory()->create();
        $admin->assignRole('admin');

        $this->actingAs($admin)
            ->get(route('incoming-efaktura.index', $company))
            ->assertOk()
            ->assertSee('Тест Добавувач ДООЕЛ');
    }

    public function test_page_shows_empty_state_with_no_documents(): void
    {
        $company = Company::factory()->create();
        $admin = User::factory()->create();
        $admin->assignRole('admin');

        $this->actingAs($admin)
            ->get(route('incoming-efaktura.index', $company))
            ->assertOk()
            ->assertSee('Нема пронајдени влезни е-фактури.');
    }
}
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `php artisan test tests/Feature/IncomingEfakturaIndexTest.php`
Expected: FAIL — route not found.

- [ ] **Step 3: Write the Livewire component**

```php
<?php

namespace App\Livewire\Invoicing;

use App\Models\Company;
use App\Models\IncomingEfakturaDocument;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Layout;
use Livewire\Component;

#[Layout('layouts.app')]
class IncomingEfakturaIndex extends Component
{
    public Company $company;

    public function mount(Company $company): void
    {
        Gate::authorize('view', $company);
        $this->company = $company;
    }

    public function render()
    {
        $documents = IncomingEfakturaDocument::where('company_id', $this->company->id)
            ->orderByDesc('doc_date')
            ->orderByDesc('id')
            ->get();

        return view('livewire.invoicing.incoming-efaktura-index', ['documents' => $documents]);
    }
}
```

- [ ] **Step 4: Write the Blade view**

```blade
<div x-data="incomingEfakturaDiscover()">
    <div class="flex items-center justify-between mb-4">
        <h1 class="text-2xl font-bold text-gray-800">Влезни е-Фактури — {{ $company->name }}</h1>
        @if ($company->hasEfakturaAccess() && $company->efaktura_credential_mode === \App\Models\Company::EFAKTURA_MODE_OWN)
            <button type="button" @click="run()" :disabled="busy" class="border border-brand text-brand px-3 py-1.5 rounded-md text-sm disabled:opacity-50">
                <span x-show="!busy">Провери за нови фактури</span>
                <span x-show="busy" x-text="statusText"></span>
            </button>
        @endif
    </div>

    <p x-show="error" x-text="error" class="text-red-600 text-sm mb-3"></p>
    <p x-show="result" x-text="result" class="text-green-700 text-sm mb-3"></p>

    <p class="text-sm text-gray-500 mb-4">
        Последна проверка:
        {{ $company->efaktura_purchase_last_checked_at ? \App\Support\Format::date($company->efaktura_purchase_last_checked_at) : 'никогаш' }}
    </p>

    <x-card padding="p-0" class="overflow-hidden">
    <table class="min-w-full divide-y divide-gray-200">
        <thead>
            <tr class="text-left text-sm text-gray-500">
                <th class="py-2 px-4">Добавувач</th>
                <th class="py-2 px-4">Датум</th>
                <th class="py-2 px-4">Износ</th>
                <th class="py-2 px-4">Статус кај УЈП</th>
                <th class="py-2 px-4">Наша одлука</th>
                <th class="py-2 px-4"></th>
            </tr>
        </thead>
        <tbody class="divide-y divide-gray-100">
            @forelse ($documents as $document)
                <tr class="text-sm">
                    <td class="py-2 px-4">{{ $document->seller_name }} <span class="text-gray-400">({{ $document->seller_tax_id }})</span></td>
                    <td class="py-2 px-4">{{ $document->doc_date ? \App\Support\Format::date($document->doc_date) : '—' }}</td>
                    <td class="py-2 px-4">{{ $document->total_amount !== null ? \App\Support\Format::money($document->total_amount) : '—' }}</td>
                    <td class="py-2 px-4">
                        @if ($document->status_name)
                            <x-badge status="pending">{{ $document->status_name }}</x-badge>
                        @else
                            <span class="text-gray-400">—</span>
                        @endif
                    </td>
                    <td class="py-2 px-4">
                        @if ($document->decision === \App\Models\IncomingEfakturaDocument::DECISION_ACCEPTED)
                            <x-badge status="active">Прифатена</x-badge>
                        @elseif ($document->decision === \App\Models\IncomingEfakturaDocument::DECISION_REJECTED)
                            <x-badge status="overdue">Одбиена</x-badge>
                        @else
                            <span class="text-gray-400">Неодлучено</span>
                        @endif
                    </td>
                    <td class="py-2 px-4">
                        @if ($document->decision === null)
                            <div x-data="incomingEfakturaAccept({{ $document->id }})" class="inline-block mr-2 align-top">
                                <button type="button" @click="run()" :disabled="busy" class="text-brand hover:underline disabled:opacity-50">
                                    <span x-show="!busy">Прифати</span>
                                    <span x-show="busy" x-text="statusText"></span>
                                </button>
                                <p x-show="error" x-text="error" class="text-red-600 text-xs mt-1"></p>
                            </div>
                            <div x-data="incomingEfakturaReject({{ $document->id }})" class="inline-block align-top">
                                <button type="button" @click="open = !open" class="text-red-600 hover:underline">Одбиј</button>
                                <div x-show="open" class="mt-2 p-2 border border-gray-200 rounded-md bg-gray-50 w-64">
                                    <select x-model="reasonCode" class="border-gray-300 rounded-md text-sm w-full mb-1">
                                        <option value="">Избери причина...</option>
                                        @foreach (\App\Models\IncomingEfakturaDocument::REJECT_REASONS as $code => $label)
                                            <option value="{{ $code }}">{{ $code }} — {{ $label }}</option>
                                        @endforeach
                                    </select>
                                    <input x-show="reasonCode === @js(\App\Models\IncomingEfakturaDocument::REJECT_REASON_OTHER)" x-model="comment" type="text" placeholder="Причина (слободен текст)" class="border-gray-300 rounded-md text-sm w-full mb-1">
                                    <button type="button" @click="run()" :disabled="busy || !reasonCode" class="text-red-600 text-sm disabled:opacity-50">
                                        <span x-show="!busy">Потврди одбивање</span>
                                        <span x-show="busy" x-text="statusText"></span>
                                    </button>
                                    <p x-show="error" x-text="error" class="text-red-600 text-xs mt-1"></p>
                                </div>
                            </div>
                        @elseif ($document->decision === \App\Models\IncomingEfakturaDocument::DECISION_ACCEPTED)
                            <a href="{{ route('purchase-invoices.show', [$company, $document->purchase_invoice_id]) }}" class="text-brand hover:underline mr-2">Прегледај фактура</a>
                            @if ($document->efaktura_pdf_path)
                                <a href="{{ route('incoming-efaktura.pdf.download', [$company, $document]) }}" class="text-brand hover:underline">Преземи ПДФ</a>
                            @else
                                <div x-data="incomingEfakturaPdfFetch({{ $document->id }})" class="inline-block">
                                    <button type="button" @click="run()" :disabled="busy" class="text-brand hover:underline disabled:opacity-50">
                                        <span x-show="!busy">Преземи ПДФ</span>
                                        <span x-show="busy" x-text="statusText"></span>
                                    </button>
                                    <p x-show="error" x-text="error" class="text-red-600 text-xs mt-1"></p>
                                </div>
                            @endif
                        @else
                            <span class="text-gray-400">{{ $document->reject_reason_code }}</span>
                        @endif
                    </td>
                </tr>
            @empty
                <tr><td colspan="6" class="py-4 px-4 text-gray-500">Нема пронајдени влезни е-фактури.</td></tr>
            @endforelse
        </tbody>
    </table>
    </x-card>

    @script
    <script>
        const toBase64Url = (str) => btoa(str).replace(/\+/g, '-').replace(/\//g, '_').replace(/=+$/, '');

        // Shared "sign this JSON body with the local bridge" step used by every flow below:
        // POST signingInputUrl -> get {token, signingInput, ...extra}, POST the signingInput to
        // the bridge's /sign, return {token, signature, ...extra}. Declared as `const ... = (...) =>`,
        // not `function name(){}` — @script blocks evaluate as one expression, so a plain
        // function declaration is invisible outside itself (a real bug already hit and fixed in
        // Phase 8b-ii's sales-invoice-index.blade.php).
        const signViaBridge = async (signingInputUrl, requestBody) => {
            const signingRes = await fetch(signingInputUrl, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content },
                body: JSON.stringify(requestBody),
            });
            if (!signingRes.ok) {
                const errorBody = await signingRes.json().catch(() => null);
                throw new Error(errorBody?.message ?? errorBody?.error ?? 'Серверот не можеше да го подготви текстот за потпишување.');
            }
            const signingJson = await signingRes.json();

            const signRes = await fetch('http://127.0.0.1:9847/sign', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ data: toBase64Url(signingJson.signingInput) }),
            });
            if (!signRes.ok) throw new Error('Потпишувањето не успеа — провери го PIN-от на токенот.');
            const { signature } = await signRes.json();

            return { ...signingJson, signature };
        };

        const readTokenCertificate = async (expectedSerial) => {
            const health = await fetch('http://127.0.0.1:9847/health').catch(() => null);
            if (!health || !health.ok) throw new Error('Локалниот потпишувач не работи. Стартувај го и обиди се повторно.');

            const certRes = await fetch('http://127.0.0.1:9847/certificate');
            if (!certRes.ok) throw new Error('Не можам да ги прочитам податоците од токенот.');
            const cert = await certRes.json();
            if (cert.serialNumber !== expectedSerial) throw new Error('Приклучениот токен не одговара на регистрираниот за оваа компанија.');

            return cert;
        };

        const postJson = async (url, body) => {
            const res = await fetch(url, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content },
                body: JSON.stringify(body),
            });
            if (!res.ok) {
                const errorBody = await res.json().catch(() => null);
                const message = errorBody?.error === 'ujp_rejected'
                    ? `УЈП го одби барањето: ${errorBody.body}`
                    : (errorBody?.message ?? errorBody?.error ?? 'Барањето не успеа.');
                throw new Error(message);
            }

            return res.json();
        };

        Alpine.data('incomingEfakturaDiscover', () => ({
            busy: false,
            error: '',
            result: '',
            statusText: '',
            async run() {
                this.busy = true; this.error = ''; this.result = '';
                try {
                    const cert = await readTokenCertificate(@js($company->efaktura_token_serial_number));

                    this.statusText = 'Барам список на нови фактури...';
                    const idsSigned = await signViaBridge(
                        @js(route('incoming-efaktura.discover.ids.signing-input', $company)),
                        { certificateBase64: cert.certificateBase64 }
                    );
                    const { newEuids, dateFrom, dateTo } = await postJson(
                        @js(route('incoming-efaktura.discover.ids', $company)),
                        { token: idsSigned.token, signature: idsSigned.signature }
                    );

                    if (newEuids.length > 0) {
                        this.statusText = `Преземам ${newEuids.length} нови фактури...`;
                        const payloadSigned = await signViaBridge(
                            @js(route('incoming-efaktura.discover.payload.signing-input', $company)),
                            { certificateBase64: cert.certificateBase64, euids: newEuids }
                        );
                        await postJson(
                            @js(route('incoming-efaktura.discover.payload', $company)),
                            { token: payloadSigned.token, signature: payloadSigned.signature }
                        );
                    }

                    this.statusText = 'Ги освежувам статусите...';
                    const statusSigned = await signViaBridge(
                        @js(route('incoming-efaktura.discover.status.signing-input', $company)),
                        { certificateBase64: cert.certificateBase64, dateFrom, dateTo }
                    );
                    await postJson(
                        @js(route('incoming-efaktura.discover.status', $company)),
                        { token: statusSigned.token, signature: statusSigned.signature }
                    );

                    this.result = `Пронајдени ${newEuids.length} нови фактури.`;
                    window.location.reload();
                } catch (e) {
                    this.error = e.message;
                } finally {
                    this.busy = false;
                }
            },
        }));

        Alpine.data('incomingEfakturaAccept', (documentId) => ({
            busy: false,
            error: '',
            statusText: '',
            async run() {
                this.busy = true; this.error = '';
                try {
                    const cert = await readTokenCertificate(@js($company->efaktura_token_serial_number));

                    this.statusText = 'Прифаќам (проверете го прозорецот на SafeNet)...';
                    const signed = await signViaBridge(
                        `/companies/{{ $company->id }}/incoming-efaktura/${documentId}/accept/signing-input`,
                        { certificateBase64: cert.certificateBase64 }
                    );
                    await postJson(
                        `/companies/{{ $company->id }}/incoming-efaktura/${documentId}/accept`,
                        { token: signed.token, signature: signed.signature }
                    );

                    window.location.reload();
                } catch (e) {
                    this.error = e.message;
                } finally {
                    this.busy = false;
                }
            },
        }));

        Alpine.data('incomingEfakturaReject', (documentId) => ({
            open: false,
            busy: false,
            error: '',
            statusText: '',
            reasonCode: '',
            comment: '',
            async run() {
                this.busy = true; this.error = '';
                try {
                    const cert = await readTokenCertificate(@js($company->efaktura_token_serial_number));

                    this.statusText = 'Одбивам (проверете го прозорецот на SafeNet)...';
                    const signed = await signViaBridge(
                        `/companies/{{ $company->id }}/incoming-efaktura/${documentId}/reject/signing-input`,
                        { certificateBase64: cert.certificateBase64, reasonCode: this.reasonCode, comment: this.comment || null }
                    );
                    await postJson(
                        `/companies/{{ $company->id }}/incoming-efaktura/${documentId}/reject`,
                        { token: signed.token, signature: signed.signature }
                    );

                    window.location.reload();
                } catch (e) {
                    this.error = e.message;
                } finally {
                    this.busy = false;
                }
            },
        }));

        Alpine.data('incomingEfakturaPdfFetch', (documentId) => ({
            busy: false,
            error: '',
            statusText: '',
            async run() {
                this.busy = true; this.error = '';
                try {
                    const cert = await readTokenCertificate(@js($company->efaktura_token_serial_number));

                    this.statusText = 'Преземам ПДФ (проверете го прозорецот на SafeNet)...';
                    const signed = await signViaBridge(
                        `/companies/{{ $company->id }}/incoming-efaktura/${documentId}/pdf/signing-input`,
                        { certificateBase64: cert.certificateBase64 }
                    );
                    await postJson(
                        `/companies/{{ $company->id }}/incoming-efaktura/${documentId}/pdf`,
                        { token: signed.token, signature: signed.signature }
                    );

                    window.location.reload();
                } catch (e) {
                    this.error = e.message;
                } finally {
                    this.busy = false;
                }
            },
        }));
    </script>
    @endscript
</div>
```

- [ ] **Step 5: Add the route**

In `routes/web.php`, add `use App\Livewire\Invoicing\IncomingEfakturaIndex;` near the other `App\Livewire\Invoicing` imports, and add this route in its own group right after the `purchase-invoices.` group (kept separate from the `incoming-efaktura.` sub-action groups from Tasks 7-10 so the index page's own controller-less `__invoke` route sits with the other top-level index routes, matching the existing `sales-invoices.`/`purchase-invoices.` split pattern):

```php
Route::middleware(['auth'])->prefix('companies/{company}')->name('incoming-efaktura.')->group(function () {
    Route::get('/incoming-efaktura', [IncomingEfakturaIndex::class, '__invoke'])->name('index');
});
```

- [ ] **Step 6: Add the sidebar link**

In `resources/views/livewire/layout/sidebar.blade.php`, add this link inside the `@if ($expandedModule === 'invoicing')` block, right after the „Влезни фактури" link:

```blade
                        <a href="{{ route('incoming-efaktura.index', $company) }}" wire:navigate
                           class="block px-4 py-1.5 text-sm {{ request()->routeIs('incoming-efaktura.*') ? 'text-white font-medium' : 'text-gray-400 hover:text-white' }}">Влезни е-Фактури</a>
```

Also update the module-toggle `<button>`'s active-state condition a few lines above (currently `request()->routeIs('partners.*') || request()->routeIs('sales-invoices.*') || request()->routeIs('purchase-invoices.*')`) to also include `|| request()->routeIs('incoming-efaktura.*')`.

- [ ] **Step 7: Run the test to verify it passes**

Run: `php artisan test tests/Feature/IncomingEfakturaIndexTest.php`
Expected: 2 passed.

- [ ] **Step 8: Run the full test suite**

Run: `php artisan test`
Expected: all tests passed (no regressions from the route/sidebar changes).

- [ ] **Step 9: Commit**

```bash
git add app/Livewire/Invoicing/IncomingEfakturaIndex.php resources/views/livewire/invoicing/incoming-efaktura-index.blade.php routes/web.php resources/views/livewire/layout/sidebar.blade.php tests/Feature/IncomingEfakturaIndexTest.php
git commit -m "feat: add Влезни е-Фактури screen with discover/accept/reject/pdf flows"
```

---

## Task 12: Live verification against efakturatest.ujp.gov.mk

This task cannot be automated — it requires the user's own PC (physical SafeNet USB token, local signing bridge running), the droplet deployment, and real interaction with the SafeNet PIN popup, following the same manual live-verification pattern as every prior е-Фактура milestone in this project (Task 18 in Phase 8b-ii, Task 8 in the status+PDF plan).

**Precondition to check first, before writing any code fixes:** this task needs at least one real incoming e-invoice sitting in `efakturatest.ujp.gov.mk` addressed to production company id 2 (tax id confirmed elsewhere in this project's records) — unlike every prior milestone, which only ever *sent* invoices, this is the first flow that needs someone else's test-environment invoice to *receive*. Ask the user whether they know of another УЈП test partner who can send a test invoice to this company's tax id, or whether УЈП provides any shared test-invoice fixture. Do not proceed to live browser testing until at least one such invoice is confirmed to exist — there's nothing to discover otherwise.

- [ ] **Step 1: Confirm the precondition**

Ask the user to confirm a real incoming test invoice exists for company id 2 on `efakturatest.ujp.gov.mk`, addressed via its tax id / EUJP-ID. If none exists, work with the user to arrange one (another test partner, or a УЈП-provided fixture) before continuing.

- [ ] **Step 2: Confirm the SSH tunnel / connectivity workaround is available**

Per this project's memory, `efakturatest.ujp.gov.mk` is Macedonia-IP-restricted and the droplet reaches it only via the user's SSH reverse tunnel (`ssh -N -R 18443:efakturatest.ujp.gov.mk:443 root@46.101.177.209`) plus the droplet's `EFAKTURA_CONNECT_TO` env var. Ask the user whether that tunnel is still open (it may have dropped since the last session) — reopen it if not, before testing.

- [ ] **Step 3: Deploy this plan's code to the droplet**

Ask the user to run `git pull` on the droplet (no `.env`/config changes needed — all new tables/columns ship via `php artisan migrate`, which the user should also run on the droplet after pulling).

- [ ] **Step 4: Live-test discovery ("Провери за нови фактури")**

From `https://portal.financebuddy.mk`, navigate to Влезни е-Фактури for company id 2, ensure the local bridge is running, click the button. Watch for the SafeNet PIN popup (up to 3 times — one per signed step: ids, payload/list if any new invoice, status). If any step 4xx/5xx's, read the actual UJP response body (surfaced in the browser's error message or via a temporary `EFAKTURA_DEBUG_INCOMING_DISCOVERY`-tagged log line added to the relevant controller, matching this project's established temporary-debug-logging convention — add it, diagnose, fix the response-shape assumption flagged in that controller's code comment, redeploy, retry, then remove the temporary logging once verified, same session).

- [ ] **Step 5: Live-test Прифати**

Once at least one document is visible in the list, click Прифати on it. Confirm a `draft` `PurchaseInvoice` is created (check via `purchase-invoices.index` for the same company) with a matching partner, line(s), and correct `vat_rate`. If the partner or line data looks wrong, compare against the raw `payload_json` stored on the `incoming_efaktura_documents` row (inspect via `php artisan tinker` on the droplet) to see whether the payload shape differs from what `IncomingPurchaseInvoiceBuilder` assumes, and fix accordingly.

- [ ] **Step 6: Live-test Одбиј**

If a second test invoice is available, click Одбиј with a real reason code and confirm the document is marked rejected with no `PurchaseInvoice` created.

- [ ] **Step 7: Live-test Преземи ПДФ**

On the accepted document from Step 5, click Преземи ПДФ. Confirm a real PDF downloads and visually contains a QR code and correct seller/buyer/amount data, same verification standard as the outgoing-invoice PDF work.

- [ ] **Step 8: Clean up any temporary debug logging**

If any `EFAKTURA_DEBUG_INCOMING_*`-tagged logging was added during Steps 4-7, remove it once all four flows are confirmed working, in the same session (per this project's established discipline — the status-refresh debug logging in the 8b status+PDF plan was added and removed same-session; the earlier Task 18 debug logging lingered for a full extra session, which this project explicitly wants to avoid repeating).

- [ ] **Step 9: Update project memory**

Record in the `tami-web-app-project` memory file: which of the five best-guess response shapes (`ids`, `payload/list`, `current-status`, `accept-reject`, `pdf`) needed correction and what the real shape turned out to be, mirroring how every prior format-iteration round in this project (Task 18, Task 8) was documented — this is what makes the next е-Фактура phase's live-verification faster.

