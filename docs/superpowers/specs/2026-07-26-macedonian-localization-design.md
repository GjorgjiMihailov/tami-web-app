# Macedonian Localization — Design

**Date:** 2026-07-26
**Status:** Approved

## Context

tami-web-app was scaffolded with Laravel Breeze and built out across Phases 0–4b plus a visual redesign and navigation/IA redesign. Text is currently a mix of English (Breeze auth pages, most module screens, validation messages, system error pages) and a few hand-typed Macedonian labels (the Sidebar's module names: Сметководство/Магацин/Фактури/Документи/Извештаи). All real users — the firm's admin/accountants and their clients — are Macedonian speakers. The phase roadmap was put on hold 2026-07-22 in favor of this and the visual/navigation redesigns; this is the last item from that hold.

Current state confirmed by codebase survey:
- `config/app.php` locale defaults to `env('APP_LOCALE', 'en')`; no `lang/` directory exists.
- No formatting is centralized. Dates render in three different inconsistent formats today (`d.m.Y`, `d.m.y`, ISO `Y-m-d`) across ~12 call sites. Money has ~48 call sites — some via `number_format()`, many printing raw bcmath decimal strings (e.g. `123.00`) with no thousands separator or currency symbol at all.
- Report queries (`LedgerCardQuery`, `TrialBalanceQuery`, `Ddv04Query`, `StockLevelQuery`, `ItemMovementCardQuery`) return plain arrays, not Eloquent models — so any formatting fix must work on raw values, not just model attributes.
- No `resources/views/errors/` overrides exist — 403/404/419/500 are still Laravel's stock English pages.
- The sales invoice PDF (`resources/views/pdf/sales-invoice.blade.php`) uses `font-family: sans-serif`, which dompdf does not render as Cyrillic-capable — needs to be pinned to `DejaVu Sans` (bundled with dompdf) or translated PDF text will render as broken glyphs/boxes.
- `APP_NAME` is still the Laravel default (`Laravel`).

## Decisions

**Macedonian-only, no language switcher.** All real users are Macedonian speakers; a bilingual toggle would double the translation-maintenance burden for no actual user benefit. `APP_LOCALE` becomes `mk` and stays fixed.

**Text is hardcoded directly in views, not routed through `__()`/lang files** — except validation messages, which are framework-generated and use Laravel's official `lang/mk/validation.php` translations as a base. This matches the existing Sidebar precedent and avoids introducing translation-key indirection for a language that will never be switched away from.

**Date/number formatting also switches to Macedonian convention**, via a new `App\Support\Format` static helper class (mirrors the existing `App\Support\Bcmath` helper):
- `Format::date($value): string` — renders `d.m.Y`.
- `Format::money($amount, $currency = 'ден'): string` — dot-thousands, comma-decimal (e.g. `1.234,56 ден`), with a `$currency` override so the foreign-currency journal-entry screens can pass the real currency code (`EUR`, `USD`, etc.) instead of `ден`.

A static helper class (not a Blade component, not per-model accessors) is used because report data is frequently a plain array, not an Eloquent model — a helper works identically on both. All ~60 existing date/money display call sites are updated to call it.

**PDF invoice**: labels translated (Invoice→Фактура, Date→Датум, Total→Вкупно, etc.), and the CSS `font-family` pinned to `'DejaVu Sans'` so Cyrillic renders correctly.

**Peripheral scope**: `APP_NAME` changes from `Laravel` to `Тами`; the root layout's `<html lang="en">` becomes `<html lang="mk">`. Logs, code comments, and artisan/admin-only tooling stay in English (developer-facing, not user-facing — no change).

## Scope of work (module breakdown)

Text translation is broken into module-sized units for the implementation plan, all within a single design/spec/plan cycle (not split into sub-phases like 3a/3b, given the pieces are tightly coupled — the formatting helper needs to exist before module views can use it, and modules don't have meaningful independent deploy value here):

1. **Formatting helper** — `App\Support\Format` (date + money), unit tested, foundational for everything after.
2. **Auth + system error pages** — Breeze login/register/forgot-password/reset-password views; new `resources/views/errors/{403,404,419,500}.blade.php`.
3. **Validation messages** — publish `lang/mk/validation.php` (Laravel's official Macedonian translations), tune `attributes` mapping so messages read naturally (e.g. `"name" => "назив"` rather than a literal field-name translation producing an awkward sentence).
4. **Accounting module** — chart of accounts, journal entries, ledger card / trial balance reports.
5. **Inventory module** — warehouses, items, stock movement screens, barcode scanner UI, stock reports.
6. **Invoicing module** — sales + purchase invoice forms/show/index, partner screens, payment recording.
7. **Documents + Reports** — document manager/index, ДДВ-04 report screen's surrounding UI chrome (its field labels are already partly Macedonian from Phase 4b).
8. **Settings + shell** — company management, dashboard, company picker, any remaining English nav/buttons outside the Sidebar; `APP_NAME` + `<html lang>` change.
9. **PDF invoice** — label translation + `DejaVu Sans` font fix.

## Testing & verification

- `Format` helper gets unit tests: date formatting, money formatting (including the currency-override case and edge cases — negative amounts, zero, large numbers with thousands separators).
- The existing 353-test suite has assertions on visible English strings (`assertSee('Save')`, validation message text, etc.) — these are expected to break as text changes and are fixed within each module's own task, not as a separate cleanup pass.
- No automated "is this actually Macedonian" test — not meaningfully testable. Verification is manual click-through per module (via browser preview) plus a final whole-branch grep sweep for common leftover English strings (Save/Cancel/Edit/etc.) across `resources/views/` before considering the work done.
- PDF: manually generate a real invoice PDF and visually confirm Cyrillic renders correctly — the one item a grep/text-assertion pass can't catch (a font misconfiguration still "passes" content checks while rendering boxes).

## Out of scope

- Any language switcher / bilingual support.
- Translating logs, code comments, or artisan/admin tooling.
- Anything beyond text + date/number/currency formatting (no new features).
