# Sidebar IA Regrouping + Working Year Context — Design

**Date:** 2026-08-11
**Status:** Approved by user, ready for planning
**Related:** [[tami_web_app_project]] memory file. Follows the UI/UX redesign (`2026-08-08-ui-ux-redesign-design.md`), which changed how the app *looks*; this one changes how it is *organised*.

## Motivation

The user (firm owner, admin) supplied a full target information architecture for the app's side menu. Two problems with today's menu drove it:

1. **The grouping no longer matches how the work is actually done.** Today's groups (Сметководство / Магацин / Фактури / Документи / Извештаи) were derived from the build order of the phases, not from the accounting workflow. The user's target groups (ФИНАНСИИ / ПРОДАЖБА / ЗАЛИХА / ПЛАТИ И ЧР / ПОСТАВКИ) follow the workflow.
2. **The menu is nearly identical for all three roles.** The only role-conditional item today is "е-Фактура барања" (admin). A `client` currently sees Контен план, Журнали, Налози and Бруто биланс — pure bookkeeping screens they neither use nor should see.

A third requirement emerged during brainstorming: the app has **no concept of a working year**. `fiscal_year` exists as a column on `journal_entries` and `sales_invoices`, but every list shows all years mixed together. The user's stated requirement — "задолжително година за која се работи, секоја година е посебна работа" — makes this a prerequisite, not a nice-to-have.

## Chosen approach: build the full target menu now ("target map")

The user was offered three options and explicitly chose **(А) the full target structure now**, including items whose features do not exist yet. Those render as visible-but-not-yet-working entries.

Rationale accepted by the user: the structure gets decided once; each future phase fills an already-existing slot instead of re-litigating the menu. The menu doubles as a visible roadmap for the admin.

## Deliverable 1 — Working year context

### Behaviour

- A year selector sits at the top of the sidebar, directly under a company selector.
- The selection is stored **per user, per company**, for the duration of the session. Switching company A → B → A restores A's year.
- It **filters lists**: journal entries and sales invoices by `fiscal_year`; purchase invoices and stock movements by their document date.
- It **pre-fills dates**: a new record opened while working in 2025 defaults its date into 2025, not to today.
- It **pre-fills report periods**: ДДВ-04, Бруто биланс, Аналитичка картица open with a period inside the selected year.
- Changing the year re-renders the current list immediately.
- The dropdown lists years for which the company has data, plus the current calendar year. A brand-new company sees only the current year.
- On login, the current calendar year is selected.

### Explicit non-behaviour (important)

**The working year never writes `fiscal_year` onto a record.** The stored fiscal year continues to be derived from the document's own date, exactly as today.

This is not a simplification — it is a correctness requirement. Sales invoice numbering restarts at 1 per fiscal year and is derived from `invoice_date->year` (`SalesInvoiceService.php:41`). If the selector could override it, an invoice dated January 2026 entered while the selector read "2025" would be issued a number from the **2025 series**. That is a silent data-integrity bug that would surface only at ДДВ filing time.

So: **the selector displays and filters; the document date decides.**

### Guard rails

- Opening a record outside the working year (e.g. a 2025 journal entry while working in 2026) is **allowed**, and shows an unobtrusive notice "Запис од 2025". It does not block.
- An empty list must not say only "нема записи". It must say **"Нема записи за &lt;year&gt; — провери дали работиш во вистинската година"**, so a year mis-selection is never mistaken for data loss.
- **Документи (the archive) is deliberately NOT filtered by year.** Its whole purpose is finding a file when you don't know what it's attached to; year-scoping it would defeat that. It keeps its own date filters.

## Deliverable 2 — The new menu

### Target structure (exactly two levels)

The menu is group → item. Nothing goes deeper. Anything the user described as "копче" lives as a button or card **on the page**, not as a third menu level.

```
Почетна                       (admin only)
Фирми                         (admin only)
──────────────────────────
Фирма:  [ selector ]
Година: [ selector ]
──────────────────────────
ФИНАНСИИ
   Главна книга
   Извештаи и обрасци
   Изводи
ПРОДАЖБА
   Излезни фактури
   Влезни фактури
   Профактури
   Кооперанти
ЗАЛИХА
   Магацини
   Артикли
   Состојба
   Прием
   Излез
   Пренос
   Попис
ПЛАТИ И ЧОВЕЧКИ РЕСУРСИ
   Вработени
   Плата (МПИН)
   е-ПДД
ПОСТАВКИ
   Компанија
   Контен план
   е-Фактура барања          (admin only)
──────────────────────────
Документи
```

### Item-by-item status

| Item | Status | Note |
|---|---|---|
| Главна книга | rebuild | merges today's "Журнали" + "Налози": list of groups → click a group → its entries |
| Извештаи и обрасци | new landing page | buttons: ДДВ-04 ✔, Бруто биланс ✔, Аналитичка картица ✔, МДБ ✖, Завршна сметка ✖, Солвентност ✖ |
| Изводи | not built | one page, денарски + девизни sections; future OCR intake target |
| Излезни / Влезни фактури | exists | unchanged |
| Профактури | not built | |
| Кооперанти | exists | label-only rename of "Партнери" |
| Магацини, Артикли, Состојба | exist | Артикли page carries "Масовен внес" as a button; Состојба page carries "Картица на движење" and "Вреднување на залихи" as buttons |
| Прием, Излез, Пренос | exist, unchanged | User explicitly decided to leave them working as they are today (raw stock movements, no приемница/испратница/преносница document yet). Those documents are a later phase. |
| Попис | not built | |
| Вработени, Плата (МПИН), е-ПДД | not built | whole module; user will supply the MPIN .txt template and the е-ПДД .xml sample when that phase starts |
| Компанија, Контен план, е-Фактура барања | exist | moved into ПОСТАВКИ |
| Документи | exists | pulled out of the groups, stands alone |

**"Корекција" (stock adjustment) is removed from the menu** — the user decided Попис replaces it.

### Accordion behaviour

Unchanged from today: one group open at a time; opening another closes the previous one. With five groups this is required, not stylistic — otherwise the menu exceeds the viewport.

### Not-yet-built items

- Render as normal menu entries, visually muted, with a small "наскоро" marker.
- Clicking opens **one shared placeholder page** stating the feature name and one sentence describing what it will do.
- **Visible to admin and accountant only. The client never sees them.** (User chose option Б.) The client's menu contains only working features.

Rationale for a clickable placeholder rather than a disabled button: a disabled control reads as broken and communicates nothing; a page can state intent. For the admin this list *is* the remaining-work map, which was the point of choosing the "target map" approach.

**A group with no visible items is hidden entirely.** This follows from the rule above and matters in one concrete case today: every item in ПЛАТИ И ЧР is unbuilt, and clients do not see unbuilt items — so a client would otherwise get an empty group header. Until that module ships, a client simply has no ПЛАТИ И ЧР group. The role table below records the *intent* (client gets the full module), not what is on screen today.

## Role visibility

| | admin | accountant | client |
|---|:--:|:--:|:--:|
| Почетна, Фирми | ✔ | ✖ | ✖ |
| Company selector scope | all companies | assigned companies | own company |
| Year selector | ✔ | ✔ | ✔ |
| ФИНАНСИИ | ✔ | ✔ | ✖ |
| ПРОДАЖБА | ✔ | ✔ | ✔ |
| ЗАЛИХА | ✔ | ✔ | ✔ |
| ПЛАТИ И ЧР | ✔ | ✔ | ✔ (full, including editing employees) |
| ПОСТАВКИ | all | without е-Фактура барања | Компанија only |
| Документи | ✔ | ✔ | ✔ |
| "наскоро" items | ✔ | ✔ | ✖ |

Two explicit user decisions worth recording, because both are counter-intuitive:

- The client does **not** get read-only access to their own ДДВ/завршна сметка forms ("не мора").
- The client **does** get the full ПЛАТИ И ЧР module, including editing employee records ("треба да гледа све, да може и да менува кај вработените ако треба").

### Landing after login

Removing Почетна and Фирми from non-admin menus raises a question the user's plan did not cover: where do those users land?

- **admin** → Почетна (dashboard), unchanged.
- **client** → straight into their own company, since they have exactly one.
- **accountant** → their assigned company if they have only one; if they have several and none is remembered from a previous session, a plain company-choice screen. This screen is reachable only in that state — it is not a menu item, so it does not reintroduce "Фирми" for accountants.

## Access control — a real gap this design must close

**Hiding a menu item is not authorization.** Verified during brainstorming: `JournalEntryPolicy::view()` (`app/Policies/JournalEntryPolicy.php:15`) returns true for any user whose `visibleCompanies()` includes the record's company — which includes a `client` viewing their own company. Journal-entry, account, journal-group and trial-balance screens gate on `Gate::authorize('view', $company)`, which a client passes for their own company.

So removing ФИНАНСИИ from the client's menu, on its own, leaves every accounting screen reachable by URL or by an old bookmark.

**This design therefore includes a real server-side restriction on the accounting screens: admin and accountant only.** Without it, "the client does not see ФИНАНСИИ" is cosmetic.

## Renaming policy

Visible labels change; **route names and URLs do not**. `partners.*`, `inventory.*`, `accounting.*` stay as they are, even though the labels become Кооперанти / ЗАЛИХА / ФИНАНСИИ.

Renaming the routes would touch every view, PDF controller and test for zero user-visible benefit, and would break existing bookmarks. Not worth it.

## Out of scope

- **Mobile sidebar behaviour.** The sidebar is a fixed `w-60` column with no collapse control, which on a phone occupies roughly half the screen. Real, but a separate piece of work; flagged to the user, who did not add it here.
- Приемница / испратница / адресница / преносница as numbered, printable documents.
- OCR of bank statements, Telegram/Viber intake, and automatic posting of attached documents.
- Monthly batching of invoices into a single journal entry per month (group 20 / group 30). Described by the user as the target workflow, but it is a Главна книга *behaviour* change, not a menu change.
- Any actual implementation of Профактури, Попис, МДБ, Завршна сметка, Солвентност, or the ПЛАТИ И ЧР module.

Each of the above becomes its own design → plan cycle later.

## Risks

1. **Year filtering can look like data loss.** Mitigated by the explicit empty-state wording above.
2. **Existing tests will break on introduction of the year filter.** Many create a record and then assert it appears in a list; once lists are year-scoped, tests unaware of the working year will fail. This is expected work inside the plan, not a defect.
3. **Livewire route-param gotcha (already hit once on this project).** The sidebar must not derive state from `request()->route(...)` inside `render()` while also having `wire:click` actions — the `/livewire/update` POST carries no route params and the state is silently lost. Capture it once in `mount()` as a public property. The current `Sidebar` component already does this correctly; the rewrite must preserve that.
4. **Tailwind JIT requires a final rebuild after all Blade changes land**, or new classes silently do not ship.

## Verification

- Each of the three roles sees exactly the menu specified in the role table — no more, no less.
- A `client` requesting an accounting URL directly is refused (not merely un-linked).
- Changing the working year changes list contents; a new record pre-fills a date inside the selected year.
- An invoice dated January 2026 receives a number from the 2026 series even when the working year selector reads a different year.
- Company switch preserves each company's own remembered year.
- Full suite green (currently 738 tests).

## Delivery order — two plans

| Order | Plan | Why |
|---|---|---|
| 1 | Working year context | The menu hosts the selector at its top; building the sidebar twice would be waste. |
| 2 | Menu restructure + role visibility + accounting access restriction | Builds on the finished year context. |

Each ships and deploys independently, so the app is never left half-restructured.
