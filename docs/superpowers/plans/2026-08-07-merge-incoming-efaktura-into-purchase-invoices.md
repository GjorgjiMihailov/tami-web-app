# Спојување на „Влезни е-Фактури" во „Влезни фактури" — имплементациски план

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Merge the separate „Влезни е-Фактури" screen into the existing „Влезни фактури" screen so the sidebar has one link instead of two, with the discover/accept/reject/pdf flows relocated but functionally unchanged.

**Architecture:** Pure UI relocation — no changes to any Phase 8c backend (controllers, services, `incoming_efaktura_documents` table). `PurchaseInvoiceIndex`'s query grows to also fetch undecided `IncomingEfakturaDocument` rows and eager-load the accepted-document link on each invoice; `purchase-invoice-index.blade.php` absorbs `incoming-efaktura-index.blade.php`'s markup and Alpine.js verbatim; the standalone index route/component/sidebar link are deleted.

**Tech Stack:** Laravel 11, Livewire 3, Alpine.js (`@script` blocks), PHPUnit.

## Global Constraints

- No changes to `app/Http/Controllers/EfakturaIncoming*Controller.php`, `app/Services/Efaktura/*`, or the `incoming_efaktura_documents` table/model — this plan only moves Blade/Livewire/route code.
- Every Alpine `@script` helper function must stay declared as `const name = (...) => {...}`, never `function name(){}` — `@script` blocks evaluate as one expression, so a plain function declaration is invisible outside itself (a real bug already hit twice in this project).
- The pending-documents section must render nothing at all (no heading, no empty-state row) when there are zero undecided documents — it is not a permanent fixture of the page.
- PHPUnit class-based tests (not Pest), `RefreshDatabase` trait, Livewire component tests via `Livewire::test(...)`.

---

## Task 1: `PurchaseInvoice::incomingEfakturaDocument()` relation

**Files:**
- Modify: `app/Models/PurchaseInvoice.php`
- Test: `tests/Unit/Models/PurchaseInvoiceIncomingEfakturaTest.php`

**Interfaces:**
- Produces: `PurchaseInvoice::incomingEfakturaDocument(): HasOne` — the inverse of the existing `IncomingEfakturaDocument::purchaseInvoice(): BelongsTo`.

- [ ] **Step 1: Write the failing test**

```php
<?php

namespace Tests\Unit\Models;

use App\Models\Company;
use App\Models\IncomingEfakturaDocument;
use App\Models\Partner;
use App\Models\PurchaseInvoice;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PurchaseInvoiceIncomingEfakturaTest extends TestCase
{
    use RefreshDatabase;

    public function test_has_one_incoming_efaktura_document(): void
    {
        $company = Company::factory()->create();
        $partner = Partner::factory()->for($company)->create();
        $invoice = PurchaseInvoice::factory()->for($company)->create(['partner_id' => $partner->id]);
        $document = IncomingEfakturaDocument::factory()->for($company)->create(['purchase_invoice_id' => $invoice->id]);

        $this->assertTrue($invoice->fresh()->incomingEfakturaDocument->is($document));
    }

    public function test_incoming_efaktura_document_is_null_for_a_manually_entered_invoice(): void
    {
        $company = Company::factory()->create();
        $partner = Partner::factory()->for($company)->create();
        $invoice = PurchaseInvoice::factory()->for($company)->create(['partner_id' => $partner->id]);

        $this->assertNull($invoice->fresh()->incomingEfakturaDocument);
    }
}
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `php artisan test tests/Unit/Models/PurchaseInvoiceIncomingEfakturaTest.php`
Expected: FAIL with "Call to undefined relationship [incomingEfakturaDocument]" (or similar).

- [ ] **Step 3: Add the relation**

In `app/Models/PurchaseInvoice.php`, add the import `use Illuminate\Database\Eloquent\Relations\HasOne;` alongside the existing relation imports, and add this method near the other relations (e.g. after `payments()`):

```php
    public function incomingEfakturaDocument(): HasOne
    {
        return $this->hasOne(IncomingEfakturaDocument::class);
    }
```

`IncomingEfakturaDocument` is in the same `App\Models` namespace, no import needed.

- [ ] **Step 4: Run the test to verify it passes**

Run: `php artisan test tests/Unit/Models/PurchaseInvoiceIncomingEfakturaTest.php`
Expected: 2 passed.

- [ ] **Step 5: Commit**

```bash
git add app/Models/PurchaseInvoice.php tests/Unit/Models/PurchaseInvoiceIncomingEfakturaTest.php
git commit -m "feat: add PurchaseInvoice::incomingEfakturaDocument() relation"
```

---

## Task 2: Merge the „Влезни е-Фактури" screen into „Влезни фактури"

**Files:**
- Modify: `app/Livewire/Invoicing/PurchaseInvoiceIndex.php`
- Modify: `resources/views/livewire/invoicing/purchase-invoice-index.blade.php`
- Modify: `routes/web.php`
- Modify: `resources/views/livewire/layout/sidebar.blade.php`
- Modify: `tests/Feature/PurchaseInvoiceIndexTest.php`
- Delete: `app/Livewire/Invoicing/IncomingEfakturaIndex.php`
- Delete: `resources/views/livewire/invoicing/incoming-efaktura-index.blade.php`
- Delete: `tests/Feature/IncomingEfakturaIndexTest.php`

**Interfaces:**
- Consumes: `PurchaseInvoice::incomingEfakturaDocument()` (Task 1), `IncomingEfakturaDocument::REJECT_REASONS`/`REJECT_REASON_OTHER`/`DECISION_ACCEPTED`/`DECISION_REJECTED` constants, all six `incoming-efaktura.discover.*` routes, `incoming-efaktura.accept*`/`reject*`/`pdf.*` routes (all unchanged, already deployed and live-verified).
- Produces: nothing new for later tasks — this is the final task in this plan.

This task moves markup/JS/query logic; it does not invent new behavior. Work through it in one pass rather than TDD-ing each fragment, since the deliverable is "the merged page renders and behaves like the sum of the two old pages" — verified by the tests in Step 5.

- [ ] **Step 1: Update the Livewire component**

Replace the full contents of `app/Livewire/Invoicing/PurchaseInvoiceIndex.php` with:

```php
<?php

namespace App\Livewire\Invoicing;

use App\Models\Company;
use App\Models\IncomingEfakturaDocument;
use App\Models\PurchaseInvoice;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Layout;
use Livewire\Component;

#[Layout('layouts.app')]
class PurchaseInvoiceIndex extends Component
{
    public Company $company;

    public string $statusFilter = '';

    public function mount(Company $company): void
    {
        Gate::authorize('view', $company);
        $this->company = $company;
    }

    public function render()
    {
        $invoices = PurchaseInvoice::where('company_id', $this->company->id)
            ->when($this->statusFilter, fn ($q) => $q->where('status', $this->statusFilter))
            ->with(['partner', 'lines', 'payments', 'incomingEfakturaDocument'])
            ->orderByDesc('invoice_date')
            ->orderByDesc('id')
            ->get();

        $pendingDocuments = IncomingEfakturaDocument::where('company_id', $this->company->id)
            ->whereNull('decision')
            ->orderByDesc('doc_date')
            ->orderByDesc('id')
            ->get();

        return view('livewire.invoicing.purchase-invoice-index', [
            'invoices' => $invoices,
            'pendingDocuments' => $pendingDocuments,
        ]);
    }
}
```

- [ ] **Step 2: Replace the Blade view**

Replace the full contents of `resources/views/livewire/invoicing/purchase-invoice-index.blade.php` with:

```blade
<div x-data="incomingEfakturaDiscover()">
    <div class="flex items-center justify-between mb-4">
        <h1 class="text-2xl font-bold text-gray-800">Влезни фактури — {{ $company->name }}</h1>
        <div class="flex items-center gap-3">
            @if ($company->hasEfakturaAccess() && $company->efaktura_credential_mode === \App\Models\Company::EFAKTURA_MODE_OWN)
                <button type="button" @click="run()" :disabled="busy" class="border border-brand text-brand px-3 py-1.5 rounded-md text-sm disabled:opacity-50">
                    <span x-show="!busy">Провери за е-Фактури</span>
                    <span x-show="busy" x-text="statusText"></span>
                </button>
            @endif
            <a href="{{ route('purchase-invoices.create', $company) }}" class="bg-brand text-white px-3 py-1.5 rounded-md text-sm">Нова влезна фактура</a>
        </div>
    </div>

    <p x-show="error" x-text="error" class="text-red-600 text-sm mb-3"></p>
    <p x-show="result" x-text="result" class="text-green-700 text-sm mb-3"></p>

    @if ($company->hasEfakturaAccess() && $company->efaktura_credential_mode === \App\Models\Company::EFAKTURA_MODE_OWN)
        <p class="text-sm text-gray-500 mb-4">
            Последна проверка за е-Фактури:
            {{ $company->efaktura_purchase_last_checked_at ? \App\Support\Format::date($company->efaktura_purchase_last_checked_at) : 'никогаш' }}
        </p>
    @endif

    @if ($pendingDocuments->isNotEmpty())
        <h2 class="text-lg font-semibold text-gray-700 mb-2">Неодлучени е-Фактури</h2>
        <x-card padding="p-0" class="overflow-hidden mb-6">
        <table class="min-w-full divide-y divide-gray-200">
            <thead>
                <tr class="text-left text-sm text-gray-500">
                    <th class="py-2 px-4">Добавувач</th>
                    <th class="py-2 px-4">Датум</th>
                    <th class="py-2 px-4">Износ</th>
                    <th class="py-2 px-4">Статус кај УЈП</th>
                    <th class="py-2 px-4"></th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-100">
                @foreach ($pendingDocuments as $document)
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
                            @if ($company->hasEfakturaAccess() && $company->efaktura_credential_mode === \App\Models\Company::EFAKTURA_MODE_OWN)
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
                            @else
                                <span class="text-gray-400">—</span>
                            @endif
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>
        </x-card>
    @endif

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
                <th class="py-2 px-4">Бр. кај добавувач</th>
                <th class="py-2 px-4">Добавувач</th>
                <th class="py-2 px-4">Датум</th>
                <th class="py-2 px-4">Статус</th>
                <th class="py-2 px-4">Вкупно</th>
                <th class="py-2 px-4"></th>
            </tr>
        </thead>
        <tbody class="divide-y divide-gray-100">
            @forelse ($invoices as $invoice)
                <tr class="text-sm">
                    <td class="py-2 px-4">{{ $invoice->supplier_invoice_number }}</td>
                    <td class="py-2 px-4">{{ $invoice->partner->name }}</td>
                    <td class="py-2 px-4">{{ \App\Support\Format::date($invoice->invoice_date) }}</td>
                    <td class="py-2 px-4"><x-badge :status="$invoice->status">{{ \App\Support\Format::invoiceStatus($invoice->status) }}</x-badge></td>
                    <td class="py-2 px-4">{{ \App\Support\Format::money($invoice->grandTotal()) }}</td>
                    <td class="py-2 px-4">
                        <a href="{{ route('purchase-invoices.show', [$company, $invoice]) }}" class="text-brand hover:underline mr-2">Прегледај</a>
                        @if ($invoice->incomingEfakturaDocument)
                            @if ($invoice->incomingEfakturaDocument->efaktura_pdf_path)
                                <a href="{{ route('incoming-efaktura.pdf.download', [$company, $invoice->incomingEfakturaDocument]) }}" class="text-brand hover:underline">Преземи ПДФ</a>
                            @elseif ($company->hasEfakturaAccess() && $company->efaktura_credential_mode === \App\Models\Company::EFAKTURA_MODE_OWN)
                                <div x-data="incomingEfakturaPdfFetch({{ $invoice->incomingEfakturaDocument->id }})" class="inline-block">
                                    <button type="button" @click="run()" :disabled="busy" class="text-brand hover:underline disabled:opacity-50">
                                        <span x-show="!busy">Преземи ПДФ</span>
                                        <span x-show="busy" x-text="statusText"></span>
                                    </button>
                                    <p x-show="error" x-text="error" class="text-red-600 text-xs mt-1"></p>
                                </div>
                            @endif
                        @endif
                    </td>
                </tr>
            @empty
                <tr><td colspan="6" class="py-4 px-4 text-gray-500">Нема внесено влезни фактури.</td></tr>
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
        // not `function name(){}` — Livewire's script block evaluates as one expression, so a plain
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

- [ ] **Step 3: Remove the standalone route, its import, and the old controller-target file**

In `routes/web.php`:
- Delete the line `use App\Livewire\Invoicing\IncomingEfakturaIndex;`
- Delete this whole route group (it's the one with only the `index` route — do NOT touch the two `incoming-efaktura.*` groups below it that hold `discover.*`/`accept*`/`reject*`/`pdf.*`, those stay exactly as-is):

```php
Route::middleware(['auth'])->prefix('companies/{company}')->name('incoming-efaktura.')->group(function () {
    Route::get('/incoming-efaktura', [IncomingEfakturaIndex::class, '__invoke'])->name('index');
});
```

Delete the file `app/Livewire/Invoicing/IncomingEfakturaIndex.php` entirely.

Delete the file `resources/views/livewire/invoicing/incoming-efaktura-index.blade.php` entirely (its content is now in `purchase-invoice-index.blade.php` from Step 2).

- [ ] **Step 4: Remove the sidebar link**

In `resources/views/livewire/layout/sidebar.blade.php`:
- Delete this link (currently right after the „Влезни фактури" link, before „Нова влезна фактура"):

```blade
                        <a href="{{ route('incoming-efaktura.index', $company) }}" wire:navigate
                           class="block px-4 py-1.5 text-sm {{ request()->routeIs('incoming-efaktura.*') ? 'text-white font-medium' : 'text-gray-400 hover:text-white' }}">Влезни е-Фактури</a>
```

- Remove ` || request()->routeIs('incoming-efaktura.*')` from the module-toggle `<button>`'s active-state condition a few lines above (it referenced the page just deleted; the module still highlights correctly via `purchase-invoices.*`, which the merged page already matches).

- [ ] **Step 5: Update the test file**

Delete `tests/Feature/IncomingEfakturaIndexTest.php` entirely — its coverage is folded into `PurchaseInvoiceIndexTest.php` below.

Replace the full contents of `tests/Feature/PurchaseInvoiceIndexTest.php` with:

```php
<?php

namespace Tests\Feature;

use App\Livewire\Invoicing\PurchaseInvoiceIndex;
use App\Models\Company;
use App\Models\IncomingEfakturaDocument;
use App\Models\Partner;
use App\Models\PurchaseInvoice;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class PurchaseInvoiceIndexTest extends TestCase
{
    use RefreshDatabase;

    public function setUp(): void
    {
        parent::setUp();
        Role::findOrCreate('admin');
    }

    public function test_it_lists_the_companys_purchase_invoices(): void
    {
        $company = Company::factory()->create();
        $partner = Partner::factory()->for($company)->create(['name' => 'Acme Supplies DOOEL']);
        PurchaseInvoice::factory()->for($company)->create(['partner_id' => $partner->id, 'supplier_invoice_number' => 'SUP-100']);
        $admin = User::factory()->create();
        $admin->assignRole('admin');
        $this->actingAs($admin);

        Livewire::test(PurchaseInvoiceIndex::class, ['company' => $company])
            ->assertSee('Acme Supplies DOOEL')
            ->assertSee('SUP-100');
    }

    public function test_it_filters_by_status(): void
    {
        $company = Company::factory()->create();
        $partner = Partner::factory()->for($company)->create();
        PurchaseInvoice::factory()->for($company)->create(['partner_id' => $partner->id, 'status' => 'draft', 'supplier_invoice_number' => 'DRAFT-1']);
        PurchaseInvoice::factory()->for($company)->create(['partner_id' => $partner->id, 'status' => 'confirmed', 'supplier_invoice_number' => 'CONF-1']);
        $admin = User::factory()->create();
        $admin->assignRole('admin');
        $this->actingAs($admin);

        Livewire::test(PurchaseInvoiceIndex::class, ['company' => $company])
            ->set('statusFilter', 'confirmed')
            ->assertSee('CONF-1')
            ->assertDontSee('DRAFT-1');
    }

    public function test_it_shows_pending_incoming_efaktura_documents(): void
    {
        $company = Company::factory()->create();
        IncomingEfakturaDocument::factory()->for($company)->create(['seller_name' => 'Тест Добавувач ДООЕЛ']);
        $admin = User::factory()->create();
        $admin->assignRole('admin');
        $this->actingAs($admin);

        Livewire::test(PurchaseInvoiceIndex::class, ['company' => $company])
            ->assertSee('Неодлучени е-Фактури')
            ->assertSee('Тест Добавувач ДООЕЛ');
    }

    public function test_it_hides_the_pending_section_when_there_are_no_pending_documents(): void
    {
        $company = Company::factory()->create();
        $admin = User::factory()->create();
        $admin->assignRole('admin');
        $this->actingAs($admin);

        Livewire::test(PurchaseInvoiceIndex::class, ['company' => $company])
            ->assertDontSee('Неодлучени е-Фактури');
    }

    public function test_it_shows_a_pdf_download_link_for_an_invoice_from_an_accepted_efaktura_document_with_a_cached_pdf(): void
    {
        $company = Company::factory()->create();
        $partner = Partner::factory()->for($company)->create();
        $invoice = PurchaseInvoice::factory()->for($company)->create(['partner_id' => $partner->id]);
        IncomingEfakturaDocument::factory()->for($company)->create([
            'decision' => IncomingEfakturaDocument::DECISION_ACCEPTED,
            'purchase_invoice_id' => $invoice->id,
            'efaktura_pdf_path' => 'efaktura-pdfs/incoming/1/1.pdf',
        ]);
        $admin = User::factory()->create();
        $admin->assignRole('admin');
        $this->actingAs($admin);

        Livewire::test(PurchaseInvoiceIndex::class, ['company' => $company])
            ->assertSee('Преземи ПДФ');
    }
}
```

- [ ] **Step 6: Run the tests**

Run: `php artisan test tests/Feature/PurchaseInvoiceIndexTest.php`
Expected: 5 passed.

- [ ] **Step 7: Run the full suite**

Run: `php artisan test`
Expected: all tests passed (2 fewer than before this task, since `IncomingEfakturaIndexTest.php`'s 2 tests were deleted — their coverage is folded into the 2 new pending-section tests above, so the total should be roughly flat, not lower by exactly 2. Confirm no unexpected failures, especially none in files that reference `incoming-efaktura.index` route by name — grep for it first if anything fails.)

- [ ] **Step 8: Commit**

```bash
git add app/Livewire/Invoicing/PurchaseInvoiceIndex.php resources/views/livewire/invoicing/purchase-invoice-index.blade.php routes/web.php resources/views/livewire/layout/sidebar.blade.php tests/Feature/PurchaseInvoiceIndexTest.php
git rm app/Livewire/Invoicing/IncomingEfakturaIndex.php resources/views/livewire/invoicing/incoming-efaktura-index.blade.php tests/Feature/IncomingEfakturaIndexTest.php
git commit -m "feat: merge Влезни е-Фактури screen into Влезни фактури"
```
