# Partners (Партнери) — Design

## Context

Fifth of 7 sub-projects from the live-testing feedback batch (see
`2026-07-27-trial-balance-totals-design.md` for the full list and
ordering). Sub-projects 1-4 (Trial Balance totals, Company Profile, Sales
Invoice PDF redesign, Journal Entry overhaul) are all complete and
deployed.

Currently `Partner` (`app/Models/Partner.php`) only has `name`, `tax_id`
(ЕДБ), `email`, `phone`, `address`. There is no edit UI at all —
`PartnerIndex` has only an inline "add" form (create-only), and
`PartnerShow` is read-only (existing since Phase 4a solely to host the
`DocumentManager` component). `PartnerPolicy::update()` already exists in
code but is currently unused by any screen — the same latent gap Company
had before sub-project 2.

## Scope

**New `partners` columns** (migration): `type` (string enum
`individual`/`legal_entity`, default `legal_entity`, NOT NULL),
`registration_number` (ЕМБС, nullable, legal-entity only),
`director_name` (nullable, legal-entity only), `is_vat_registered`
(boolean, default false, legal-entity only), `vat_number` (nullable,
revealed only when `is_vat_registered` is true — a distinct field from
the existing `tax_id`/ЕДБ, not a replacement for it). All four new
type-conditional fields are forced back to `null`/`false` server-side in
`save()` whenever `type` is `individual`, mirroring the existing
`SalesInvoiceForm::setVatTreatment()` zero-forcing pattern (enforced both
client-side, when the type toggle changes, and again server-side on
save, so a stale client payload can't smuggle legal-entity-only data onto
an individual).

**Bank accounts — new child table**, a partner-scoped twin of
`company_bank_accounts` (deliberately a separate table, not a shared/
polymorphic one — avoids touching the already-shipped, already-tested
Company Profile code for zero behavioral gain):

```
partner_bank_accounts
  id
  partner_id (FK, cascade delete)
  bank_name
  account_number
  position (unsigned tinyint, 0-4 — display/entry order)
  timestamps
```

Up to 5 rows per partner, same repeatable-block UI pattern as
`CompanyDashboard` (starts with one empty row; filling it reveals the
next).

## Components

**`PartnerIndex`** ("Партнери") quick-add form gains one more field: a
type selector (Физичко лице / Правно лице, defaulting to Правно лице).
The rest of the new fields (ЕМБС, ДДВ, директор, банкарски сметки) are
NOT on this quick-add form — they're filled in afterward on the
partner's own page. This keeps the quick-add form from growing unwieldy,
matching the design decision already made for Company (full profile
completion happens on a dedicated page, not the list's inline form).

**`PartnerShow`** gains an "Уреди" button revealing an inline edit form
(same pattern as `CompanyDashboard`'s Почетна edit), scoped by
`Gate::authorize('update', $partner)` (existing policy method, not yet
wired to any UI — this is the fix). Fields: name, ЕДБ, е-пошта, телефон,
адреса (existing), type selector, and — only rendered when
`type === 'legal_entity'` — ЕМБС, име на директор, „Обврзник на ДДВ"
checkbox that reveals a ДДВ-број text input when checked, plus the
repeatable bank-account block (same delete-and-reinsert-on-save approach
as Company). Saving wraps the scalar update + bank-account sync in one
`DB::transaction()`, matching Company Profile's final-review fix.

**`Partner` model** gains a `bankAccounts()` `HasMany` relation (ordered
by `position`) and the new fields added to `$fillable`, plus a
`casts()` entry for `is_vat_registered` (boolean), mirroring `Company`.

**`PartnerPolicy`** is unchanged — `update()` already covers this, it's
just finally being used.

**New `PartnerListPdfController`** (`__invoke(Company $company)`, mirrors
the existing `JournalEntryPdfController`/`SalesInvoicePdfController`
pattern): `Gate::authorize('view', $company)`, loads all of the
company's partners ordered by name, renders `pdf.partner-list` via
`Pdf::loadView(...)->download(...)`. Columns: Назив, Тип, ЕДБ, Телефон,
Е-пошта — portrait, table-based layout (this codebase's dompdf has no
flex/grid support at all, confirmed during the Sales Invoice PDF
redesign — every column layout here must use `<table>/<td>`). A
"Преземи PDF" link is added next to `PartnerIndex`'s page heading.

## Testing

- `Partner` model/factory: extend the factory with the new fields
  (sensible legal-entity defaults), add an individual-type factory state.
- `PartnerIndex`: quick-add form persists the chosen type; existing
  create-flow tests still pass.
- `PartnerShow`: edit form saves all new scalar fields; saves 1-5 bank
  account rows correctly (including replacing a previous set on
  re-save); rejects/ignores an attempt to add a 6th row; switching type
  from legal_entity to individual (with data already filled in) clears
  ЕМБС/director/VAT fields on save, verified via a direct server-side
  save call (not just the client-side toggle) so the test actually
  exercises the save()-time guard, not only the UI's own reveal/hide
  logic — matching the "prove it can't be bypassed" lesson from the
  ДДВ-04 sub-project. Non-admin/non-accountant/non-client-of-this-company
  users cannot see "Уреди" or submit the update action.
- `PartnerListPdfController`: authorized user gets a PDF download;
  cross-company access is rejected; an actual rendered PDF (or its
  content stream) is inspected for the expected columns, not just an
  HTML-string assertion — the dompdf-flex lesson applies broadly to
  "don't trust that HTML looks right," and table-based layout has no
  flex pitfall to begin with, but the rendered-PDF check stays cheap
  insurance.
