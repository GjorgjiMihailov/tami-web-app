# Phase 2: Inventory — Design

**Date:** 2026-07-19
**Status:** Approved

## Purpose

Build full warehouse-operations inventory management: items, per-client warehouses, stock movements (receipt, issue, transfer, adjustment), weighted-average costing, and the reports the firm needs to see current stock and value. This phase deliberately does **not** integrate with the accounting ledger built in Phase 1 — that integration is deferred to the Invoicing phase, where real-world stock movements will actually originate from purchase/sales documents.

## Scope Decisions (confirmed with user)

- **Full warehouse operations**, not a simple item list or accounting-only stock/COGS view — multiple warehouses per client, transfers, stock counts/adjustments, camera-based barcode scanning.
- **No ledger auto-posting in this phase.** Inventory tracks quantity and value on its own; journal-entry integration is explicitly deferred to the Invoicing phase.
- **Weighted-average costing**, recalculated per item **per warehouse** on every receipt/transfer-in. No FIFO/lot tracking, no standard/fixed cost.
- **Each client (company) defines their own warehouses** — no forced single default, consistent with `company_id` scoping used throughout the app.
- **Clients (the tenant role) have full read-write access** to Inventory for their own company — items, warehouses, and all four movement types — unlike Phase 1's accounting module where Clients are read-only. Admin/Accountant retain full read-write access across all companies.
- **Camera-based barcode scanning** (browser camera, not handheld scanner hardware) as an item-picker shortcut on movement screens, fitting the app's tablet/phone usability goal.

## Data Model

- **`warehouses`** (scoped by `company_id`): `name`, `is_active`.
- **`items`** (scoped by `company_id`): `code` (barcode/SKU, unique per company), `name`, `unit_of_measure` (free-text string — piece/kg/liter/box/etc., not a fixed enum), `category` (free-text grouping), `vat_rate` (default rate, editable per item, for future Invoicing pre-fill and VAT-aware reporting now), `preferred_partner_id` (nullable FK to `partners`), `is_active`.
- **`stock_movements`** (scoped by `company_id` via `item_id`/`warehouse_id`): `item_id`, `warehouse_id`, `type` (`receipt` / `issue` / `transfer` / `adjustment`), `quantity` (always stored positive; direction implied by `type`), `unit_cost` (entered on receipt; computed from the running average at time of issue/transfer-out; for adjustments, positive deltas use current average cost), `to_warehouse_id` (nullable, set only for `transfer`), `reason` (nullable text, required for `adjustment`), `movement_date`, `created_by`.
- **`stock_levels`**: one row per (`item_id`, `warehouse_id`) pair — `quantity_on_hand`, `average_cost`. This is a maintained cache, not derived on read: every `stock_movements` insert updates the corresponding `stock_levels` row(s) inside the same DB transaction, using a `lockForUpdate()` pattern (mirroring the journal-entry-numbering approach from Phase 1) so concurrent movements on the same item/warehouse can't corrupt the running average.

This mirrors Phase 1's architecture deliberately: one ledger-style table (`stock_movements`, like `journal_entry_lines`) with a `type`/dimension column driving reports, plus a maintained running-balance cache, rather than separate tables per movement type or expensive on-the-fly aggregation.

## Movement Logic

- **Receipt (stock in):** user selects item + warehouse, enters quantity and unit cost (pre-filled with the item's last-used cost at that warehouse, editable). New average cost for that (item, warehouse) is:
  `((old_qty × old_avg_cost) + (new_qty × new_unit_cost)) / (old_qty + new_qty)`
- **Issue (stock out):** user selects item + warehouse + quantity. `unit_cost` on the movement is filled automatically from the warehouse's current `average_cost` — not user-entered, so valuation stays internally consistent. Rejected if quantity exceeds `quantity_on_hand` (no negative stock).
- **Transfer:** user selects item, source warehouse, destination warehouse, quantity. Recorded as one `stock_movements` row (`warehouse_id` = source, `to_warehouse_id` = destination). Cost carries at the source's current average cost; the destination's average cost is recalculated as though it received stock at that cost. Rejected if quantity exceeds the source's on-hand quantity.
- **Adjustment:** user selects item + warehouse, enters a signed quantity delta and a required `reason`. A positive delta behaves like a receipt at the *current* average cost (doesn't distort valuation); a negative delta behaves like an issue.
- All four movement types are entered through one Livewire form component parameterized by `type`, posting into the single `stock_movements` table — the same "one form, multiple line/entry types" pattern as Phase 1's journal entry form.
- **Barcode scanning:** the item picker on every movement screen supports camera-based scanning (browser `getUserMedia` + a barcode-decoding JS library) as an alternative to typing/searching — a successful scan matches against `items.code`; manual search remains available as a fallback if the camera is unavailable, denied, or no match is found.

## Screens

- **Item catalog:** list/search/create/edit/activate-deactivate items, following the same pattern as the Phase 1 chart-of-accounts screen.
- **Warehouse management:** simple CRUD list per company.
- **Movement entry:** one form, parameterized by movement type (receipt/issue/transfer/adjustment), with barcode-scan item picker.
- **Stock on hand:** current quantity and value (`quantity_on_hand × average_cost`) per item, filterable/groupable by warehouse or totaled across all of a company's warehouses.
- **Item movement card:** per-item transaction history for a date range — every receipt/issue/transfer/adjustment, with running quantity and running average cost. Same shape as Phase 1's analytical ledger card, queried directly off `stock_movements`.
- **Stock valuation summary:** total inventory value at a point in time, optionally broken down by warehouse or category.

## Roles & Permissions

- **Admin/Accountant:** full read-write across all companies (items, warehouses, all movement types) — consistent with Phase 1.
- **Client:** full read-write scoped to their own company only, including managing their own items/warehouses and recording all four movement types. This is a deliberate departure from Phase 1, where Clients are read-only on accounting — Inventory is a module clients actively operate day to day (e.g. scanning stock in their own shop).
- Policies follow the existing `visibleCompanies()` / `company_id`-scoping pattern established in Phase 0/1.

## Testing

- Weighted-average cost calculation: sequences of receipts at varying unit costs produce the correct running average, per item per warehouse.
- Movement rejection: issues and transfers that would exceed `quantity_on_hand` are rejected; balanced/valid movements post and immediately update `stock_levels`.
- Transfer correctness: a transfer decrements the source and increments the destination in a single transaction, with the destination's average cost recalculated correctly.
- Adjustment correctness: positive and negative adjustments correctly update quantity without distorting `average_cost` incorrectly, and require a `reason`.
- Stock on hand / valuation / movement card reports: seeded test movements with known figures, asserting report output matches hand-calculated expected values.
- Multi-tenancy: a company only ever sees its own items, warehouses, and stock movements.
- Role-based access: Clients can create/edit within their own company; cannot access another company's inventory data.
- Barcode scan matching: a scanned code correctly resolves to the matching item, with graceful fallback to manual search when no match is found.

## Out of Scope (this phase)

- Ledger/journal-entry auto-posting from stock movements — deferred to the Invoicing phase, where movements will originate from purchase/sales documents that don't exist yet.
- Low-stock alerts / reorder suggestions.
- FIFO/lot or serial-number tracking — weighted average only.
- Purchase orders, sales orders, or any document-driven movement creation.
- Negative/backordered stock — issues and transfers are rejected outright if they'd exceed on-hand quantity, with no backorder or negative-stock allowance.
- Handheld/Bluetooth barcode scanner support — camera-based scanning only.
