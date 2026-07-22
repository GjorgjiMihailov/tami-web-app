# Visual Redesign — Design

## Context

The phase roadmap (Phase 4c and beyond) is on hold while the app gets a
visual refresh. This is one of two independent cross-cutting efforts the
user requested; the other — Macedonian localization — is deliberately
scoped as its own separate follow-on effort (its own brainstorm, spec, and
plan) so the two kinds of changes (visual vs. text) aren't mixed in the
same diffs. This document covers only the visual redesign.

The app currently uses default Laravel Breeze/Livewire starter-kit
styling: a single top nav bar (Dashboard / Companies only — every real
module is reached by drilling into a company first), indigo used
inconsistently as the accent color instead of the firm's brand orange, and
ad-hoc inline Tailwind classes repeated across views instead of shared
components for cards and status badges.

## Scope

The whole app: every existing view across Phases 0–4b (54 Blade files).
The PDF invoice template (`resources/views/pdf/sales-invoice.blade.php`)
gets brand colors too, but is handled separately since dompdf doesn't
render through the same Vite/Tailwind pipeline as the rest of the app.

## Layout & navigation

Replace the current top-bar-only nav with a persistent left sidebar:

- Company name/switcher pinned at the top.
- Module links below it — Сметководство (Accounting), Магацин (Inventory),
  Фактури (Invoicing), Документи (Documents), Извештаи (Reports) — scoped
  to whichever company is currently selected (read from the current
  route's `{company}` parameter), mirroring today's structure but always
  visible instead of nested behind a "Companies" page.
- A slim top bar remains, holding only the user menu (profile/logout).
- On mobile, the sidebar collapses behind a hamburger toggle, matching the
  responsive pattern the current top nav already uses.

This replaces `resources/views/livewire/layout/navigation.blade.php` with
a new sidebar Livewire component.

## Typography & color system

**Font:** Manrope (weights 400/500/600/700), loaded via bunny.net the same
way Figtree is loaded today (`layouts/app.blade.php`'s `<link>` tags) —
chosen over Inter/Plus Jakarta Sans/Golos Text for solid Cyrillic coverage
(needed for the upcoming localization effort) combined with a more
distinctive, modern feel than the current default.

**Colors**, registered in `tailwind.config.js`'s existing `colors.brand`
block (already has `#ff6600`/`#ff8533`/`#cc5200` from Phase 0a) plus new
tokens:

| Role | Color |
|---|---|
| Primary action / accent / active nav / links | `#ff6600` (existing `brand.DEFAULT`) |
| Sidebar background | `#1f2937` (Tailwind gray-800 — dark gray, not near-black) |
| Page background | `#f9fafb` / `#f3f4f6` (light gray) |
| Card background | white |
| Primary text | near-black (`#111827`) |
| Status: confirmed/paid/success | muted green (`bg-green-100 text-green-800`) |
| Status: draft/pending | muted amber (`bg-amber-100 text-amber-800`) |
| Status: overdue/cancelled/error | muted red (`bg-red-100 text-red-800`) |

Semantic status colors are used *only* for status badges — everything
else (buttons, links, active states, borders) stays within the brand
palette. This replaces the current inconsistent use of Tailwind's default
`indigo-600` as the de facto accent color throughout the app.

## Component style

Soft & rounded: `rounded-2xl` cards with a subtle soft shadow and no
border (replacing the current `bg-white shadow rounded-md` flat-box
pattern), pill-shaped buttons and status badges (`rounded-full`), generous
padding. Tables keep clean row dividers with a light-gray header row
rather than heavy borders. Primary buttons are solid brand orange with
white text; secondary actions are ghost/outline style in gray.

## Architecture

Most interactive elements already funnel through a small set of shared
Blade components — `<x-primary-button>`, `<x-secondary-button>`,
`<x-danger-button>`, `<x-text-input>`, `<x-input-label>`, `<x-dropdown>`,
`<x-modal>`, `<x-nav-link>`, `<x-responsive-nav-link>` (all in
`resources/views/components/`). Restyling these ~10 files (new colors,
font, rounded/pill shapes) cascades the new look to every view that uses
them without touching those views directly.

Two new shared components fill the gaps views currently build inline and
inconsistently:

- **`<x-card>`** — replaces the repeated `<div class="bg-white shadow
  rounded-md p-4">` wrapper pattern seen across nearly every index/show
  view.
- **`<x-badge :status="$status" />`** — centralizes the status-to-color
  mapping (confirmed/draft/cancelled/overdue for invoices, etc.) instead
  of each view coloring it ad hoc inline.

Applying `<x-card>`/`<x-badge>` across the views that currently build
these patterns inline is the main mechanical part of the work — a
find-and-replace of the wrapper markup, not a rewrite of each view's
logic.

`tailwind.config.js` gains the new color tokens and Manrope in
`fontFamily.sans`; `layouts/app.blade.php` and `layouts/guest.blade.php`
get the new font `<link>` and the new sidebar-aware page shell.

## Out of scope

- Macedonian localization (separate effort, separate spec/plan).
- Dark mode (not requested).
- Any change to business logic, routes, permissions, or data — this is a
  presentation-layer-only change.

## Testing

Existing feature tests assert on visible text (`assertSee`) and Livewire
component behavior, not on CSS classes or exact markup structure, so this
redesign should not require test changes beyond the sidebar navigation
component itself (which replaces `layout/navigation.blade.php` and needs
its own test covering: correct module links render for the current
company context, and it correctly reads `{company}` from the route). A
final manual pass in a real browser (as required for any frontend change)
confirms the visual result across desktop and mobile widths.
