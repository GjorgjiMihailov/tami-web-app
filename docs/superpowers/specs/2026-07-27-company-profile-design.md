# Company Profile (Почетна) — Design

## Context

Second of 7 sub-projects from a batch of live-testing feedback (see
`2026-07-27-trial-balance-totals-design.md` for the full list and
ordering). This one is the foundation sub-project — several later pieces
(Sales Invoice redesign in particular) render company data, its logo, and
an invoice footer note that only exist as fields once this lands.

Currently, after picking a company, "Почетна" (`CompanyDashboard`) is
empty except for five module quick-link cards. Company data can only be
entered at creation (name only, via `CompanyIndex`'s add form) or edited
through a minimal inline form on the `CompanyIndex` list screen (currently
just `bank_account` and `is_vat_registered`). There is no logo upload
anywhere in the app, despite `companies.logo_path` existing since Phase 0.

## Scope

**New company fields** (migration on `companies`): `short_name`,
`registration_number` (ЕМБС), `nkd_code`, `nkd_name` (free text for both
— no official NKD code list lookup this phase), `website`,
`director_name`, `director_phone`, `director_email`, `logo_position`
(string enum `left`/`center`/`right`, default `left`),
`invoice_footer_note` (text, nullable). All nullable/optional except the
already-required `name`. Existing fields reused as-is: `name` (full
legal name), `tax_id` (ЕДБ), `address`, `phone`, `email`, `logo_path`.

**Bank accounts — new child table**, replacing the single `bank_account`
string column:

```
company_bank_accounts
  id
  company_id (FK, cascade delete)
  bank_name
  account_number
  position (unsigned tinyint, 0-4 — display/entry order)
  timestamps
```

Up to 5 rows per company. Confirmed via grep that `bank_account` is only
read/written in `CompanyIndex` (the inline edit form being removed by this
same change) and `Company::$fillable` — no invoice/report code depends on
it yet, so this is a clean cutover: a migration copies any existing
non-null `bank_account` value into one `company_bank_accounts` row (bank
name left blank, since the old field never captured one), then the
`bank_account` column is dropped.

**Logo storage:** local `public` disk (`storage/app/public`), not Google
Drive — unlike the Documents module, a logo needs to render on every
profile view and (later) every invoice, so it must be fast and always
available without a Drive API round-trip. Uploaded via Livewire's
`WithFileUploads`, subject to the existing 25MB temporary-upload limit
(raised in Phase 4a). Requires `php artisan storage:link` on the server
(not yet run — added as a one-time deploy step, same category as the
Phase 0b Google OAuth one-time setup).

## Components

**`CompanyDashboard`** (Почетна) gains an "Уреди" button that reveals an
inline edit form (not a modal — one less moving part, consistent with
this codebase's existing inline-edit patterns like `PartnerIndex`) with:
every new field above, a repeatable bank-account block (starts with one
empty row; filling it reveals the next, up to 5 — plain Livewire array
property, no separate table screen), logo upload + position picker
(radio: лево/средина/десно), and the invoice footer note textarea. Saving
validates and persists all fields plus syncs the `company_bank_accounts`
rows (delete-and-reinsert on save, simplest correct approach given the
max-5, no-independent-identity nature of these rows). Gated on
`Gate::authorize('update', $company)` — unchanged, admin-only.

**`CompanyIndex`** ("Компании") loses its inline edit form entirely
(`editingCompanyId`, `editBankAccount`, `editIsVatRegistered` and their
methods removed). It becomes what it visually already mostly is: a list
of companies plus the existing "add company" form (name only) — full
profile completion now happens exclusively on Почетна after creation.

**`Company` model** gains a `bankAccounts()` `HasMany` relation (ordered
by `position`) and the new fields added to `$fillable`; `bank_account` is
removed from `$fillable`.

## Testing

- Migration test/assertion: an existing `bank_account` value survives as
  one `company_bank_accounts` row after migrating.
- `CompanyDashboard`: edit form saves all new scalar fields; saves 1-5
  bank account rows correctly (including replacing a previous set on
  re-save); rejects/ignores an attempt to add a 6th row; logo upload
  updates `logo_path` and is retrievable; non-admin (accountant/client)
  cannot see the "Уреди" button or submit the update action
  (policy-level, mirroring existing `CompanyPolicy::update` tests).
- `CompanyIndex`: existing "add company" test still passes; old edit-form
  tests are removed/replaced since that UI no longer exists.
