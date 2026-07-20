# Phase 3a: Sales Invoicing — Design

**Date:** 2026-07-20
**Status:** Approved

## Purpose

Build core outgoing (sales) invoicing: create, confirm, and track invoices issued by a client company to its customers (Partners), linked to the Chart of Accounts (Phase 1) and Items/Warehouses (Phase 2). Confirming an invoice auto-posts a balanced journal entry and, for stock-tracked lines, auto-issues inventory at weighted-average cost — closing the loop Phase 2 deliberately left open. Purchase (incoming) invoicing is deferred to Phase 3b, a follow-on phase that reuses this phase's shared plumbing (line items, VAT calculation, PDF layout, payment recording). е-Фактура integration remains deferred to Phase 8, per the original roadmap.

## Scope Decisions (confirmed with user)

- **Sales invoices only** in this phase. Purchase invoices are Phase 3b.
- **GL auto-posting on confirm** — not a separate manual step. Matches how the rest of the app already treats accounting as the source of truth.
- **Inventory auto-move on confirm**, and the GL now tracks a real inventory asset account that should reconcile with `stock_levels` — Phase 2's inventory value and the ledger become linked for the first time.
- **Invoice lines may reference an Item or be free-text** — covers services and one-off charges without forcing everything into the Item catalog.
- **Payment recording is in scope** — due dates, partial/full payments, each posting its own GL entry, with derived paid/unpaid/overdue status.
- **PDF generation only, no real email sending.** Mail is currently a `log` driver with no SMTP configured; wiring up real delivery is an unrelated infra decision deferred to a small follow-up once an email account/service is chosen. "Sent" is a manually-set flag for now.
- **MKD only.** No foreign-currency invoicing this phase, despite `ExchangeRate` already existing from Phase 1 — avoids extra complexity (rate-locking, FX gain/loss on payment) in an already-large phase.
- **Clients get full read-write** on their own company's invoices and payments — matches Phase 2's Inventory model (client-operated data), not Phase 1's Accounting model (client-read-only).
- **Company gains two new fields**: `bank_account` (IBAN, for the printed invoice) and `is_vat_registered` (boolean, default true). When false, VAT is never charged on that company's invoices regardless of Item `vat_rate`.

## Data Model

- **`sales_invoices`** (scoped by `company_id`): `partner_id` (customer), `invoice_number`, `fiscal_year`, `invoice_date`, `due_date`, `status` (`draft` / `confirmed` / `cancelled`), `warehouse_id` (nullable — required at confirm-time only if any line references a stock-tracked Item), `sent_at` (nullable), `notes`, `created_by`. Numbering follows `JournalEntry`'s exact pattern: assigned on creation via `lockForUpdate()` + `DB::transaction()`, sequential per `company_id` + `fiscal_year`.
- **`sales_invoice_lines`**: belongs to `sales_invoices`; `item_id` (nullable FK to `items`) and/or free-text `description` — at least one required; `quantity` (required when `item_id` is set); `unit_price`; `vat_rate` (defaults from the Item, or entered directly for free-text lines); `line_total` (computed, stored).
- **`sales_invoice_payments`**: belongs to `sales_invoices`; `amount`, `payment_date`, `payment_method` (`bank` / `cash`), `created_by`.
- **`companies`** gains `bank_account` (string, nullable) and `is_vat_registered` (boolean, default true), editable only by Admin on the existing company edit screen.

**Derived status** (not stored): `unpaid` / `partially_paid` / `paid` from summed payments vs. invoice total, plus a display-only `overdue` flag (unpaid or partial, past `due_date`) layered over the stored `draft`/`confirmed`/`cancelled` document status.

**Editability rule:** only `draft` invoices can have lines added, edited, or removed. A `confirmed` invoice is immutable except for two actions: recording a payment, or cancelling (only permitted if it has zero payments recorded — once money has moved, cancellation is blocked; a credit-note workflow for confirmed-and-paid invoices is out of scope for this phase).

## GL Posting & Inventory Integration

All of the following happens inside one `DB::transaction()` when an invoice is confirmed, mirroring the atomicity already used by `JournalEntryForm::save()`:

1. Assign `invoice_number` (lock-and-increment, as above).
2. For each Item-linked line, call `StockMovementService::issue()` against the invoice's `warehouse_id` — this moves the stock and returns the weighted-average unit cost used, which becomes that line's COGS contribution.
3. Build one balanced `JournalEntry` (reusing the Phase 1 model as-is, no new ledger machinery):
   - **Debit** `120` (Побарувања од купувачи — AR) — invoice grand total incl. VAT
   - **Credit** `740` (Приходи од продажба... во земјата — domestic sales revenue; used for both goods and service lines, one default account rather than splitting by line type) — net amount
   - **Credit** `230` (Обврски за ДДВ — VAT payable) — total VAT, only if `company.is_vat_registered`
   - **Debit** `701` (Набавна вредност на продадени добра — COGS) / **Credit** `660` (Стоки на залиха — inventory asset) — only present if the invoice has Item lines, for the summed cost from step 2
4. Set `status = confirmed`.

These account codes are resolved by fixed convention (looked up by `code` within the company's already-seeded 428-account chart from Phase 1) — no separate account-mapping table in this phase.

**Insufficient stock:** if any line's `StockMovementService::issue()` call would fail, the whole transaction rolls back — invoice stays `draft`, nothing posts, and the UI reports the error against the specific line.

**Cancelling** a confirmed, unpaid invoice reverses both sides rather than deleting: a reversing `JournalEntry` (debits/credits swapped) and stock returned via `StockMovementService::receipt()` at the original issued cost. Nothing is ever deleted — full audit trail, consistent with the rest of the ledger.

**Recording a payment** posts its own immediate GL entry: **Debit** `100` (bank) or `102` (cash till) depending on `payment_method` — **Credit** `120` (AR) — for the payment amount. Payment amount is validated against the remaining unpaid balance (can't overpay).

## Numbering, PDF & Access Control

- **Numbering:** as above — reuses `JournalEntry`'s auto-increment pattern exactly.
- **PDF:** adds `barryvdh/laravel-dompdf` (pure-PHP, HTML/CSS rendering — no headless-browser binary needed, fits the $6/mo droplet). A Blade view renders a standard Macedonian Фактура layout: issuer header (name, tax ID, address, bank IBAN), customer (Partner) details, invoice number/dates, line-item table, VAT/subtotal/total summary. Downloadable from the invoice view. A separate "Mark as sent" action sets `sent_at`.
- **Access control:** new `SalesInvoicePolicy` and `SalesInvoicePaymentPolicy` giving Admin/Accountant/Client full read-write on their own company's data, following the exact `visibleCompanies()`-scoped pattern every existing policy uses (matches `WarehousePolicy`/`ItemPolicy`, not `JournalEntryPolicy`).

## Screens

- **Sales Invoice list:** per company, filterable by status (draft/confirmed/cancelled) and paid/unpaid/overdue.
- **Sales Invoice form:** create/edit a draft — partner, dates, warehouse (if needed), dynamic line items (Item picker or free-text), live VAT/total calculation.
- **Sales Invoice show:** confirmed-invoice view — line detail, GL entry reference, payment history, "Record payment," "Download PDF," "Mark as sent," "Cancel" (when eligible).
- Nav link and companies-list links added following the exact pattern Phase 2 used for Inventory links.

## Validation & Error Handling

- Confirming requires at least one line; free-text lines require a `vat_rate` if the company is VAT-registered.
- `Partner` and `Item` references must belong to the same `company_id` as the invoice — reuses the cross-tenancy guard pattern from `StockMovementService::assertSameCompany()`.
- All monetary/VAT arithmetic uses the same `bcmath`-based rounding approach as `StockMovementService::bcDivRoundHalfUp()` — no float drift.
- `warehouse_id` is required at confirm-time if any line references an Item.
- Payment amount can't exceed the remaining unpaid balance.
- Cancellation is blocked once any payment exists against the invoice.

## Testing

- GL posting correctness: confirming an invoice (with and without Item lines, with and without VAT) produces a balanced `JournalEntry` with exactly the expected debit/credit lines and amounts.
- COGS correctness: the weighted-average cost used for the COGS line matches `StockMovementService`'s own computed average at time of issue.
- Numbering: sequential per company per fiscal year, no gaps, correct under concurrent confirms (mirrors Phase 1's numbering test).
- Insufficient stock: confirming an invoice whose Item line exceeds on-hand quantity rolls back cleanly — no partial GL entry, no partial stock movement, invoice stays `draft`.
- Cancellation: reverses both the GL entry and the stock movement correctly; blocked once a payment exists.
- Payment correctness: partial and full payments correctly derive `unpaid`/`partially_paid`/`paid` status, each posts its own correct GL entry, overpayment is rejected.
- VAT-exempt companies: `is_vat_registered = false` produces invoices with no VAT line regardless of Item `vat_rate`.
- Multi-tenancy: a company only ever sees its own invoices, partners, and items; cross-company Partner/Item references on an invoice line are rejected.
- Role-based access: Clients can create/confirm/cancel/record payments within their own company only; cannot access another company's invoices.
- PDF generation: rendered PDF contains the expected company/customer/line/total data for a known fixture invoice.

## Out of Scope (this phase)

- Purchase (incoming) invoicing — Phase 3b.
- е-Фактура integration — Phase 8.
- Real email sending — PDF download/manual-share only; SMTP setup is a separate follow-up.
- Foreign-currency invoices — MKD only.
- Credit notes / partial cancellation of a paid invoice.
- Per-category or per-item GL account overrides — fixed default account codes only.
- Recurring/subscription invoices, quotes/estimates, or any document type other than the invoice itself.
