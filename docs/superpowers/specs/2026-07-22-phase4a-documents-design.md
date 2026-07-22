# Phase 4a: Documents Subsystem — Design

## Context

Phase 3b (Purchase Invoicing) added a single `source_document_path` column on
`purchase_invoices` as a deliberately scoped shortcut — one file, one entity
type, no reuse. This phase generalizes that into a proper document/attachment
subsystem usable across the app, and migrates the Phase 3b data into it.

Phase 4 was split into 4a (this doc, the subsystem) and 4b (statutory
reports — ДДВ-04 and year-end filings), mirroring the 0a/0b and 3a/3b
precedent: the two halves are independent, and reports don't need the
document subsystem to exist first.

## Attachable entities (this phase)

- `PurchaseInvoice` (migrating its existing `source_document_path` data)
- `SalesInvoice`
- `JournalEntry`
- `Partner`

## Data model

One new `documents` table:

| Column               | Notes                                                          |
|----------------------|-----------------------------------------------------------------|
| `id`                 |                                                                 |
| `company_id`         | tenancy scope, mirrors every other module                      |
| `documentable_type`  | polymorphic — `PurchaseInvoice`, `SalesInvoice`, `JournalEntry`, or `Partner` |
| `documentable_id`    | polymorphic                                                     |
| `category`           | enum: Invoice, Contract, Bank Statement, Receipt, ID/Registration, Other |
| `note`               | nullable text                                                   |
| `path`               | storage path on the `google` disk                               |
| `original_filename`  |                                                                 |
| `mime_type`          |                                                                 |
| `size`               | bytes                                                           |
| `uploaded_by`        | user id                                                         |
| `deleted_at`         | soft delete                                                     |

`Document` model with a `documentable()` morphTo relation. Each of the four
entity models gains a `documents()` morphMany relation.

Multiple documents may attach to a single record (e.g. a purchase invoice can
have the supplier's scan, a delivery note, and a payment confirmation all
attached at once).

## Permissions

No new `DocumentPolicy`. Permissions inherit the parent record's existing
policy: viewing a document's parent record via `Gate::authorize('view', ...)`
grants viewing its documents; `update` on the parent grants upload/delete on
its documents. This reuses `PurchaseInvoicePolicy`, `SalesInvoicePolicy`,
`JournalEntryPolicy`, and `PartnerPolicy` exactly as they exist today — no
new capability is introduced beyond what each policy already grants (e.g.
clients keep full read-write on invoices/partners, stay locked out of
journal-entry documents, matching current module-level access).

## Storage

Files live on the existing `google` disk (Google Drive via OAuth, from Phase
0b), under:

```
documents/{company_id}/{documentable_type}/{documentable_id}/{document_id}_{original_filename}
```

Including `document_id` in the path avoids collisions when two uploads share
a filename. Upload limit: 25MB, any file type (raised from Phase 3b's 10MB
to comfortably cover larger multi-page bank statement scans).

Deletion is soft: the `documents` row is soft-deleted and hidden from the
UI, but the Drive file itself is not removed and the row is recoverable.
This matches accounting audit-trail norms — a document's removal may later
be questioned, and nothing should be unrecoverably gone by mistake.

## Downloads

A single generic `DocumentController` replaces `PurchaseInvoiceDocumentController`,
authorizing against the document's parent record before streaming the file
from the `google` disk.

## Migrating Phase 3b data

One-time data migration: every non-null `purchase_invoices.source_document_path`
becomes a `Document` row (category "Invoice", `documentable` = that purchase
invoice, `uploaded_by` = the invoice's `created_by` as the closest available
approximation), after which the `source_document_path` column is dropped.
`PurchaseInvoiceForm`'s upload field and `PurchaseInvoiceDocumentController`
are removed in favor of the shared components below.

## UI

- **`DocumentManager` Livewire component** — a reusable upload + list block
  (upload button, list of existing docs showing category/note/uploader/date,
  download and soft-delete actions), embedded via
  `<livewire:document-manager :documentable="$record" />` on the four
  entity Show/detail pages.
- **`DocumentIndex` page** — one per company (linked from the companies list
  alongside the other module indexes), listing all documents across every
  entity type for that company. Filterable by category, entity type, and
  date range; each row links back to its parent record. Visibility is scoped
  per entity type's own existing rules (an admin/accountant sees everything
  for their assigned companies; a client sees only documents whose parent
  record they could already view) — not a blanket "can see all documents"
  permission.

## Testing

- Feature tests per entity type: upload, list, download, soft-delete, and
  policy enforcement (mirroring `PurchaseInvoicePoliciesTest`'s cross-cutting
  style — both denial and assigned-accountant/client access).
- `DocumentIndexTest` covering the cross-entity browser's filtering and
  per-entity-type visibility scoping.
- A migration test confirming existing `source_document_path` data survives
  the move to `documents` rows correctly.
