# UI/UX Pro-Max Redesign — Design

**Date:** 2026-08-08
**Status:** Approved by user, ready for planning
**Related:** [[tami_web_app_project]] memory file — Phase 8 (е-Фактура) is complete; this is a cross-cutting visual/UX pass that happens *before* Phase 4c/5 (payroll), not a numbered roadmap phase.

## Motivation

No specific user complaints or usability bugs are driving this. The site (portal.financebuddy.mk) already went through one visual/IA redesign pass earlier in the project. This pass exists purely to raise the *perceived* quality — make the app feel more expensive/professional/trustworthy before it's shown to new clients — using the `ui-ux-pro-max` plugin's style/color/typography database to make deliberate, evidence-based choices instead of ad-hoc tweaks.

## Scope boundary (hard constraint)

**In scope:**
- All Blade/Livewire views across all 3 roles (Admin, Accountant, Client) — ~40 screens.
- All shared Blade components (`resources/views/components/*`).
- The app shell (`layouts/app.blade.php`, `layout.sidebar`, `layout.navigation`).
- The **visual layout only** of PDF reports rendered via dompdf (Sales Invoice, ДДВ-04, Trial Balance, Analytical Cards, Stock Report) — HTML/CSS structure, spacing, typography, using the existing `<table>`-based technique (dompdf 3.1.6 has zero flex/grid support — this is a known, already-hit gotcha, see memory).

**Never touched, under any circumstance:**
- Controllers, Livewire component PHP logic, calculations, validations.
- The е-Фактура JSON/JWS document format or any field it sends to UJP.
- The content, structure, or required fields of any legal/statutory document (ДДВ-04, е-Фактура, year-end filings) — only how that same content is *styled* on the page/PDF.
- Any data or database change.

If a UI change would require touching business logic (e.g., a table needs a new computed column to look right), that's out of scope — flag it instead of doing it.

## Approach: hybrid tokens-first with a showcase screen

Rejected pure screen-by-screen (40 screens invite visual drift, expensive to fix a shared pattern later) and pure tokens-first-with-no-visible-checkpoint (hard for a non-technical stakeholder to approve an abstract token set with nothing rendered). Chosen hybrid:

1. Build the token layer + update shared components (invisible until applied).
2. Apply to **one showcase screen (Dashboard)**, get real sign-off on the rendered result.
3. Roll out the now-approved components mechanically across the remaining screens + PDF reports.

This works specifically because the app already has a shared Blade component library (`card`, `badge`, buttons, inputs, `dropdown`, `modal`) used across nearly all 40 screens — the infrastructure a tokens-first approach needs already exists; this isn't introducing a new pattern.

## Visual direction (chosen via visual companion, option "В — Топол модерен SaaS")

Warm, rounded, soft-shadow modern SaaS aesthetic — white surfaces on a warm neutral background (not the current cold `bg-gray-50`), orange brand color used generously in accents/gradients/CTAs, rounded cards, everything still clean (not decorative/cluttered).

**Deliberate deviations from the literal mockup**, made for a tool used for hours at a time, not a marketing page:
- **Body/label text stays neutral gray/near-black**, not warm brown — the mockup's warm-brown text was a small-scale stylization choice; at full scale, legibility for hours-long accounting work wins over color-consistency purism.
- **Semantic status colors (success/warning/danger/info) are decoupled from the brand orange** — warning uses amber/yellow, not orange, so "this needs attention" never gets visually confused with "this is a brand-colored button/accent."

## Design tokens (`tailwind.config.js` + `resources/css/app.css`)

- **Color:** keep `brand.DEFAULT #ff6600` / `light #ff8533` / `dark #cc5200`. Add a warm neutral background scale (replacing bare `gray-50` for the app shell/content background). Add named semantic tokens `success`, `warning`, `danger`, `info` (each with a base + light/bg variant for badges) — these replace the 25 files' worth of ad-hoc `text-green-600`/`bg-red-100`-style raw Tailwind color classes found during review, giving every "this invoice is overdue" / "this partner is inactive" indicator one consistent meaning and look everywhere.
- **Radius:** a small scale (e.g. `sm`/`md`/`lg`) — larger radius for cards/panels, tighter for buttons/inputs/badges, consistently applied instead of per-screen guesses.
- **Shadow:** one subtle neutral resting shadow, one slightly warm-tinted emphasis shadow for hover/highlighted cards — not applied to every element (avoids visual noise across 40 screens).
- **Spacing/density:** a "compact" density variant (tighter row height/padding) specifically for data-table-heavy screens (Фактури, Ставки, Аналитички картици, Биланс). Non-table layouts (Dashboard cards, forms) keep normal, more generous spacing — density is a table-context rule, not a global one.
- **Typography:** keep Manrope (already loaded via Bunny Fonts, Cyrillic-capable) — formalize a heading/subheading/body/caption scale to replace per-screen ad-hoc `text-lg`/`text-sm` choices. No font change.
- **Icons:** keep the existing inline-SVG approach (already emoji-free, matches best practice) — just standardize size/stroke/color across screens. No new icon package dependency.

## New shared component

`x-stat-card` — a KPI-style card (big number + small label + optional trend/status color), built on top of the existing `card` component. Needed because Dashboard and likely the Reports screens repeat this pattern with no shared component for it today.

## Components to update (Chapter/Step 2 of rollout)

`card`, `badge`, `primary-button`, `secondary-button`, `danger-button`, `text-input`, `input-label`, `input-error`, `modal`, `dropdown`, `dropdown-link`, `nav-link`, `responsive-nav-link`, `layouts/app.blade.php`, `layout.sidebar`, `layout.navigation`, plus the new `stat-card`.

## Rollout order

1. Tokens + base CSS (no visible change yet).
2. Shared components listed above.
3. **Dashboard** — full showcase, user approves the real rendered result before continuing.
4. Remaining ~39 screens + PDF reports, grouped by module: Сметководство → Инвентар → Фактурирање → Партнери/Компании → Документи/Извештаи (incl. PDF layouts) → Профил/Поставки. Each screen/module gets a real browser check (and, for PDFs, a real generated PDF file check — never just "the HTML looks right," per the dompdf gotcha) before moving to the next.

## Verification approach

No automated visual-regression tooling is introduced (disproportionate for this project's scale/budget). Verification is manual but real: each updated screen is checked live in a browser preview (key interactions — forms, modals, dropdowns — exercised, not just static appearance); each updated PDF report is checked from actual generated PDF bytes/content, not the source HTML, because dompdf 3.1.6 silently drops flex/grid layouts without erroring.

## Out of scope / explicitly not doing

- No new page structures, navigation reorganization, or feature additions (that was the earlier "navigation/IA redesign" pass, already done).
- No changes to what data appears where, sort orders, filters, or workflow steps.
- No PDF content/field changes — layout only.
- No automated visual regression / screenshot-diff tooling.
