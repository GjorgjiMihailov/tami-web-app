# Phase 4b: ДДВ-04 VAT Return Report — Design

## Context

Phase 4 (Documents & Reports) was split into 4a (Documents subsystem, complete)
and 4b (statutory reports). Phase 4b itself covers two report types —
ДДВ-04 (VAT return) and year-end filings — which turned out to be
materially different in size and shape once explored: year-end filings in
Macedonia mean a set of annual statutory forms (balance sheet, income
statement, tax balance, and related annexes), a much larger effort than a
single periodic return. That work is deferred to its own future phase
(4c) once its exact requirements are scoped. This document covers only the
ДДВ-04 VAT return.

Within ДДВ-04 itself, a further scope decision was made during
brainstorming: the official form (`sl.79_DDV-04_30.03.2022 (1).pdf`, 32
fields) includes reverse-charge/non-resident/import scenarios (fields
10–19, 23–28, 32) that require materially more modeling (a domestic
reverse-charge-as-supplier scenario also legally requires submitting a
separate "Извештај за извршени промети" attachment report to UJP, not just
a number on the form). This phase covers the return's **core fields**
only — standard-rate domestic sales, exports, exempt supplies, and input
VAT deduction. Reverse-charge and the attachment report are deferred to a
fast follow-on phase once this core lands.

## Scope

**In scope (computed fields):**
- 01–06: standard-rate domestic sales (18%, 10%, 5%) — base + VAT
- 07: exports — base only (0%)
- 08/09: exempt supplies, with/without deduction right — base only (0%)
- 20: total output VAT (sum of 02+04+06)
- 21/22: input VAT with deduction right — base + VAT
- 29: total deductible input VAT (= 22, since 24/26/28 stay 0)
- 30: always 0 this phase (rare manual adjustments — user adds by hand if needed)
- 31: net VAT due/refund (20 − 29 − 30)

**Out of scope this phase (left blank/zero, deferred to a follow-on):**
fields 10–19 (reverse-charge, non-resident recipient/supplier scenarios),
23–28 (reverse-charge/import on the input side), 32 (cession of
receivable), and the associated "Извештај" attachment reports.

## Data model

`sales_invoice_lines` gains one new column: `vat_treatment` (string,
default `'standard'`), with allowed values `standard`, `export`,
`exempt_with_credit`, `exempt_without_credit`. When a line's treatment is
anything other than `standard`, its `vat_rate` is forced to `0.00` — both
client-side (disabled input) and server-side in `SalesInvoiceForm::save()`
(so it can't be bypassed by direct manipulation).

No schema change is needed on the purchase side — the existing
`vat_deductible` boolean on `PurchaseInvoiceLine` already gives the core
return everything it needs for fields 21/22/29.

## Computation engine

A new plain PHP query class, `App\Reports\Ddv04Query`, following this
codebase's established pattern of Livewire-independent report engines
(`LedgerCardQuery`, `TrialBalanceQuery` from Phase 1;
`StockLevelQuery`/`ItemMovementCardQuery` from Phase 2). Given a
`Company` and a date range (`from`, `to`), it returns an array/object keyed
by field number:

- Fields 01–06: confirmed `SalesInvoiceLine`s with `vat_treatment =
  'standard'` in the period (by `invoice_date`), grouped by `vat_rate`
  (18.00 → 01/02, 10.00 → 03/04, 5.00 → 05/06) — summed base amount
  (`quantity * unit_price`) and VAT amount.
- Field 07: confirmed lines with `vat_treatment = 'export'` — summed base
  amount only.
- Fields 08/09: confirmed lines with `vat_treatment =
  'exempt_with_credit'` / `'exempt_without_credit'` — summed base amount
  only.
- Field 20: 02 + 04 + 06.
- Fields 21/22: confirmed `PurchaseInvoiceLine`s with `vat_deductible =
  true` in the period — summed base amount and VAT amount.
- Field 29: equal to 22 (24/26/28 always 0 this phase).
- Field 30: always 0.
- Field 31: 20 − 29 − 30.

Only invoices with `status = 'confirmed'` are included (matching how GL
posting already only happens on confirm), filtered by `invoice_date`
falling within the chosen range. All monetary aggregation uses the
existing `App\Support\Bcmath::roundHalfUp()` helper.

## UI

A new `App\Livewire\Reports\Ddv04Report` component — a new `Reports`
namespace, since this is the first statutory report that isn't specific to
the Accounting or Inventory modules. It presents a from/to date-range
picker (mirroring the existing Ledger Card / Trial Balance reports) and
renders each in-scope field number with its label and computed amount,
laid out to visually mirror the paper form's two sections ("Промет на
добра и услуги" / "Влезни исполнувања со право на одбивка") so the numbers
can be read directly into UJP's e-tax portal (https://etax.ujp.gov.mk) —
no PDF or file export is produced; ДДВ-04 is filed by manual entry on that
portal, not by uploading a file.

Route: `companies/{company}/reports/ddv04`, named `reports.ddv04`,
registered via the existing array-callable route convention.

Permissions: gated on `Gate::authorize('view', $company)` — the same
company-visibility check already used for Ledger Card/Trial Balance and
Phase 4a's Documents browser. Any user who can already view the company's
invoices (including clients, read-only) can view this report. No new
policy class.

## Capturing the new field

`SalesInvoiceForm`'s line editor gets a "VAT treatment" dropdown per line
(default: Standard) alongside the existing VAT-rate input. Selecting a
non-standard treatment disables the rate input and zeroes it, enforced
again server-side in `save()`. `SalesInvoiceShow`'s line table displays
the treatment label when it isn't Standard.

## Testing

- `Ddv04QueryTest` (unit-style, no Livewire): one test per field group
  (standard rates, export, exempt with/without credit, input deduction,
  net total arithmetic), plus a test confirming draft/cancelled invoices
  and out-of-range dates are excluded from every field.
- `Ddv04ReportTest`: date-range filtering renders the right computed
  values; company-visibility gate (client can view their own company's
  report, cross-company access forbidden).
- `SalesInvoiceFormTest`: new cases proving a non-standard treatment forces
  `vat_rate` to 0 server-side even if a client attempts to submit a
  nonzero rate alongside it.
