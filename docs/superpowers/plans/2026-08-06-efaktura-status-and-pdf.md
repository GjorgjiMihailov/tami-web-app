# е-Фактура — преглед на статус кај УЈП и официјален ПДФ со QR код Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Let the user see when a sent invoice has been accepted by УЈП ("Прифатена"/"Автоматски прифатена") via a single batch "Освежи статуси" signing operation, and download УЈП's official accepted-invoice PDF (with QR code) once, caching it permanently so repeat views never need the hardware token again.

**Design doc:** `docs/superpowers/specs/2026-08-05-efaktura-status-and-pdf-design.md` (approved). This plan implements it as written — do not re-derive scope from first principles, the sections below map directly onto that doc's components А–Д.

**Architecture:** Extends the existing sign-and-send pattern (`EfakturaSendController` + the bridge-signing JS already shipped in `sales-invoice-show.blade.php`) with two more independent two-step flows (signing-input → sign via bridge → send) that share the same browser-side bridge calls (`/health`, `/certificate`, `/sign`) but post small, different JSON payloads instead of a full invoice document. `EfakturaJwsService::buildSigningInput()` is refactored to sit on top of a new generic `buildSigningInputForPayload()` so all three flows (send, status-refresh, pdf-fetch) share one JWS-assembly implementation.

**Tech Stack:** Same as the existing е-Фактура work — Laravel (PHP), Livewire 3 + Alpine.js for the UI, `Illuminate\Support\Facades\Http` for the УЈП calls, `Illuminate\Support\Facades\Storage` (`local` disk, which maps to `storage/app/private/`) for the cached PDFs.

## Global Constraints

- **The exact request/response shape of the two new УЈП endpoints is NOT confirmed against live infrastructure.** Everything known comes from the approved design doc's prose description (`POST /api/v1/documents/sales-invoice/invoices-status`, `POST /api/v1/documents/sales-invoice/pdf`, both JWS-signed) and this project's one confirmed precedent — the `/sales-invoices/send` endpoint, which needed **three real format iterations** (a 415 wrong-content-type bug, an E5000 crash from bad party data, then E10002/E10004 registry-mismatch errors) before it worked, per project memory. Assume the same will happen here. This plan implements the two new endpoints under the `/JSONReceiver/api/v1/documents/sales-invoice/...` path (same base as `send`, since both are document-management operations, not the read-only `/einvoice_api/...` reference endpoints) and the same `{requestTimestamp, jws}` POST-body wrapper already proven to work for `send`. **Task 8 (live verification) is not a formality — treat its first few attempts as expected, not as a sign the plan is wrong**, exactly like Task 18's real history.
- **УЈП status codes "03"/"04" for "Прифатена"/"Автоматски прифатена" are taken directly from the approved design doc's own text** (§Д, date-range note) — not independently re-verified against a sifrarnik PDF. If Task 8's live response uses different codes, `SalesInvoice::EFAKTURA_ACCEPTED_STATUS_CODES` is the single place to fix it.
- **Only `efaktura_credential_mode = own` companies reach these flows** — same restriction already in place for `send` (Phase 8b-ii's explicit scope decision, `firm`-mode resolution still undefined). Both new controllers reuse the identical 422 guard.
- **`EFAKTURA_CONNECT_TO` / SSH tunnel** is still needed for Task 8's live test against `efakturatest.ujp.gov.mk` — confirm with the user whether the tunnel (`ssh -N -R 18443:efakturatest.ujp.gov.mk:443 root@46.101.177.209`, droplet `.env`'s `EFAKTURA_CONNECT_TO`) is still open before that task; it may have dropped since 2026-08-05.
- **No automated background refresh.** The hardware token requires physical PIN entry every time it signs — this plan implements exactly what the design doc scopes: one manual "Освежи статуси" click per session, one manual "Преземи ПДФ" click per invoice (first time only).
- **Debug logging convention, if needed during Task 8:** if a live attempt returns an opaque/generic error the way `send()`'s E5000 did, add a temporary `Log::info('EFAKTURA_DEBUG_STATUS_REFRESH', [...])` (or `_PDF_FETCH`) around the request/response in `EfakturaJwsService`, diagnose from `storage/logs/laravel.log`, then **remove it before the final commit** — do not repeat the still-outstanding cleanup debt from Task 18's `EFAKTURA_DEBUG_TASK18` logging (flagged separately, out of scope for this plan).

---

## File Structure

```
tami-web-app/
  database/migrations/
    2026_08_06_090000_add_ujp_status_and_pdf_path_to_sales_invoices_table.php  ← Create (Task 1)
  app/Models/SalesInvoice.php                                          ← Modify (Task 1)
  tests/Unit/Models/SalesInvoiceEfakturaStatusTest.php                 ← Create (Task 1)
  app/Services/Efaktura/EfakturaJwsService.php                         ← Modify (Task 2, Task 3)
  tests/Unit/Services/Efaktura/EfakturaJwsServiceTest.php              ← Modify (Task 2, Task 3)
  app/Http/Controllers/EfakturaStatusController.php                    ← Create (Task 4)
  routes/web.php                                                       ← Modify (Task 4, Task 6)
  tests/Feature/EfakturaStatusControllerTest.php                       ← Create (Task 4)
  app/Livewire/Invoicing/SalesInvoiceIndex.php                         ← Modify (Task 5)
  resources/views/livewire/invoicing/sales-invoice-index.blade.php     ← Modify (Task 5, Task 7)
  app/Http/Controllers/EfakturaPdfController.php                       ← Create (Task 6)
  tests/Feature/EfakturaPdfControllerTest.php                          ← Create (Task 6)
```

---

### Task 1: Migration + model — УЈП status and cached-PDF-path columns on `sales_invoices`

**Files:**
- Create: `database/migrations/2026_08_06_090000_add_ujp_status_and_pdf_path_to_sales_invoices_table.php`
- Modify: `app/Models/SalesInvoice.php`
- Test: `tests/Unit/Models/SalesInvoiceEfakturaStatusTest.php`

**Interfaces:**
- Produces: `sales_invoices.efaktura_ujp_status_code`, `efaktura_ujp_status_name`, `efaktura_pdf_path` (all nullable strings). `SalesInvoice::EFAKTURA_ACCEPTED_STATUS_CODES` const and `SalesInvoice::isEfakturaAccepted(): bool` — consumed by Task 4's date-range query, Task 5's status badge, and Task 6/7's "download PDF" gating.

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
        Schema::table('sales_invoices', function (Blueprint $table) {
            $table->string('efaktura_ujp_status_code', 10)->nullable()->after('efaktura_error');
            $table->string('efaktura_ujp_status_name')->nullable()->after('efaktura_ujp_status_code');
            $table->string('efaktura_pdf_path')->nullable()->after('efaktura_ujp_status_name');
        });
    }

    public function down(): void
    {
        Schema::table('sales_invoices', function (Blueprint $table) {
            $table->dropColumn(['efaktura_ujp_status_code', 'efaktura_ujp_status_name', 'efaktura_pdf_path']);
        });
    }
};
```

Save as `database/migrations/2026_08_06_090000_add_ujp_status_and_pdf_path_to_sales_invoices_table.php`.

- [ ] **Step 2: Run the migration**

Run:
```powershell
php artisan migrate
```
Expected: runs successfully.

- [ ] **Step 3: Update the `SalesInvoice` model**

In `app/Models/SalesInvoice.php`, add the const and helper method, and extend `$fillable`:

```php
    public const PAYMENT_TYPES = [
        'P10' => 'Готово',
        'P11' => 'Картичка',
        'P12' => 'Плаќање преку банка',
        'P13' => 'Рати',
        'P14' => 'Онлајн-банка',
        'P15' => 'Мобилна апликација',
        'P16' => 'Без надомест',
        'P17' => 'Компензација',
        'P18' => 'Ваучер',
        'P19' => 'Друго',
    ];

    // Per the approved design doc (2026-08-05-efaktura-status-and-pdf-design.md §Д) — not yet
    // independently re-verified against a live УЈП response. If Task 8's live test surfaces
    // different codes for "Прифатена"/"Автоматски прифатена", fix them here only.
    public const EFAKTURA_ACCEPTED_STATUS_CODES = ['03', '04'];

    protected $fillable = [
        'company_id', 'partner_id', 'warehouse_id', 'journal_entry_id',
        'fiscal_year', 'invoice_number', 'invoice_date', 'due_date',
        'status', 'payment_type_code', 'sent_at', 'notes', 'created_by',
        'efaktura_status', 'efaktura_doc_id', 'efaktura_sent_at', 'efaktura_error',
        'efaktura_ujp_status_code', 'efaktura_ujp_status_name', 'efaktura_pdf_path',
    ];

    protected function casts(): array
    {
        return [
            'invoice_date' => 'date',
            'due_date' => 'date',
            'sent_at' => 'datetime',
            'efaktura_sent_at' => 'datetime',
        ];
    }

    public function isEfakturaAccepted(): bool
    {
        return in_array($this->efaktura_ujp_status_code, self::EFAKTURA_ACCEPTED_STATUS_CODES, true);
    }
```

(Insert the const and method in place; everything else in the file — relationships — is unchanged.)

- [ ] **Step 4: Write the test**

```php
<?php

namespace Tests\Unit\Models;

use App\Models\Company;
use App\Models\Partner;
use App\Models\SalesInvoice;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SalesInvoiceEfakturaStatusTest extends TestCase
{
    use RefreshDatabase;

    private function makeInvoice(?string $ujpStatusCode = null): SalesInvoice
    {
        $company = Company::factory()->create();
        $partner = Partner::factory()->for($company)->create();

        return SalesInvoice::factory()->for($company)->create([
            'partner_id' => $partner->id,
            'efaktura_ujp_status_code' => $ujpStatusCode,
        ]);
    }

    public function test_is_efaktura_accepted_is_false_when_no_status_yet(): void
    {
        $this->assertFalse($this->makeInvoice(null)->isEfakturaAccepted());
    }

    public function test_is_efaktura_accepted_is_false_for_a_non_terminal_status(): void
    {
        $this->assertFalse($this->makeInvoice('01')->isEfakturaAccepted());
    }

    public function test_is_efaktura_accepted_is_true_for_code_03(): void
    {
        $this->assertTrue($this->makeInvoice('03')->isEfakturaAccepted());
    }

    public function test_is_efaktura_accepted_is_true_for_code_04(): void
    {
        $this->assertTrue($this->makeInvoice('04')->isEfakturaAccepted());
    }
}
```

Save as `tests/Unit/Models/SalesInvoiceEfakturaStatusTest.php`.

- [ ] **Step 5: Run the test and confirm it passes**

Run:
```powershell
php artisan test --filter SalesInvoiceEfakturaStatusTest
```
Expected: `OK (4 tests, 4 assertions)`.

- [ ] **Step 6: Commit**

```bash
git add database/migrations/2026_08_06_090000_add_ujp_status_and_pdf_path_to_sales_invoices_table.php app/Models/SalesInvoice.php tests/Unit/Models/SalesInvoiceEfakturaStatusTest.php
git commit -m "feat: add УЈП status and cached-PDF-path columns to sales invoices"
```

---

### Task 2: Generalize `EfakturaJwsService` signing-input assembly

**Files:**
- Modify: `app/Services/Efaktura/EfakturaJwsService.php`
- Modify: `tests/Unit/Services/Efaktura/EfakturaJwsServiceTest.php`

**Interfaces:**
- Produces: `EfakturaJwsService::buildSigningInputForPayload(array $payload, string $certificateBase64Der): array` — same return shape (`['signingInput' => ..., 'payloadJson' => ...]`) as the existing `buildSigningInput()`, but takes any JSON-serializable array instead of a `SalesInvoice`. `buildSigningInput()` becomes a thin wrapper over it. Consumed by Task 4 (status-refresh payload) and Task 6 (pdf-fetch payload).

- [ ] **Step 1: Write the failing test**

Add to `tests/Unit/Services/Efaktura/EfakturaJwsServiceTest.php` (inside the class, alongside the existing two tests):

```php
    public function test_build_signing_input_for_payload_works_for_an_arbitrary_json_body(): void
    {
        $certDer = base64_encode('fake-der-bytes');
        $payload = ['requestTimestamp' => '2026-08-06T10:00:00', 'dateFrom' => '2026-08-01', 'dateTo' => '2026-08-06'];

        $result = (new EfakturaJwsService)->buildSigningInputForPayload($payload, $certDer);

        [$headerPart, $payloadPart] = explode('.', $result['signingInput']);
        $header = json_decode(Base64Url::decode($headerPart), true);
        $decodedPayload = json_decode(Base64Url::decode($payloadPart), true);

        $this->assertSame('RS256', $header['alg']);
        $this->assertSame([$certDer], $header['x5c']);
        $this->assertSame($payload, $decodedPayload);
        $this->assertSame($result['payloadJson'], Base64Url::decode($payloadPart));
    }
```

- [ ] **Step 2: Run the test and confirm it fails**

Run:
```powershell
php artisan test --filter EfakturaJwsServiceTest
```
Expected: FAIL — `buildSigningInputForPayload` doesn't exist yet.

- [ ] **Step 3: Implement — extract the generic method, refactor `buildSigningInput` on top of it**

In `app/Services/Efaktura/EfakturaJwsService.php`, replace:

```php
    /**
     * @return array{signingInput: string, payloadJson: string}
     */
    public function buildSigningInput(SalesInvoice $invoice, string $certificateBase64Der): array
    {
        $payload = $this->documentBuilder->build($invoice);
        $payloadJson = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        $header = ['alg' => 'RS256', 'x5c' => [$certificateBase64Der]];
        $headerJson = json_encode($header, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        $signingInput = Base64Url::encode($headerJson).'.'.Base64Url::encode($payloadJson);

        return ['signingInput' => $signingInput, 'payloadJson' => $payloadJson];
    }
```

with:

```php
    /**
     * @return array{signingInput: string, payloadJson: string}
     */
    public function buildSigningInput(SalesInvoice $invoice, string $certificateBase64Der): array
    {
        return $this->buildSigningInputForPayload($this->documentBuilder->build($invoice), $certificateBase64Der);
    }

    /**
     * Same base64url(header) + "." + base64url(payload) assembly as buildSigningInput(),
     * but for the small, non-invoice JSON bodies the status-refresh and PDF-fetch endpoints
     * sign (e.g. {requestTimestamp, dateFrom, dateTo} or {requestTimestamp, euid}).
     *
     * @return array{signingInput: string, payloadJson: string}
     */
    public function buildSigningInputForPayload(array $payload, string $certificateBase64Der): array
    {
        $payloadJson = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        $header = ['alg' => 'RS256', 'x5c' => [$certificateBase64Der]];
        $headerJson = json_encode($header, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        $signingInput = Base64Url::encode($headerJson).'.'.Base64Url::encode($payloadJson);

        return ['signingInput' => $signingInput, 'payloadJson' => $payloadJson];
    }
```

- [ ] **Step 4: Run tests and confirm they pass**

Run:
```powershell
php artisan test --filter EfakturaJwsServiceTest
```
Expected: `OK (3 tests, ...)` — the two pre-existing tests must still pass unchanged, confirming the refactor didn't change `buildSigningInput()`'s behavior.

- [ ] **Step 5: Run the full е-Фактура test suite as a regression check**

Run:
```powershell
php artisan test --filter Efaktura
```
Expected: all green — this refactor must not change `EfakturaSendControllerTest`'s behavior either.

- [ ] **Step 6: Commit**

```bash
git add app/Services/Efaktura/EfakturaJwsService.php tests/Unit/Services/Efaktura/EfakturaJwsServiceTest.php
git commit -m "refactor: extract generic buildSigningInputForPayload from buildSigningInput"
```

---

### Task 3: `EfakturaJwsService` — send methods for status-refresh and PDF-fetch

**Files:**
- Modify: `app/Services/Efaktura/EfakturaJwsService.php`
- Modify: `tests/Unit/Services/Efaktura/EfakturaJwsServiceTest.php`

**Interfaces:**
- Produces: `EfakturaJwsService::sendStatusRefresh(Company $company, string $signingInput, string $signatureBase64Url): Response` and `sendPdfFetch(...)` (same signature). Both share a new private `postSignedRequest()` helper that the existing `send()` is refactored to use too (same URL/headers/body for `send()` — this is a pure internal refactor, `EfakturaSendControllerTest` must stay green). Consumed by Task 4 and Task 6's controllers.

- [ ] **Step 1: Write the failing tests**

Add to `tests/Unit/Services/Efaktura/EfakturaJwsServiceTest.php`:

```php
    public function test_send_status_refresh_posts_compact_jws_to_the_status_endpoint(): void
    {
        Http::fake(['*' => Http::response(['invoices' => []], 200)]);
        $company = Company::factory()->create([
            'tax_id' => '4030001234567', 'efaktura_eujp_id' => 'EUJP-1', 'efaktura_token_serial_number' => '1A2B3C',
        ]);

        $response = (new EfakturaJwsService)->sendStatusRefresh($company, 'header.payload', 'c2ln');

        $this->assertTrue($response->successful());
        Http::assertSent(function ($request) {
            $body = $request->data();

            return $request->url() === rtrim(config('services.efaktura.base_url'), '/').'/JSONReceiver/api/v1/documents/sales-invoice/invoices-status'
                && $request->hasHeader('X-EUJP-ID', 'EUJP-1')
                && $request->hasHeader('X-EDB', '4030001234567')
                && $request->hasHeader('X-SERIAL-NUMBER', '1A2B3C')
                && $body['jws'] === 'header.payload.c2ln'
                && isset($body['requestTimestamp']);
        });
    }

    public function test_send_pdf_fetch_posts_compact_jws_to_the_pdf_endpoint(): void
    {
        Http::fake(['*' => Http::response(['pdfBase64' => base64_encode('fake-pdf-bytes')], 200)]);
        $company = Company::factory()->create([
            'tax_id' => '4030001234567', 'efaktura_eujp_id' => 'EUJP-1', 'efaktura_token_serial_number' => '1A2B3C',
        ]);

        $response = (new EfakturaJwsService)->sendPdfFetch($company, 'header.payload', 'c2ln');

        $this->assertTrue($response->successful());
        Http::assertSent(function ($request) {
            $body = $request->data();

            return $request->url() === rtrim(config('services.efaktura.base_url'), '/').'/JSONReceiver/api/v1/documents/sales-invoice/pdf'
                && $request->hasHeader('X-EUJP-ID', 'EUJP-1')
                && $body['jws'] === 'header.payload.c2ln'
                && isset($body['requestTimestamp']);
        });
    }
```

- [ ] **Step 2: Run the tests and confirm they fail**

Run:
```powershell
php artisan test --filter EfakturaJwsServiceTest
```
Expected: FAIL — `sendStatusRefresh`/`sendPdfFetch` don't exist yet.

- [ ] **Step 3: Implement — extract `postSignedRequest()`, add the two new methods**

In `app/Services/Efaktura/EfakturaJwsService.php`, replace the existing `send()` method:

```php
    public function send(Company $company, string $signingInput, string $signatureBase64Url): Response
    {
        $compact = $signingInput.'.'.$signatureBase64Url;
        $baseUrl = config('services.efaktura.base_url');
        $url = rtrim($baseUrl, '/').'/JSONReceiver/api/v1/sales-invoices/send';

        $request = Http::withHeaders([
            'X-EUJP-ID' => $company->efaktura_eujp_id,
            'X-EDB' => $company->tax_id,
            'X-SERIAL-NUMBER' => $company->efaktura_token_serial_number,
            'X-DOC-TYPE-CODE' => '100',
        ])->timeout(20);

        if ($connectTo = config('services.efaktura.connect_to')) {
            $request = $request->withOptions(['curl' => [CURLOPT_CONNECT_TO => [$connectTo]]]);
        }

        return $request->post($url, [
            'requestTimestamp' => now()->timezone('Europe/Skopje')->format('Y-m-d\TH:i:s'),
            'jws' => $compact,
        ]);
    }
```

with:

```php
    // Best-guess paths from the approved design doc's prose (efakturawiki.ujp.gov.mk hasn't
    // been checked for these two specifically the way it was for /sales-invoices/send) — same
    // /JSONReceiver/api/v1/... base as the confirmed-working send endpoint, since both are
    // document-management operations requiring JWS, not the read-only /einvoice_api/... ones.
    // VERIFY against a real response in Task 8; fix here if wrong.
    private const STATUS_REFRESH_PATH = '/JSONReceiver/api/v1/documents/sales-invoice/invoices-status';

    private const PDF_FETCH_PATH = '/JSONReceiver/api/v1/documents/sales-invoice/pdf';

    public function send(Company $company, string $signingInput, string $signatureBase64Url): Response
    {
        return $this->postSignedRequest(
            $company,
            '/JSONReceiver/api/v1/sales-invoices/send',
            $signingInput,
            $signatureBase64Url,
            ['X-DOC-TYPE-CODE' => '100'],
        );
    }

    public function sendStatusRefresh(Company $company, string $signingInput, string $signatureBase64Url): Response
    {
        return $this->postSignedRequest($company, self::STATUS_REFRESH_PATH, $signingInput, $signatureBase64Url);
    }

    public function sendPdfFetch(Company $company, string $signingInput, string $signatureBase64Url): Response
    {
        return $this->postSignedRequest($company, self::PDF_FETCH_PATH, $signingInput, $signatureBase64Url);
    }

    private function postSignedRequest(Company $company, string $path, string $signingInput, string $signatureBase64Url, array $extraHeaders = []): Response
    {
        $compact = $signingInput.'.'.$signatureBase64Url;
        $baseUrl = config('services.efaktura.base_url');
        $url = rtrim($baseUrl, '/').$path;

        $request = Http::withHeaders(array_merge([
            'X-EUJP-ID' => $company->efaktura_eujp_id,
            'X-EDB' => $company->tax_id,
            'X-SERIAL-NUMBER' => $company->efaktura_token_serial_number,
        ], $extraHeaders))->timeout(20);

        if ($connectTo = config('services.efaktura.connect_to')) {
            $request = $request->withOptions(['curl' => [CURLOPT_CONNECT_TO => [$connectTo]]]);
        }

        return $request->post($url, [
            'requestTimestamp' => now()->timezone('Europe/Skopje')->format('Y-m-d\TH:i:s'),
            'jws' => $compact,
        ]);
    }
```

- [ ] **Step 4: Run tests and confirm they pass**

Run:
```powershell
php artisan test --filter EfakturaJwsServiceTest
```
Expected: `OK (5 tests, ...)`.

- [ ] **Step 5: Regression check on the existing send flow**

Run:
```powershell
php artisan test --filter Efaktura
```
Expected: all green, including `EfakturaSendControllerTest` — confirms the `send()` refactor didn't change its URL/headers/body.

- [ ] **Step 6: Commit**

```bash
git add app/Services/Efaktura/EfakturaJwsService.php tests/Unit/Services/Efaktura/EfakturaJwsServiceTest.php
git commit -m "feat: add EfakturaJwsService methods for УЈП status-refresh and PDF-fetch"
```

---

### Task 4: `EfakturaStatusController` — batch status refresh

**Files:**
- Create: `app/Http/Controllers/EfakturaStatusController.php`
- Modify: `routes/web.php`
- Test: `tests/Feature/EfakturaStatusControllerTest.php`

**Interfaces:**
- Consumes: `EfakturaJwsService::buildSigningInputForPayload()`, `sendStatusRefresh()` (Tasks 2–3); `SalesInvoice::EFAKTURA_ACCEPTED_STATUS_CODES` (Task 1).
- Produces: `POST companies/{company}/sales-invoices/efaktura/refresh-statuses/signing-input` (route name `sales-invoices.efaktura.refresh-statuses.signing-input`) and `POST companies/{company}/sales-invoices/efaktura/refresh-statuses` (`sales-invoices.efaktura.refresh-statuses`). Consumed by Task 5's browser JS.

- [ ] **Step 1: Write the controller**

```php
<?php

namespace App\Http\Controllers;

use App\Models\Company;
use App\Models\SalesInvoice;
use App\Services\Efaktura\EfakturaJwsService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;

class EfakturaStatusController extends Controller
{
    public function signingInput(Request $request, Company $company, EfakturaJwsService $jwsService)
    {
        $this->authorizeRefresh($company);

        $earliestPending = $this->earliestPendingInvoice($company);

        if (! $earliestPending) {
            return response()->json(['error' => 'nothing_pending', 'message' => 'Нема чекачки фактури за освежување статус.'], 422);
        }

        $validated = $request->validate(['certificateBase64' => 'required|string']);

        $payload = [
            'requestTimestamp' => now()->timezone('Europe/Skopje')->format('Y-m-d\TH:i:s'),
            'dateFrom' => $earliestPending->efaktura_sent_at->timezone('Europe/Skopje')->toDateString(),
            'dateTo' => now()->timezone('Europe/Skopje')->toDateString(),
        ];
        $result = $jwsService->buildSigningInputForPayload($payload, $validated['certificateBase64']);

        $token = (string) Str::uuid();
        Cache::put("efaktura-status-refresh:{$token}", [
            'company_id' => $company->id,
            'signing_input' => $result['signingInput'],
        ], now()->addMinutes(10));

        return response()->json(['token' => $token, 'signingInput' => $result['signingInput']]);
    }

    public function refresh(Request $request, Company $company, EfakturaJwsService $jwsService)
    {
        $this->authorizeRefresh($company);

        $validated = $request->validate(['token' => 'required|string', 'signature' => 'required|string']);

        $cached = Cache::pull("efaktura-status-refresh:{$validated['token']}");

        if (! $cached || $cached['company_id'] !== $company->id) {
            return response()->json(['error' => 'expired_or_invalid_token'], 410);
        }

        try {
            $response = $jwsService->sendStatusRefresh($company, $cached['signing_input'], $validated['signature']);
        } catch (\Illuminate\Http\Client\ConnectionException $e) {
            return response()->json([
                'error' => 'ujp_unreachable',
                'message' => 'Не можам да се поврзам со серверот на УЈП — провери ја интернет-врската или обиди се подоцна.',
            ], 503);
        }

        if (! $response->successful()) {
            return response()->json(['error' => 'ujp_rejected', 'status' => $response->status(), 'body' => $response->body()], 422);
        }

        // Response shape ("invoices" array of {euid, statusCode, statusName}) is a best guess —
        // not yet confirmed live (see Global Constraints). If Task 8 finds a different shape,
        // this is the only place that needs to change.
        $items = $response->json('invoices', []);
        $updated = 0;

        foreach ($items as $item) {
            $euid = $item['euid'] ?? null;
            if (! $euid) {
                continue;
            }

            $invoice = SalesInvoice::where('company_id', $company->id)->where('efaktura_doc_id', $euid)->first();
            if (! $invoice) {
                continue;
            }

            $invoice->update([
                'efaktura_ujp_status_code' => $item['statusCode'] ?? null,
                'efaktura_ujp_status_name' => $item['statusName'] ?? null,
            ]);
            $updated++;
        }

        return response()->json(['status' => 'refreshed', 'updated' => $updated]);
    }

    private function earliestPendingInvoice(Company $company): ?SalesInvoice
    {
        return SalesInvoice::where('company_id', $company->id)
            ->where('efaktura_status', 'sent')
            // whereNotIn() treats a NULL column as excluded (SQL: "NULL NOT IN (...)" is NULL,
            // not true) — an invoice never checked before (status_code still null) must count
            // as pending too, so it needs an explicit whereNull() branch alongside whereNotIn().
            ->where(function ($query) {
                $query->whereNull('efaktura_ujp_status_code')
                    ->orWhereNotIn('efaktura_ujp_status_code', SalesInvoice::EFAKTURA_ACCEPTED_STATUS_CODES);
            })
            ->orderBy('efaktura_sent_at')
            ->first();
    }

    private function authorizeRefresh(Company $company): void
    {
        Gate::authorize('view', $company);
        abort_unless(auth()->user()->hasAnyRole(['admin', 'accountant']), 403);
        abort_unless(
            $company->efaktura_credential_mode === Company::EFAKTURA_MODE_OWN,
            422,
            'Освежување статус преку фирмениот сертификат сè уште не е поддржано.'
        );
    }
}
```

Save as `app/Http/Controllers/EfakturaStatusController.php`.

- [ ] **Step 2: Add routes**

In `routes/web.php`, add `use App\Http\Controllers\EfakturaStatusController;` near the other Efaktura controller imports, and add a new group right after the existing `sales-invoices.efaktura.` group (the one scoped to `{salesInvoice}`):

```php
Route::middleware(['auth'])->prefix('companies/{company}/sales-invoices')->name('sales-invoices.efaktura.')->group(function () {
    Route::post('/efaktura/refresh-statuses/signing-input', [EfakturaStatusController::class, 'signingInput'])->name('refresh-statuses.signing-input');
    Route::post('/efaktura/refresh-statuses', [EfakturaStatusController::class, 'refresh'])->name('refresh-statuses');
});
```

This is a separate `Route::group` from the existing per-invoice one (different prefix — no `{salesInvoice}` segment, since refresh-statuses operates on the whole company) but reuses the same `sales-invoices.efaktura.` name prefix; the child route names (`refresh-statuses.signing-input`, `refresh-statuses`) don't collide with the existing `signing-input`/`send` names, so both groups coexist fine.

- [ ] **Step 3: Write feature tests**

```php
<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Partner;
use App\Models\SalesInvoice;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class EfakturaStatusControllerTest extends TestCase
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

    private function makeSentInvoice(Company $company, ?string $statusCode = null, ?string $euid = 'euid-1'): SalesInvoice
    {
        $partner = Partner::factory()->for($company)->create();

        return SalesInvoice::factory()->for($company)->create([
            'partner_id' => $partner->id,
            'status' => 'confirmed',
            'invoice_date' => '2026-08-01',
            'efaktura_status' => 'sent',
            'efaktura_sent_at' => now()->subDays(3),
            'efaktura_doc_id' => $euid,
            'efaktura_ujp_status_code' => $statusCode,
        ]);
    }

    public function test_signing_input_returns_token_when_a_pending_invoice_exists(): void
    {
        $company = $this->makeOwnModeCompany();
        $this->makeSentInvoice($company);
        $admin = User::factory()->create();
        $admin->assignRole('admin');

        $response = $this->actingAs($admin)->postJson(
            route('sales-invoices.efaktura.refresh-statuses.signing-input', $company),
            ['certificateBase64' => base64_encode('fake-cert')]
        );

        $response->assertOk()->assertJsonStructure(['token', 'signingInput']);
    }

    public function test_signing_input_returns_422_when_nothing_pending(): void
    {
        $company = $this->makeOwnModeCompany();
        $this->makeSentInvoice($company, statusCode: '03');
        $admin = User::factory()->create();
        $admin->assignRole('admin');

        $response = $this->actingAs($admin)->postJson(
            route('sales-invoices.efaktura.refresh-statuses.signing-input', $company),
            ['certificateBase64' => base64_encode('fake-cert')]
        );

        $response->assertStatus(422)->assertJson(['error' => 'nothing_pending']);
    }

    public function test_refresh_updates_matching_invoice_by_euid(): void
    {
        Http::fake(['*' => Http::response([
            'invoices' => [
                ['euid' => 'euid-1', 'statusCode' => '03', 'statusName' => 'Прифатена'],
            ],
        ], 200)]);
        $company = $this->makeOwnModeCompany();
        $invoice = $this->makeSentInvoice($company);
        $admin = User::factory()->create();
        $admin->assignRole('admin');

        $signingResponse = $this->actingAs($admin)->postJson(
            route('sales-invoices.efaktura.refresh-statuses.signing-input', $company),
            ['certificateBase64' => base64_encode('fake-cert')]
        )->json();

        $refreshResponse = $this->actingAs($admin)->postJson(
            route('sales-invoices.efaktura.refresh-statuses', $company),
            ['token' => $signingResponse['token'], 'signature' => 'ZmFrZS1zaWc']
        );

        $refreshResponse->assertOk()->assertJson(['status' => 'refreshed', 'updated' => 1]);
        $this->assertSame('03', $invoice->fresh()->efaktura_ujp_status_code);
        $this->assertSame('Прифатена', $invoice->fresh()->efaktura_ujp_status_name);
    }

    public function test_refresh_leaves_non_matching_invoice_untouched(): void
    {
        Http::fake(['*' => Http::response(['invoices' => [
            ['euid' => 'some-other-euid', 'statusCode' => '03', 'statusName' => 'Прифатена'],
        ]], 200)]);
        $company = $this->makeOwnModeCompany();
        $invoice = $this->makeSentInvoice($company, euid: 'euid-1');
        $admin = User::factory()->create();
        $admin->assignRole('admin');

        $signingResponse = $this->actingAs($admin)->postJson(
            route('sales-invoices.efaktura.refresh-statuses.signing-input', $company),
            ['certificateBase64' => base64_encode('fake-cert')]
        )->json();

        $this->actingAs($admin)->postJson(
            route('sales-invoices.efaktura.refresh-statuses', $company),
            ['token' => $signingResponse['token'], 'signature' => 'ZmFrZS1zaWc']
        )->assertOk();

        $this->assertNull($invoice->fresh()->efaktura_ujp_status_code);
    }

    public function test_refresh_when_ujp_is_unreachable_returns_503(): void
    {
        Http::fake(function () {
            throw new \Illuminate\Http\Client\ConnectionException('cURL error 28: Connection timeout');
        });
        $company = $this->makeOwnModeCompany();
        $this->makeSentInvoice($company);
        $admin = User::factory()->create();
        $admin->assignRole('admin');

        $signingResponse = $this->actingAs($admin)->postJson(
            route('sales-invoices.efaktura.refresh-statuses.signing-input', $company),
            ['certificateBase64' => base64_encode('fake-cert')]
        )->json();

        $response = $this->actingAs($admin)->postJson(
            route('sales-invoices.efaktura.refresh-statuses', $company),
            ['token' => $signingResponse['token'], 'signature' => 'ZmFrZS1zaWc']
        );

        $response->assertStatus(503)->assertJson(['error' => 'ujp_unreachable']);
    }

    public function test_client_role_is_forbidden(): void
    {
        $company = $this->makeOwnModeCompany();
        $this->makeSentInvoice($company);
        $client = User::factory()->create(['company_id' => $company->id]);
        $client->assignRole('client');

        $response = $this->actingAs($client)->postJson(
            route('sales-invoices.efaktura.refresh-statuses.signing-input', $company),
            ['certificateBase64' => base64_encode('fake-cert')]
        );

        $response->assertStatus(403);
    }

    public function test_firm_mode_company_is_rejected(): void
    {
        $company = Company::factory()->create(['efaktura_credential_mode' => Company::EFAKTURA_MODE_FIRM]);
        $admin = User::factory()->create();
        $admin->assignRole('admin');

        $response = $this->actingAs($admin)->postJson(
            route('sales-invoices.efaktura.refresh-statuses.signing-input', $company),
            ['certificateBase64' => base64_encode('fake-cert')]
        );

        $response->assertStatus(422);
    }
}
```

Save as `tests/Feature/EfakturaStatusControllerTest.php`.

- [ ] **Step 4: Run the tests**

Run:
```powershell
php artisan test --filter EfakturaStatusControllerTest
```
Expected: `OK (7 tests, ...)`.

- [ ] **Step 5: Commit**

```bash
git add app/Http/Controllers/EfakturaStatusController.php routes/web.php tests/Feature/EfakturaStatusControllerTest.php
git commit -m "feat: add batch е-Фактура status-refresh endpoint"
```

---

### Task 5: UI — status badge column + "Освежи статуси" on `SalesInvoiceIndex`

**Files:**
- Modify: `app/Livewire/Invoicing/SalesInvoiceIndex.php`
- Modify: `resources/views/livewire/invoicing/sales-invoice-index.blade.php`

**Interfaces:**
- Consumes: `sales-invoices.efaktura.refresh-statuses.signing-input` / `.refresh-statuses` routes (Task 4), the local bridge (`127.0.0.1:9847`), `SalesInvoice::isEfakturaAccepted()` (Task 1).

- [ ] **Step 1: Expose `hasEfakturaAccess`/mode to the view**

In `app/Livewire/Invoicing/SalesInvoiceIndex.php`, no PHP change is needed beyond what already exists — `$company` is already a public property available to the Blade view, and `Company::hasEfakturaAccess()`/`EFAKTURA_MODE_OWN` are already usable from Blade exactly like in `sales-invoice-show.blade.php`. Skip to Step 2.

- [ ] **Step 2: Add the status column and "Освежи статуси" button**

In `resources/views/livewire/invoicing/sales-invoice-index.blade.php`, replace the whole file with:

```blade
<div x-data="efakturaStatusRefresh()">
    <div class="flex items-center justify-between mb-4">
        <h1 class="text-2xl font-bold text-gray-800">Излезни фактури — {{ $company->name }}</h1>
        <div class="flex items-center gap-3">
            @if ($company->hasEfakturaAccess() && $company->efaktura_credential_mode === \App\Models\Company::EFAKTURA_MODE_OWN)
                <button type="button" @click="run()" :disabled="busy" class="border border-brand text-brand px-3 py-1.5 rounded-md text-sm disabled:opacity-50">
                    <span x-show="!busy">Освежи статуси</span>
                    <span x-show="busy" x-text="statusText"></span>
                </button>
            @endif
            <a href="{{ route('sales-invoices.create', $company) }}" class="bg-brand text-white px-3 py-1.5 rounded-md text-sm">Нова фактура</a>
        </div>
    </div>

    <p x-show="error" x-text="error" class="text-red-600 text-sm mb-3"></p>

    <div class="mb-4">
        <select wire:model.live="statusFilter" class="border-gray-300 rounded-md text-sm">
            <option value="">Сите статуси</option>
            <option value="draft">Нацрт</option>
            <option value="confirmed">Потврдена</option>
            <option value="cancelled">Откажана</option>
        </select>
    </div>

    <x-card padding="p-0" class="overflow-hidden">
    <table class="min-w-full divide-y divide-gray-200">
        <thead>
            <tr class="text-left text-sm text-gray-500">
                <th class="py-2 px-4">Број</th>
                <th class="py-2 px-4">Купувач</th>
                <th class="py-2 px-4">Датум</th>
                <th class="py-2 px-4">Статус</th>
                <th class="py-2 px-4">е-Фактура</th>
                <th class="py-2 px-4">Вкупно</th>
                <th class="py-2 px-4"></th>
            </tr>
        </thead>
        <tbody class="divide-y divide-gray-100">
            @forelse ($invoices as $invoice)
                <tr class="text-sm">
                    <td class="py-2 px-4">{{ $invoice->invoice_number ? "{$invoice->fiscal_year}/{$invoice->invoice_number}" : '—' }}</td>
                    <td class="py-2 px-4">{{ $invoice->partner->name }}</td>
                    <td class="py-2 px-4">{{ \App\Support\Format::date($invoice->invoice_date) }}</td>
                    <td class="py-2 px-4"><x-badge :status="$invoice->status">{{ \App\Support\Format::invoiceStatus($invoice->status) }}</x-badge></td>
                    <td class="py-2 px-4">
                        @if ($invoice->efaktura_ujp_status_name)
                            <x-badge :status="$invoice->isEfakturaAccepted() ? 'active' : 'pending'">{{ $invoice->efaktura_ujp_status_name }}</x-badge>
                        @elseif ($invoice->efaktura_status === 'sent')
                            <x-badge status="pending">Испратена</x-badge>
                        @elseif ($invoice->efaktura_status === 'failed')
                            <x-badge status="overdue">Не успеа</x-badge>
                        @else
                            <span class="text-gray-400">—</span>
                        @endif
                    </td>
                    <td class="py-2 px-4">{{ \App\Support\Format::money($invoice->grandTotal()) }}</td>
                    <td class="py-2 px-4">
                        <a href="{{ route('sales-invoices.show', [$company, $invoice]) }}" class="text-brand hover:underline">Прегледај</a>
                    </td>
                </tr>
            @empty
                <tr><td colspan="7" class="py-4 px-4 text-gray-500">Нема издадено фактури.</td></tr>
            @endforelse
        </tbody>
    </table>
    </x-card>

    @script
    <script>
        const toBase64Url = (str) => btoa(str).replace(/\+/g, '-').replace(/\//g, '_').replace(/=+$/, '');

        Alpine.data('efakturaStatusRefresh', () => ({
            busy: false,
            error: '',
            statusText: '',
            async run() {
                this.busy = true; this.error = '';
                try {
                    this.statusText = 'Проверувам мост...';
                    const health = await fetch('http://127.0.0.1:9847/health').catch(() => null);
                    if (!health || !health.ok) {
                        throw new Error('Локалниот потпишувач не работи. Стартувај го и обиди се повторно.');
                    }

                    this.statusText = 'Читам токен...';
                    const certRes = await fetch('http://127.0.0.1:9847/certificate');
                    if (!certRes.ok) throw new Error('Не можам да ги прочитам податоците од токенот.');
                    const cert = await certRes.json();

                    this.statusText = 'Подготвувам текст за потпишување...';
                    const signingRes = await fetch(@js(route('sales-invoices.efaktura.refresh-statuses.signing-input', $company)), {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/json',
                            'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content,
                        },
                        body: JSON.stringify({ certificateBase64: cert.certificateBase64 }),
                    });
                    if (!signingRes.ok) {
                        const errorBody = await signingRes.json().catch(() => null);
                        throw new Error(errorBody?.message ?? errorBody?.error ?? 'Серверот не можеше да го подготви текстот за потпишување.');
                    }
                    const { token, signingInput } = await signingRes.json();

                    this.statusText = 'Потпишувам (проверете го прозорецот на SafeNet)...';
                    const signRes = await fetch('http://127.0.0.1:9847/sign', {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/json' },
                        body: JSON.stringify({ data: toBase64Url(signingInput) }),
                    });
                    if (!signRes.ok) throw new Error('Потпишувањето не успеа — провери го PIN-от на токенот.');
                    const { signature } = await signRes.json();

                    this.statusText = 'Ги освежувам статусите...';
                    const refreshRes = await fetch(@js(route('sales-invoices.efaktura.refresh-statuses', $company)), {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/json',
                            'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content,
                        },
                        body: JSON.stringify({ token, signature }),
                    });
                    if (!refreshRes.ok) {
                        const refreshBody = await refreshRes.json().catch(() => null);
                        throw new Error(refreshBody?.message ?? refreshBody?.error ?? 'Освежувањето не успеа.');
                    }

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

Note the reused `toBase64Url` — it's declared inside this file's own `@script` block, separate from the one in `sales-invoice-show.blade.php`; Livewire's `@script` scoping (per project memory's earlier Alpine gotcha) means each page's script block is independent, so no name collision across pages.

- [ ] **Step 3: Confirm the `x-badge` `pending` status class exists**

`resources/views/components/badge.blade.php` already maps `'pending'` to amber and `'active'` to green (checked while researching this plan) — no changes needed there.

- [ ] **Step 4: Manual verification — THIS STEP IS FOR THE USER**

Since this touches real browser+bridge interaction, run through the existing `run` skill or the dev server, open a company's Излезни фактури list with at least one `sent` invoice, and confirm:
- The "Освежи статуси" button only appears when the company has `own`-mode signing-device access.
- Clicking it without the bridge running shows "Локалниот потпишувач не работи...".
- The new "е-Фактура" column renders a badge for `sent`/`failed`/unset invoices without errors.

- [ ] **Step 5: Commit**

```bash
git add app/Livewire/Invoicing/SalesInvoiceIndex.php resources/views/livewire/invoicing/sales-invoice-index.blade.php
git commit -m "feat: add е-Фактура status column and batch refresh button to sales invoice list"
```

---

### Task 6: `EfakturaPdfController` — fetch and cache the official accepted-invoice PDF

**Files:**
- Create: `app/Http/Controllers/EfakturaPdfController.php`
- Modify: `routes/web.php`
- Test: `tests/Feature/EfakturaPdfControllerTest.php`

**Interfaces:**
- Consumes: `EfakturaJwsService::buildSigningInputForPayload()`, `sendPdfFetch()` (Tasks 2–3); `sales_invoices.efaktura_pdf_path` (Task 1).
- Produces: `POST companies/{company}/sales-invoices/{salesInvoice}/efaktura/pdf/signing-input`, `POST .../efaktura/pdf`, `GET .../efaktura/pdf/download` (route names `sales-invoices.efaktura.pdf.signing-input`, `.pdf.store`, `.pdf.download`). Consumed by Task 7's browser JS.

- [ ] **Step 1: Write the controller**

```php
<?php

namespace App\Http\Controllers;

use App\Models\Company;
use App\Models\SalesInvoice;
use App\Services\Efaktura\EfakturaJwsService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class EfakturaPdfController extends Controller
{
    public function signingInput(Request $request, Company $company, SalesInvoice $salesInvoice, EfakturaJwsService $jwsService)
    {
        $this->authorizePdf($company, $salesInvoice);

        $validated = $request->validate(['certificateBase64' => 'required|string']);

        $payload = [
            'requestTimestamp' => now()->timezone('Europe/Skopje')->format('Y-m-d\TH:i:s'),
            'euid' => $salesInvoice->efaktura_doc_id,
        ];
        $result = $jwsService->buildSigningInputForPayload($payload, $validated['certificateBase64']);

        $token = (string) Str::uuid();
        Cache::put("efaktura-pdf:{$token}", [
            'company_id' => $company->id,
            'sales_invoice_id' => $salesInvoice->id,
            'signing_input' => $result['signingInput'],
        ], now()->addMinutes(10));

        return response()->json(['token' => $token, 'signingInput' => $result['signingInput']]);
    }

    public function store(Request $request, Company $company, SalesInvoice $salesInvoice, EfakturaJwsService $jwsService)
    {
        $this->authorizePdf($company, $salesInvoice);

        $validated = $request->validate(['token' => 'required|string', 'signature' => 'required|string']);

        $cached = Cache::pull("efaktura-pdf:{$validated['token']}");

        if (! $cached || $cached['company_id'] !== $company->id || $cached['sales_invoice_id'] !== $salesInvoice->id) {
            return response()->json(['error' => 'expired_or_invalid_token'], 410);
        }

        try {
            $response = $jwsService->sendPdfFetch($company, $cached['signing_input'], $validated['signature']);
        } catch (\Illuminate\Http\Client\ConnectionException $e) {
            return response()->json([
                'error' => 'ujp_unreachable',
                'message' => 'Не можам да се поврзам со серверот на УЈП — провери ја интернет-врската или обиди се подоцна.',
            ], 503);
        }

        if (! $response->successful()) {
            return response()->json(['error' => 'ujp_rejected', 'status' => $response->status(), 'body' => $response->body()], 422);
        }

        // Response key name ("pdfBase64") is a best guess — not yet confirmed live. If Task 8
        // finds a different key, this is the only place that needs to change.
        $pdfBase64 = $response->json('pdfBase64');
        if (! $pdfBase64) {
            return response()->json(['error' => 'ujp_response_missing_pdf', 'body' => $response->body()], 422);
        }

        $path = "efaktura-pdfs/{$company->id}/{$salesInvoice->id}.pdf";
        Storage::disk('local')->put($path, base64_decode($pdfBase64));
        $salesInvoice->update(['efaktura_pdf_path' => $path]);

        return response()->json(['status' => 'saved']);
    }

    public function download(Company $company, SalesInvoice $salesInvoice)
    {
        Gate::authorize('view', $salesInvoice);
        abort_if($salesInvoice->company_id !== $company->id, 404);
        abort_unless($salesInvoice->efaktura_pdf_path, 404);

        $filename = "faktura-{$salesInvoice->fiscal_year}-{$salesInvoice->invoice_number}.pdf";

        return Storage::disk('local')->download($salesInvoice->efaktura_pdf_path, $filename);
    }

    private function authorizePdf(Company $company, SalesInvoice $salesInvoice): void
    {
        Gate::authorize('view', $salesInvoice);
        abort_if($salesInvoice->company_id !== $company->id, 404);
        abort_unless(auth()->user()->hasAnyRole(['admin', 'accountant']), 403);
        abort_unless(
            $company->efaktura_credential_mode === Company::EFAKTURA_MODE_OWN,
            422,
            'Преземање на официјален ПДФ преку фирмениот сертификат сè уште не е поддржано.'
        );
        abort_if($salesInvoice->efaktura_pdf_path, 422, 'ПДФ-от е веќе преземен.');
        abort_unless($salesInvoice->isEfakturaAccepted(), 422, 'Фактурата сè уште не е прифатена кај УЈП.');
    }
}
```

Save as `app/Http/Controllers/EfakturaPdfController.php`.

- [ ] **Step 2: Add routes**

In `routes/web.php`, add `use App\Http\Controllers\EfakturaPdfController;`, then extend the existing per-invoice `sales-invoices.efaktura.` group (the one prefixed `companies/{company}/sales-invoices/{salesInvoice}`):

```php
Route::middleware(['auth'])->prefix('companies/{company}/sales-invoices/{salesInvoice}')->name('sales-invoices.efaktura.')->group(function () {
    Route::post('/efaktura/signing-input', [EfakturaSendController::class, 'signingInput'])->name('signing-input');
    Route::post('/efaktura/send', [EfakturaSendController::class, 'send'])->name('send');
    Route::post('/efaktura/pdf/signing-input', [EfakturaPdfController::class, 'signingInput'])->name('pdf.signing-input');
    Route::post('/efaktura/pdf', [EfakturaPdfController::class, 'store'])->name('pdf.store');
    Route::get('/efaktura/pdf/download', [EfakturaPdfController::class, 'download'])->name('pdf.download');
});
```

- [ ] **Step 3: Write feature tests**

```php
<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Partner;
use App\Models\SalesInvoice;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class EfakturaPdfControllerTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Role::findOrCreate('admin');
        Storage::fake('local');
    }

    private function makeAcceptedInvoice(): array
    {
        $company = Company::factory()->create([
            'tax_id' => '4030001234567',
            'efaktura_credential_mode' => Company::EFAKTURA_MODE_OWN,
            'efaktura_eujp_id' => 'EUJP-1',
            'efaktura_token_serial_number' => '1A2B3C',
        ]);
        $partner = Partner::factory()->for($company)->create();
        $invoice = SalesInvoice::factory()->for($company)->create([
            'partner_id' => $partner->id, 'fiscal_year' => 2026, 'invoice_number' => 1,
            'status' => 'confirmed', 'invoice_date' => '2026-08-01',
            'efaktura_status' => 'sent', 'efaktura_doc_id' => 'euid-1',
            'efaktura_ujp_status_code' => '03', 'efaktura_ujp_status_name' => 'Прифатена',
        ]);

        return [$company, $invoice];
    }

    public function test_signing_input_returns_token_for_an_accepted_invoice(): void
    {
        [$company, $invoice] = $this->makeAcceptedInvoice();
        $admin = User::factory()->create();
        $admin->assignRole('admin');

        $response = $this->actingAs($admin)->postJson(
            route('sales-invoices.efaktura.pdf.signing-input', [$company, $invoice]),
            ['certificateBase64' => base64_encode('fake-cert')]
        );

        $response->assertOk()->assertJsonStructure(['token', 'signingInput']);
    }

    public function test_signing_input_rejects_an_invoice_not_yet_accepted(): void
    {
        [$company, $invoice] = $this->makeAcceptedInvoice();
        $invoice->update(['efaktura_ujp_status_code' => null, 'efaktura_ujp_status_name' => null]);
        $admin = User::factory()->create();
        $admin->assignRole('admin');

        $response = $this->actingAs($admin)->postJson(
            route('sales-invoices.efaktura.pdf.signing-input', [$company, $invoice]),
            ['certificateBase64' => base64_encode('fake-cert')]
        );

        $response->assertStatus(422);
    }

    public function test_store_saves_pdf_and_records_path(): void
    {
        Http::fake(['*' => Http::response(['pdfBase64' => base64_encode('%PDF-fake-bytes')], 200)]);
        [$company, $invoice] = $this->makeAcceptedInvoice();
        $admin = User::factory()->create();
        $admin->assignRole('admin');

        $signingResponse = $this->actingAs($admin)->postJson(
            route('sales-invoices.efaktura.pdf.signing-input', [$company, $invoice]),
            ['certificateBase64' => base64_encode('fake-cert')]
        )->json();

        $storeResponse = $this->actingAs($admin)->postJson(
            route('sales-invoices.efaktura.pdf.store', [$company, $invoice]),
            ['token' => $signingResponse['token'], 'signature' => 'ZmFrZS1zaWc']
        );

        $storeResponse->assertOk()->assertJson(['status' => 'saved']);
        $invoice->refresh();
        $this->assertNotNull($invoice->efaktura_pdf_path);
        Storage::disk('local')->assertExists($invoice->efaktura_pdf_path);
    }

    public function test_signing_input_rejects_when_pdf_already_cached(): void
    {
        [$company, $invoice] = $this->makeAcceptedInvoice();
        $invoice->update(['efaktura_pdf_path' => 'efaktura-pdfs/1/1.pdf']);
        $admin = User::factory()->create();
        $admin->assignRole('admin');

        $response = $this->actingAs($admin)->postJson(
            route('sales-invoices.efaktura.pdf.signing-input', [$company, $invoice]),
            ['certificateBase64' => base64_encode('fake-cert')]
        );

        $response->assertStatus(422);
    }

    public function test_download_serves_the_cached_file_without_any_ujp_call(): void
    {
        Http::fake(function () {
            $this->fail('No УЈП call should happen on download of an already-cached PDF.');
        });
        [$company, $invoice] = $this->makeAcceptedInvoice();
        Storage::disk('local')->put("efaktura-pdfs/{$company->id}/{$invoice->id}.pdf", '%PDF-fake-bytes');
        $invoice->update(['efaktura_pdf_path' => "efaktura-pdfs/{$company->id}/{$invoice->id}.pdf"]);
        $admin = User::factory()->create();
        $admin->assignRole('admin');

        $response = $this->actingAs($admin)->get(route('sales-invoices.efaktura.pdf.download', [$company, $invoice]));

        $response->assertOk();
    }

    public function test_download_404s_when_no_pdf_cached_yet(): void
    {
        [$company, $invoice] = $this->makeAcceptedInvoice();
        $admin = User::factory()->create();
        $admin->assignRole('admin');

        $response = $this->actingAs($admin)->get(route('sales-invoices.efaktura.pdf.download', [$company, $invoice]));

        $response->assertStatus(404);
    }
}
```

Save as `tests/Feature/EfakturaPdfControllerTest.php`.

- [ ] **Step 4: Run the tests**

Run:
```powershell
php artisan test --filter EfakturaPdfControllerTest
```
Expected: `OK (6 tests, ...)`.

- [ ] **Step 5: Commit**

```bash
git add app/Http/Controllers/EfakturaPdfController.php routes/web.php tests/Feature/EfakturaPdfControllerTest.php
git commit -m "feat: add е-Фактура official-PDF fetch-and-cache endpoint"
```

---

### Task 7: UI — "Преземи ПДФ" on `SalesInvoiceIndex`

**Files:**
- Modify: `resources/views/livewire/invoicing/sales-invoice-index.blade.php`

**Interfaces:**
- Consumes: `sales-invoices.efaktura.pdf.signing-input` / `.pdf.store` / `.pdf.download` routes (Task 6).

- [ ] **Step 1: Add the per-row PDF cell and its per-row Alpine component**

In `resources/views/livewire/invoicing/sales-invoice-index.blade.php`, add a new column header after "е-Фактура" (`<th class="py-2 px-4">ПДФ</th>`) and a corresponding `<td>` in the row, right after the "е-Фактура" `<td>` from Task 5:

```blade
                    <td class="py-2 px-4">
                        @if ($invoice->efaktura_pdf_path)
                            <a href="{{ route('sales-invoices.efaktura.pdf.download', [$company, $invoice]) }}" class="text-brand hover:underline">Преземи ПДФ</a>
                        @elseif ($invoice->isEfakturaAccepted() && $company->hasEfakturaAccess() && $company->efaktura_credential_mode === \App\Models\Company::EFAKTURA_MODE_OWN)
                            <div x-data="efakturaPdfFetch({{ $invoice->id }})">
                                <button type="button" @click="run()" :disabled="busy" class="text-brand hover:underline disabled:opacity-50">
                                    <span x-show="!busy">Преземи ПДФ</span>
                                    <span x-show="busy" x-text="statusText"></span>
                                </button>
                                <p x-show="error" x-text="error" class="text-red-600 text-xs mt-1"></p>
                            </div>
                        @else
                            <span class="text-gray-400">—</span>
                        @endif
                    </td>
```

Remember to also update the empty-state `colspan` from `7` to `8` (one more column added).

Then extend the page's `@script` block (the one added in Task 5) with a second `Alpine.data(...)` registration, right after the `efakturaStatusRefresh` one:

```javascript
        Alpine.data('efakturaPdfFetch', (invoiceId) => ({
            busy: false,
            error: '',
            statusText: '',
            async run() {
                this.busy = true; this.error = '';
                try {
                    this.statusText = 'Проверувам мост...';
                    const health = await fetch('http://127.0.0.1:9847/health').catch(() => null);
                    if (!health || !health.ok) {
                        throw new Error('Локалниот потпишувач не работи. Стартувај го и обиди се повторно.');
                    }

                    this.statusText = 'Читам токен...';
                    const certRes = await fetch('http://127.0.0.1:9847/certificate');
                    if (!certRes.ok) throw new Error('Не можам да ги прочитам податоците од токенот.');
                    const cert = await certRes.json();

                    this.statusText = 'Подготвувам текст за потпишување...';
                    const signingRes = await fetch(`/companies/{{ $company->id }}/sales-invoices/${invoiceId}/efaktura/pdf/signing-input`, {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/json',
                            'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content,
                        },
                        body: JSON.stringify({ certificateBase64: cert.certificateBase64 }),
                    });
                    if (!signingRes.ok) {
                        const errorBody = await signingRes.json().catch(() => null);
                        throw new Error(errorBody?.message ?? errorBody?.error ?? 'Серверот не можеше да го подготви текстот за потпишување.');
                    }
                    const { token, signingInput } = await signingRes.json();

                    this.statusText = 'Потпишувам (проверете го прозорецот на SafeNet)...';
                    const signRes = await fetch('http://127.0.0.1:9847/sign', {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/json' },
                        body: JSON.stringify({ data: toBase64Url(signingInput) }),
                    });
                    if (!signRes.ok) throw new Error('Потпишувањето не успеа — провери го PIN-от на токенот.');
                    const { signature } = await signRes.json();

                    this.statusText = 'Преземам ПДФ...';
                    const storeRes = await fetch(`/companies/{{ $company->id }}/sales-invoices/${invoiceId}/efaktura/pdf`, {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/json',
                            'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content,
                        },
                        body: JSON.stringify({ token, signature }),
                    });
                    if (!storeRes.ok) {
                        const storeBody = await storeRes.json().catch(() => null);
                        throw new Error(storeBody?.message ?? storeBody?.error ?? 'Преземањето не успеа.');
                    }

                    window.location.reload();
                } catch (e) {
                    this.error = e.message;
                } finally {
                    this.busy = false;
                }
            },
        }));
```

Two routes are built with a raw template-literal path (`/companies/{{ $company->id }}/sales-invoices/${invoiceId}/efaktura/pdf...`) instead of `@js(route(...))`, because `route()` needs a concrete `$invoice` at Blade-render time but this component is instantiated once per row with a JS-side `invoiceId` — matching the exact same constraint (and the same style of workaround) Laravel/Livewire route-helper generation always hits for per-row dynamic IDs in a `@script` block that isn't itself inside the `@foreach`. Confirm the path segments exactly match `routes/web.php`'s `sales-invoices.efaktura.` group (`companies/{company}/sales-invoices/{salesInvoice}/efaktura/pdf/signing-input` and `.../efaktura/pdf`) before testing.

- [ ] **Step 2: Manual verification — THIS STEP IS FOR THE USER**

With a real accepted invoice (`efaktura_ujp_status_code` = `03`/`04`, no `efaktura_pdf_path` yet) and the bridge running:
- Confirm "Преземи ПДФ" appears only for accepted invoices without a cached path.
- Click it, confirm the SafeNet PIN prompt appears, and after success the page reloads showing a plain "Преземи ПДФ" download link instead of the button.
- Click that download link a second time and confirm it does **not** prompt for the bridge/PIN again (served straight from cache).

- [ ] **Step 3: Commit**

```bash
git add resources/views/livewire/invoicing/sales-invoice-index.blade.php
git commit -m "feat: add per-invoice официјален ПДФ download to sales invoice list"
```

---

### Task 8: Live verification against efakturatest.ujp.gov.mk — THIS STEP IS FOR THE USER

**Files:** none planned — this task's only job is to run the flows for real and report back what needs fixing in `EfakturaJwsService`/`EfakturaStatusController`/`EfakturaPdfController`.

Per Global Constraints, the exact endpoint paths, request wrapper, and response field names for both new УЈП calls are unconfirmed guesses taken from the approved design doc's prose. This task is expected to take multiple real attempts, the same way Task 18 (send) took a 415 fix, an E5000 fix, and two registry-mismatch fixes before it worked.

- [ ] **Step 1: Confirm the tunnel is up**

Ask whether `ssh -N -R 18443:efakturatest.ujp.gov.mk:443 root@46.101.177.209` is still running on the user's PC and whether the droplet's `.env` still has `EFAKTURA_CONNECT_TO=efakturatest.ujp.gov.mk:443:127.0.0.1:18443`. Re-open/re-add if not.

- [ ] **Step 2: Pull this plan's commits to the droplet**

```bash
git pull
```
(User runs this on the droplet over their own SSH session, same as every prior deploy in this project.)

- [ ] **Step 3: Live-test "Освежи статуси" against at least one real sent invoice**

Through `portal.financebuddy.mk`, with the local signing bridge running and the token plugged in, click "Освежи статуси" on a company with at least one `sent`, not-yet-accepted invoice. Read back whatever error/response comes back (paste the raw text). If it's a 415/format error, compare against `EfakturaJwsService::STATUS_REFRESH_PATH` and the `{requestTimestamp, dateFrom, dateTo}` payload shape — fix and redeploy. If it's a shape mismatch in a *successful* response (200 but `updated: 0` when it shouldn't be), add the temporary `EFAKTURA_DEBUG_STATUS_REFRESH` log described in Global Constraints, inspect the real JSON keys, and fix `EfakturaStatusController::refresh()`'s parsing accordingly — then remove the temporary log.

- [ ] **Step 4: Live-test "Преземи ПДФ" against a real accepted invoice**

Once at least one invoice shows an accepted status from Step 3, click "Преземи ПДФ". Same iterate-on-real-response process as Step 3, this time against `EfakturaJwsService::PDF_FETCH_PATH` and `EfakturaPdfController::store()`'s `pdfBase64` key assumption. Also watch for the design doc's flagged external prerequisite: if УЈП rejects with something indicating the account lacks the "DP — Преземање на ПДФ" privilege, that's not a code bug — report it back so the user can request that privilege from УЈП, same as the design doc's error-handling section anticipated.

- [ ] **Step 5: Confirm the downloaded PDF actually renders and contains a QR code**

Open the downloaded file and visually confirm it's a real, readable УЈП-format invoice PDF with a QR code — not just "a 200 response was received."

- [ ] **Step 6: Update this plan's file with what was actually found**

Once both flows work end-to-end, add a short note to the top of this plan file (or to project memory, per the project's [[feedback_context_rot]] convention) recording the real confirmed endpoint paths and response shapes, so a future session doesn't have to re-discover them.
