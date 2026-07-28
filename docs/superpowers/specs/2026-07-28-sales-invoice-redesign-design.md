# Sales Invoice (Фактура) PDF Redesign — Design

**Date:** 2026-07-28
**Sub-project:** #3 of 7 in the 2026-07-27 live-testing feedback batch (user's top priority)

## Scope

This redesign covers **only the printed/downloaded PDF document** —
`resources/views/pdf/sales-invoice.blade.php`, generated via
`barryvdh/laravel-dompdf`. The on-screen `SalesInvoiceShow` Livewire page (the
working screen with action buttons — Потврди/Плаќања/Означи како
испратена/etc.) is explicitly **out of scope** and stays exactly as it is
today.

## Visual style

"Modern, on-brand": a thin brand-orange (`#ff6600`) accent bar across the top
of the page, rounded info blocks with light-gray/orange-tinted backgrounds
for the issuer/recipient/payment sections, matching the app's existing
Manrope/rounded-card visual language (established in the 2026-07-23/24
visual redesign).

**Technical constraint:** dompdf's CSS support does not include flexbox.
The side-by-side block layout (issuer/recipient blocks, totals+payment
boxes) must be implemented with CSS tables or floated/inline-block divs,
not `display: flex`. dompdf does support `border-radius` and background
colors on block elements, so the rounded-card look is achievable — the
implementation just won't literally reuse flex-based markup from any HTML
mockup used during brainstorming.

## Header

- Logo rendered at the position set by `Company::logo_position`
  (left/center/right, from the Company Profile sub-project). If the company
  has no `logo_path`, no placeholder box is rendered — the layout is clean
  without it.
- Invoice number shown prominently as a badge: `ФАКТУРА {fiscal_year}/{invoice_number}`.
- Invoice date and due date shown near the header (`invoice_date`, `due_date`,
  via existing `App\Support\Format::date()`).

## Issuer block (Издавач)

Compact set, pulled from `Company`: name, address, tax_id (ЕДБ),
registration_number (ЕМБС), phone/email. Website, NKD, and
director/contact fields are **not** printed on the invoice (they're for
official/registry documents, not routine sales invoices).

Bank accounts are **not** repeated here — they move to the payment-info box
below the totals (see below), to avoid duplicating the same data in two
places.

## Recipient block (Купувач)

Unchanged from today: name, address, tax_id (ЕДБ) from `Partner`. Partner
doesn't yet have registration_number/bank fields (that's sub-project #5) so
nothing more is available to print.

## Line items table

Real `<table>` markup (not div-based cards) with a `<thead>` — this is what
lets dompdf repeat the column header automatically on every page and let
rows flow naturally across a page break, which matters once an invoice has
enough lines to fill more than one page. The table always fills the page
width and continues onto a new page rather than being artificially
constrained to partial-page height.

Columns:

| Р.б. | Опис | Кол. | Ед. цена | ДДВ % | Вкупно со ДДВ |
|---|---|---|---|---|---|

- **Р.б.** — new: 1-based row number.
- **ДДВ %** — shows the line's `vat_rate`, with the `vat_treatment` label
  appended in parentheses when it's not `standard` (mirrors the Show
  screen's existing convention), e.g. `0% (Извоз)`, `0% (Ослободено без
  одбивање)`.
- **Вкупно со ДДВ** (renamed from today's misleadingly-labeled "Вкупно",
  which actually showed the VAT-exclusive base) — per-line gross total:
  `$line->lineTotal() + $line->vatAmount()`.

**Non-VAT-registered company** (`Company::is_vat_registered === false`):
the **ДДВ %** column is dropped entirely (every line is 0% anyway), and the
last column is simply labeled **Вкупно** (still the same computed value,
since VAT is always zero).

## Totals + payment info

Unchanged computation, restyled into an orange-tinted card:
Основа / ДДВ / Вкупно / За доплата (`subtotal()`, `vatTotal()`,
`grandTotal()`, `balanceDue()` from `HasInvoiceTotals`).

Next to it, a "Начин на плаќање" box lists every row from
`Company::bankAccounts()` (bank name + account number) so the client has
everything needed to pay directly on the document, without needing to
cross-reference the header.

**Payment status** (Платена/Делумично/Неплатена/Задоцнета) is deliberately
**not** shown on the PDF — the document stays neutral regardless of when
it's printed/downloaded relative to actual payment. That status only lives
in the app's Show screen.

## Footnotes

Two independent footnote sources, stacked in this order when both apply:

1. **Non-VAT-registered legal note** (only when `!is_vat_registered`):
   generic wording, no specific ЗДДВ article cited (avoiding a citation
   neither the user nor Claude could verify as exactly correct):
   > „Фирмава не е ДДВ обврзник."
2. **Company's own footer note** (`Company::invoice_footer_note`), if set —
   printed below the legal note.

## Out of scope / unchanged

- `SalesInvoiceShow`, `SalesInvoiceForm`, `SalesInvoiceIndex` Livewire
  screens — no changes.
- Numbering, GL posting, payment recording logic — no changes, this is a
  presentation-only redesign of the existing PDF template.
- Partner fields (EMBS, bank account, VAT-registered flag) — deferred to
  sub-project #5 (Partners).

## Implementation note

Single file change in practice: `resources/views/pdf/sales-invoice.blade.php`
(styles + markup), no new columns, migrations, or PHP classes needed — every
data point used here already exists on `Company`, `Partner`,
`SalesInvoice`/`SalesInvoiceLine` as of the Company Profile sub-project.
