# Phase 3b: Purchase Invoicing — Design

**Date:** 2026-07-21
**Status:** Approved

## Purpose

Build core incoming (purchase/AP) invoicing: record supplier bills, link them to Partners/Items/the Chart of Accounts, auto-post the GL on confirm, receive stock for stock-tracked lines, and record payments made. Mirrors Phase 3a's shape (draft → confirm → cancel, plus payments) but with debits/credits reversed and the specific differences a purchase bill actually needs — a per-line expense account picker (no single "purchases" account exists in the chart the way `740` does for sales revenue), supplier-provided numbering instead of an auto-sequence, and an attached source document instead of a generated PDF. Reuses Phase 3a's shared plumbing (line-item pattern, VAT calc, payment recording) rather than rebuilding it. е-Фактура integration stays deferred to Phase 8.

## Scope Decisions (confirmed with user)

- **Purchase invoices only** in this phase, extending Phase 3a. No changes to `SalesInvoice*` behavior, except the shared-trait refactor below (behavior-preserving).
- **Per-line expense account picker** for non-stock lines — the chart has ~30 distinct expense accounts across groups 40-45 (materials, external services, salaries-related, depreciation, other operating costs); unlike sales revenue there is no single default account that would produce an accurate P&L.
- **Attach the supplier's own source document** (PDF/photo) rather than generating one — the real bill already exists as a document. No PDF generation for purchase invoices.
- **Supplier invoice number + internal ID**, not a second auto-sequential numbering scheme — the legal sequential-numbering requirement only applies to invoices *you issue* (sales side), not ones you receive.
- **Per-line VAT-deductible flag** — Macedonian VAT law disallows deducting input VAT on some purchases (e.g. entertainment expenses, passenger vehicles); non-deductible VAT is folded into that line's own cost instead of being split to the input-VAT account.
- **Single "Confirm" action** posts GL and receives stock atomically, mirroring sales' confirm exactly (no separate "goods received" vs. "bill posted" states).
- **Clients get full read-write**, same policy shape as `SalesInvoicePolicy` — keeps the three invoicing-adjacent modules (Sales, Purchase, and their shared Partner/Item dependencies) consistent.
- **Shared money-math trait**: `SalesInvoice`'s `subtotal()`/`vatTotal()`/`grandTotal()`/`paidTotal()`/`balanceDue()`/`paymentStatus()` are byte-identical logic with zero divergent branching between sales and purchases, so they're extracted into a shared trait rather than duplicated (unlike Phase 2's `bcDivRoundHalfUp`, which was kept separate because it genuinely differed in rounding precision across modules). Everything else (schema, service, GL posting, policy, screens) stays fully separate — no shared state, no polymorphic table.

## Data Model

- **`purchase_invoices`** (scoped by `company_id`): `partner_id` (supplier), `warehouse_id` (nullable — required at confirm-time only if any line references a stock-tracked Item), `journal_entry_id` (nullable), `supplier_invoice_number` (string, required), `invoice_date` (the date on the supplier's bill), `due_date`, `status` (`draft` / `confirmed` / `cancelled`), `notes`, `source_document_path` (nullable string — path on the existing `google` Drive disk from Phase 0b), `created_by`. Unique constraint on `(company_id, partner_id, supplier_invoice_number)` to guard against double-booking the same bill. No `fiscal_year`/auto-`invoice_number` columns.
- **`purchase_invoice_lines`**: belongs to `purchase_invoices`; `item_id` (nullable FK to `items`); `account_id` (nullable FK to `accounts` — required when `item_id` is null, the expense-account picker; unused when `item_id` is set since stock lines always post to `660`); `stock_movement_id` (nullable); `description`; `quantity` (required when `item_id` is set); `unit_price` (the cost — used directly as `unit_cost` for `StockMovementService::receipt()`, no weighted-average lookup needed since this *is* the cost entering the system); `vat_rate`; `vat_deductible` (boolean, default `true`).
- **`purchase_invoice_payments`**: belongs to `purchase_invoices`; `amount`, `payment_date`, `payment_method` (`bank` / `cash`), `created_by` — identical shape to `sales_invoice_payments`.
- **Shared trait** (new, `App\Models\Concerns\HasInvoiceTotals`): the six methods listed above, extracted from `SalesInvoice` and used by both `SalesInvoice` and `PurchaseInvoice`. `SalesInvoice`'s existing 246 tests must still pass unchanged after this refactor.

**Editability rule:** same as sales — only `draft` invoices can have lines added/edited/removed; a `confirmed` invoice is immutable except for recording a payment or cancelling (blocked once any payment exists).

## GL Posting & Inventory Integration

All within one `DB::transaction()`, mirroring `SalesInvoiceService::confirm()`'s structure:

1. Guard: invoice is `draft`, has at least one line, and has a `warehouse_id` if any line references an Item.
2. For each line:
   - **Stock item line**: call `StockMovementService::receipt($item, $warehouse, $quantity, $unitPrice, $invoiceDate, $userId)` — the line's `unit_price` is passed directly as `unit_cost`. Debit accumulates to `660` (inventory asset) at `quantity × unit_price`.
   - **Non-stock line**: debit accumulates to that line's own `account_id`, at `quantity × unit_price`.
   - **VAT**: for lines where `vat_deductible` is true (and `company.is_vat_registered`), the line's VAT amount accumulates to a debit on `130` (input VAT). Non-deductible VAT is added into that line's own 660/expense-account debit instead — no separate account for it.
3. Build one balanced `JournalEntry`:
   - **Debit** `660` and/or the picked expense accounts (per-line, as above) — net cost
   - **Debit** `130` (Данок на додадена вредност — input VAT) — total deductible VAT, only if `company.is_vat_registered` and any line is deductible
   - **Credit** `220` (Обврски спрема добавувачи во земјата — AP) — full gross total
4. Set `status = confirmed`. No COGS lines (sales-only).

Label uses the supplier's own reference: `"Purchase bill {$partner->name} #{$supplierInvoiceNumber}"`.

**Cancelling** a confirmed, unpaid invoice reverses both sides: a reversing `JournalEntry` (debits/credits swapped) and stock returned via `StockMovementService::issue()` (the inverse of the original `receipt()`), consistent with `SalesInvoiceService::cancel()`'s pattern of reversal rather than deletion.

**Recording a payment** posts: **Debit** `220` (AP, reducing the payable) — **Credit** `100` (bank) or `102` (cash) depending on `payment_method` — for the payment amount. Validated against the remaining unpaid balance (`balanceDue()` from the shared trait).

Account codes are resolved by fixed convention (`130`, `220`, `660`, `100`, `102`) except the per-line expense account, which is user-selected — same "no mapping table" approach as sales.

## Source Document Attachment

A single nullable `source_document_path` column — not a new generic `Document` model or polymorphic attachments table, since that broader design belongs to Phase 4 (Documents & Reports) and shouldn't be preempted here. The invoice form gets one file-upload field; on save, the file streams to the existing `google` disk (configured since Phase 0b) under `purchase-invoices/{company_id}/{invoice_id}/{original filename}`. `PurchaseInvoiceShow` gets a "Download original" link. No preview or OCR this phase.

## Access Control

New `PurchaseInvoicePolicy`, identical shape to `SalesInvoicePolicy`: `viewAny` true for all authenticated users, `view` scoped by `visibleCompanies()`, `create`/`update` allow `admin`/`accountant`/`client`.

## Screens

- **Purchase Invoice list:** per company, filterable by status (draft/confirmed/cancelled) and paid/unpaid/overdue (via the shared trait's `paymentStatus()`).
- **Purchase Invoice form:** create/edit a draft — supplier (Partner) picker, supplier invoice number/date, warehouse (if needed), file upload, dynamic line items (Item picker with implicit `660` posting, or account picker with description for expense lines), per-line VAT-deductible toggle, live VAT/total calculation.
- **Purchase Invoice show:** confirmed-invoice view — line detail, GL entry reference, payment history, "Download original," "Record payment," "Cancel" (when eligible).
- Nav link and companies-list links added following the same pattern Phase 2/3a used.

## Validation & Error Handling

- Confirming requires at least one line; non-stock lines require an `account_id`; stock lines require `quantity` and a `warehouse_id` on the invoice.
- `Partner`, `Item`, and `Account` references must belong to the same `company_id` as the invoice — reuses the cross-tenancy guard pattern from `StockMovementService::assertSameCompany()`.
- All monetary/VAT arithmetic uses the same `bcmath`-based rounding approach as the rest of the codebase (`App\Support\Bcmath::roundHalfUp()`).
- `supplier_invoice_number` must be unique per `(company_id, partner_id)`.
- Payment amount can't exceed the remaining unpaid balance.
- Cancellation is blocked once any payment exists against the invoice.

## Testing

- GL posting correctness: confirming a purchase invoice (with stock lines, expense lines, mixed, with/without VAT, with/without non-deductible VAT) produces a balanced `JournalEntry` with exactly the expected debit/credit lines and amounts.
- Stock receipt correctness: `receipt()` is called with the line's `unit_price` as `unit_cost`, and `stock_levels`/weighted-average cost update accordingly.
- Cancellation: reverses both the GL entry and the stock movement (via `issue()`) correctly; blocked once a payment exists.
- Payment correctness: partial and full payments correctly derive `unpaid`/`partially_paid`/`paid` status via the shared trait, each posts its own correct GL entry, overpayment is rejected.
- Non-deductible VAT: a line with `vat_deductible = false` folds its VAT into the expense/inventory debit instead of posting to `130`.
- Duplicate bill guard: booking the same `(company_id, partner_id, supplier_invoice_number)` twice is rejected.
- Multi-tenancy: cross-company Partner/Item/Account references on an invoice line are rejected.
- Role-based access: Clients can create/confirm/cancel/record payments within their own company only; cannot access another company's invoices.
- File attachment: uploading a source document stores it at the expected Drive path and the download link resolves it.
- Shared trait refactor: full existing `SalesInvoice` test suite (246 tests) still passes unchanged after `HasInvoiceTotals` extraction.

## Out of Scope (this phase)

- е-Фактура integration — Phase 8.
- Generated PDF for purchase invoices — source document attachment only.
- Purchase orders / goods-receipt-before-billing workflows — single-step confirm only.
- Foreign-currency purchase invoices — MKD only, matching Phase 3a.
- Credit notes / partial cancellation of a paid invoice.
- OCR or auto-extraction of the attached source document — Phase 7's job.
- A general-purpose Documents/attachments subsystem — Phase 4's job; this phase adds only a single nullable path column scoped to purchase invoices.
