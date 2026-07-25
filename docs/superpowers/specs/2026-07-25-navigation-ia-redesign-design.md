# Navigation/IA Redesign — Design (IN PROGRESS, NOT YET APPROVED)

> **Status: brainstorming in progress, paused mid-session at the user's request to continue in a fresh conversation.** This is NOT a finalized spec — do not proceed to writing-plans from this document as-is. Resume the `superpowers:brainstorming` flow: confirm the "Architecture" section below (last thing presented, not yet explicitly approved), then continue presenting remaining sections, then follow the normal spec-approval flow before writing an implementation plan.

## Context

The visual redesign (colors/fonts/cards/badges/sidebar shell — see
`docs/superpowers/specs/2026-07-22-visual-redesign-design.md`, complete and
live) was explicitly scoped as presentation-only. It did not touch the
underlying navigation structure. After it shipped, the user reported real
usability problems that are IA (information architecture) issues, not
styling issues:

- The Dashboard is still Laravel's original empty placeholder ("You're
  logged in!") — never given real content by any phase.
- After login, the sidebar shows only "Dashboard" and "Companies" — no
  module links — because the sidebar (built in the visual redesign) only
  renders company-scoped module links when the current route already has a
  `{company}` parameter, i.e. only once you've drilled into a specific
  company via the Companies page.
- The Companies page (`company-index.blade.php`) still dumps every
  module's links in one flat list per company (unchanged since Phase 0a,
  just recolored) — "Accounting: Accounts Journal Ledger Card Trial
  Balance", "Inventory: Warehouses Items ...", etc. — with no submenus,
  tabs, or real structure.

This is a follow-on fix to the same "modernize the site" cross-cutting
effort the user originally requested — not a new roadmap phase (the phase
roadmap remains on hold per the user's earlier instruction).

## Decisions confirmed so far (via Q&A, all answered)

1. **Company-picker placement**: a modal popup on top of the Dashboard page
   (not a full-page replacement of Dashboard, and not a separate step
   before Dashboard even loads). Dashboard stays a distinct page/concept.
2. **Popup trigger**: shows every time the Dashboard route is visited —
   which includes every fresh login (Breeze redirects to `/dashboard` after
   login), AND any later manual visit to Dashboard (e.g. clicking
   "Dashboard" in the sidebar while already working in a company). This
   was deliberately chosen (see Architecture section below) so the same
   mechanism doubles as a company-switcher with no extra session-state
   machinery — clicking Dashboard = "let me pick a different company."
3. **Dashboard content**: kept minimal for now, per explicit user
   correction after initially selecting a richer set — just confirmation of
   which company is active (e.g. "Working on: [Company Name]") plus
   quick-access shortcut cards into that company's modules. At-a-glance
   numbers (receivables/payables/stock value) and a recent-activity feed
   were explicitly deferred: *"keep it minimal for now. later we will add
   something useful and reorganize the dashboard."* Do not build those in
   this pass.
4. **Sidebar submenus**: each module (Accounting, Inventory, Invoicing,
   Documents, Reports) should expand in place within the sidebar to show
   its sub-pages as indented links, replacing the flat link-list currently
   on the Companies page — not a separate tab-bar-per-module-landing-page
   approach.

## Architecture (presented, NOT yet explicitly confirmed by the user — confirm this first when resuming)

This app has no existing session concept of "current company" — every page
is scoped purely by a `{company}` URL route parameter (e.g.
`/companies/5/accounting/accounts`), and the existing `Sidebar` Livewire
component (built in the visual redesign, `app/Livewire/Layout/Sidebar.php`)
already derives its company-scoped links this way via
`request()->route('company')`.

Proposed approach: rather than introduce new session state for "current
company," add one new route — `companies/{company}/dashboard` — which
becomes the real "home base" for a company. Picking a company in the popup
redirects the browser there. Because the Sidebar component already reads
the current route's company parameter, landing on this new route
automatically makes the sidebar show that company's expanded modules with
zero new plumbing. The existing generic `/dashboard` route (no company
param) stays as the route that always shows the popup (per decision #2
above) — clicking "Dashboard" in the sidebar always sends you back to the
route with no company context, which re-triggers the picker.

**This was presented to the user but the conversation paused before they
confirmed it.** Re-confirm before proceeding.

## Open / not yet discussed when the session paused

- **Exact sidebar submenu contents per module** — not yet presented to the
  user. Known routes to map (from `routes/web.php`, current as of the
  visual redesign):
  - **Accounting** (`accounting.*`): Accounts (`accounting.accounts.index`),
    Journal (`accounting.journal-entries.index`), Ledger Card
    (`accounting.reports.ledger-card`), Trial Balance
    (`accounting.reports.trial-balance`).
  - **Inventory** (`inventory.*`): Warehouses
    (`inventory.warehouses.index`), Items (`inventory.items.index`), Stock
    On Hand (`inventory.reports.stock-on-hand`), Item Movement Card
    (`inventory.reports.item-movement-card`), Stock Valuation
    (`inventory.reports.stock-valuation`) — plus 4 "record movement" create
    forms (Receipt/Issue/Transfer/Adjustment, all
    `inventory.stock-movements.create` with a `{type}` param) whose place
    in the submenu structure (siblings vs. a nested sub-group) hasn't been
    decided yet.
  - **Invoicing** ("Фактури" in the current sidebar, groups 3 route
    prefixes): Partners (`partners.index`), Sales Invoices
    (`sales-invoices.index`), New Invoice (`sales-invoices.create`),
    Purchase Invoices (`purchase-invoices.index`), New Purchase Invoice
    (`purchase-invoices.create`).
  - **Documents**: just `documents.index` — likely no submenu needed, stays
    a single link.
  - **Reports** ("Извештаи"): just `reports.ddv04` today — likely no
    submenu needed yet, though this will grow when deferred work
    (reverse-charge ДДВ-04 fields, year-end filings) eventually lands.
- **Expand behavior**: accordion-style (only one module's submenu open at a
  time) vs. multiple simultaneously expandable — not yet asked. Leaning
  toward auto-expanding whichever module matches the current route (reusing
  the existing active-highlight logic already in `Sidebar`), collapsed
  otherwise, and letting a click expand/collapse — but the
  one-at-a-time-vs-many question specifically hasn't been asked yet.
- **Company-index page's future role**: since the popup + sidebar now
  handle "get me into a company's modules," `company-index.blade.php`
  probably sheds its giant per-company link list and shrinks down to just
  company management (the existing "Add company" form + "Edit settings"
  action per company already there) — not yet proposed to the user.
- Whether the popup should be dismissable/skippable (e.g. an "X" or click-
  outside-to-close, landing you on a company-less Dashboard state) or
  mandatory (must pick a company to proceed) — not yet discussed.
- Whether users with only one accessible company should still see the full
  popup every time, or get some lighter-weight treatment — the "every
  login" decision (#2 above) as stated doesn't distinguish this, so as
  currently decided, yes, even a single-company user sees the popup and
  picks (clicks) their one company. Worth a quick re-confirmation since
  earlier questioning around this was ambiguous.

## Next steps when resuming

1. Re-confirm the Architecture section above with the user.
2. Ask about the remaining open items listed above, one at a time.
3. Present the sidebar's exact submenu structure as a design section (a
   visual mockup here would likely help — the visual-companion browser tool
   was not used in this brainstorm yet).
4. Present the Companies page's reduced future role.
5. Once all sections are approved, write the final spec (replacing this
   in-progress document, or updating it in place and removing the "IN
   PROGRESS" banner), run the self-review checklist, get the user's
   sign-off on the written spec file, then invoke `superpowers:writing-plans`.
