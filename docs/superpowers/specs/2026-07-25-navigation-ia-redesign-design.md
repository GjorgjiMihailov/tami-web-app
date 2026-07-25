# Navigation/IA Redesign — Design

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

## Decisions

1. **Company-picker placement**: a modal popup on top of the Dashboard page
   (not a full-page replacement of Dashboard, and not a separate step
   before Dashboard even loads). Dashboard stays a distinct page/concept.
2. **Popup trigger**: shows every time the Dashboard route is visited —
   which includes every fresh login (Breeze redirects to `/dashboard` after
   login), AND any later manual visit to Dashboard (e.g. clicking
   "Dashboard" in the sidebar while already working in a company). This
   doubles as a company-switcher with no extra session-state machinery —
   clicking Dashboard = "let me pick a different company."
3. **Single-company users**: still see the full popup every time, same as
   everyone else — no shortcut/auto-skip just because there's only one
   company to pick. Confirmed for consistency.
4. **Popup dismissal**: mandatory. No close button and no click-outside-to-
   dismiss — you must pick a company to proceed. There is no supported
   "logged in, no company chosen" state, so nothing needs to be designed
   for it.
5. **Dashboard content**: kept minimal for now, per explicit user
   correction after initially selecting a richer set — just confirmation of
   which company is active (e.g. "Working on: [Company Name]") plus
   quick-access shortcut cards into that company's modules. At-a-glance
   numbers (receivables/payables/stock value) and a recent-activity feed
   were explicitly deferred: *"keep it minimal for now. later we will add
   something useful and reorganize the dashboard."* Do not build those in
   this pass.
6. **Sidebar submenus**: each module (Accounting, Inventory, Invoicing,
   Documents, Reports) expands in place within the sidebar to show its
   sub-pages as indented links, replacing the flat link-list currently on
   the Companies page.
7. **Sidebar expand behavior**: accordion-style — only one module's
   submenu is open at a time. Whichever module matches the current route
   auto-expands (reusing the existing active-highlight logic already in
   `Sidebar`); everything else stays collapsed. Clicking a different
   module's name collapses the currently-open one and expands the clicked
   one.
8. **Companies page's future role**: since the popup + sidebar now handle
   "get me into a company's modules," `company-index.blade.php` sheds its
   giant per-company link list and shrinks down to just company
   management — the existing "Add company" form and "Edit settings" action
   per company, nothing else.

## Architecture

This app has no existing session concept of "current company" — every page
is scoped purely by a `{company}` URL route parameter (e.g.
`/companies/5/accounting/accounts`), and the existing `Sidebar` Livewire
component (built in the visual redesign, `app/Livewire/Layout/Sidebar.php`)
already derives its company-scoped links this way via
`request()->route('company')`.

Rather than introduce new session state for "current company," add one new
route — `companies/{company}/dashboard` — which becomes the real "home
base" for a company. Picking a company in the popup redirects the browser
there. Because the Sidebar component already reads the current route's
company parameter, landing on this new route automatically makes the
sidebar show that company's expanded modules with zero new plumbing. The
existing generic `/dashboard` route (no company param) stays as the route
that always shows the popup — clicking "Dashboard" in the sidebar always
sends you back to the route with no company context, which re-triggers the
picker.

## Sidebar submenu structure

- **Accounting**: Accounts, Journal, Ledger Card, Trial Balance.
- **Inventory**: Warehouses, Items, Stock On Hand, Item Movement Card,
  Stock Valuation, plus a nested "Record Movement" sub-group that expands
  further into Receipt, Issue, Transfer, Adjustment.
- **Invoicing** (Фактури): Partners, Sales Invoices, New Invoice, Purchase
  Invoices, New Purchase Invoice.
- **Documents**: single link, no submenu (only one page exists today).
- **Reports** (Извештаи): single link, no submenu for now (will grow when
  deferred ДДВ-04 reverse-charge and year-end filing work lands).

## Out of scope

- Dashboard's richer content (at-a-glance numbers, recent-activity feed) —
  explicitly deferred to a later pass.
- Any new roadmap phase work — this is a standalone IA fix within the
  existing "modernize the site" effort, not a phase.
- A "logged in, no company selected" state — the popup is mandatory, so
  this state never occurs.

## Testing

- Fresh login redirects to `/dashboard` and shows the mandatory company
  picker; picking a company redirects to `companies/{company}/dashboard`
  and the sidebar shows that company's modules, collapsed except the one
  matching the current route.
- Clicking "Dashboard" in the sidebar from within a company always returns
  to `/dashboard` and re-shows the picker (company-switcher behavior).
- Users with only one company still see the popup and must click it to
  proceed.
- Expanding a module in the sidebar collapses any other open module
  (accordion behavior); clicking Inventory's "Record Movement" expands its
  4 sub-links.
- Companies page no longer shows per-company module link lists — only
  company management actions (add/edit settings).
