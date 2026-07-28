# Journal Entry (Налози) Screen Overhaul — Design

**Date:** 2026-07-28
**Sub-project:** #4 of 7 in the 2026-07-27 live-testing feedback batch — the
largest of the seven.

## Scope

Full rework of the journal entry create/edit screen
(`app/Livewire/Accounting/JournalEntryForm.php` +
`resources/views/livewire/accounting/journal-entry-form.blade.php`), plus the
supporting list screen (`JournalEntryIndex`), a new per-company Journal
Groups settings screen, and a new single-entry PDF. Reference: a screenshot
of the legacy desktop app the user replaced this system with, showing the
target journal-selector UX and layout conventions.

Not in scope: any change to `JournalEntryLine`'s accounting/GL semantics
(debit/credit balancing rules, multi-currency posting math) beyond adding the
two new fields described below. Ledger Card / Trial Balance reports are
untouched.

## 1. Journal groups + per-journal numbering

**New table `journal_groups`** (per-company, not shared):

- `id`, `company_id` (FK), `code` (string, 2 chars, e.g. `10`, `11`, `20`),
  `name` (string, e.g. „Изводи-Денарски"), `sort_order` (int, for display),
  timestamps.
- Unique on `(company_id, code)`.
- Managed via a new Settings → Журнали CRUD screen (add/edit/delete),
  following the existing `PartnerIndex`/`ItemIndex` pattern (simple list +
  inline add/edit form). Delete is blocked (validation error, not a crash)
  if any `journal_entries` row still references the group.
- No separate "category" entity — the leading digit of `code` (e.g. the `1`
  in `10`/`11`/`12`) is purely a display grouping convention, implemented as
  `<optgroup>` buckets in the picker (grouped by `substr(code, 0, 1)`), not a
  stored relationship.

**`journal_entries` changes:**

- Add `journal_group_id` (FK to `journal_groups`, required for all entries
  going forward).
- Numbering scope changes from `(company_id, fiscal_year)` to
  `(company_id, fiscal_year, journal_group_id)` — the unique index and the
  `creating` hook's `max(entry_number)` lookup both gain `journal_group_id`
  to the `where()`. Resets to 1 each new fiscal year, same as today, just
  scoped per journal group now. Displayed everywhere as `{code}-{entry_number
  padded to 4 digits}`, e.g. `10-0012`.
- Deleting an entry leaves a permanent gap in that journal's numbering
  (no renumbering of later entries) — matches the user's explicit call and
  avoids ever invalidating a previously-printed voucher number.

**Migration / existing data:** a migration auto-creates one `journal_groups`
row per existing company with code `00` / name „Стари налози", and backfills
every existing `journal_entries` row (all of which predate this feature) to
that group, renumbering them 1, 2, 3... per company in their existing
`entry_date`/`id` order within each fiscal year. The user can delete any of
these via the new delete button (§6) once migrated, if she doesn't want to
keep them.

## 2. Line-level dates + "last valid date" highlighting

- `entryDate` (existing field, the one shown as "Дата на налогот") keeps its
  current job — fiscal year derivation, numbering, and now also becomes the
  **ceiling** ("последен важечки датум") lines are checked against. No new
  entry-level field.
- **New column** on `journal_entry_lines`: `line_date` (date, not nullable).
  Defaults to the entry's `entryDate` when a line is added, but is
  independently editable per row.
- In the form, a line whose `line_date > entryDate` gets a red background on
  that row (Tailwind, e.g. `bg-red-50` + red text on the date cell) — visual
  warning only, does not block saving.
- Migration backfills every existing line's `line_date` to its parent
  entry's `entry_date`.

## 3. Journal group picker

Single `<select>` per the approved approach, options grouped visually with
`<optgroup label="{leading digit} — ...">` by the leading digit of each
group's code, e.g.:

```
1 — Изводи
    10 — Изводи-Денарски
    11 — Изводи-Девизни
    12 — ПроКредит Банка
2 — Купувачи
    20 — ...
```

Optgroup labels are generated from whatever codes exist for the company
(no fixed/hardcoded 0-9 category names) — if she only ever creates codes
starting with `1` and `3`, only those two optgroups appear. Selecting a
group is required to save an entry. Changing the group on an *existing*
entry re-runs the numbering hook conceptually, but in practice the group is
fixed at creation and not editable afterward (editing a saved entry keeps
its original group/number — only new entries pick a group), avoiding
renumbering complexity.

## 4. Navigator (⏮ ◁ ▷ ⏭)

Four buttons scoped to the **current journal group only**, ordered by
`entry_number`:

- ⏮ first entry in this journal (this fiscal year)
- ◁ previous entry number in this journal
- ▷ next entry number in this journal
- ⏭ last entry in this journal

Each navigates directly to that entry's edit route
(`journal-entries.{id}.edit`). Buttons disable (not hide) at the start/end
of the list. Not shown at all when creating a brand-new, unsaved entry (no
group/number assigned yet).

## 5. PDF printing

New route + controller action (mirrors the existing sales-invoice PDF
pattern) rendering a single entry's voucher: company name, journal
code/name, entry number, entry date, description, the full line table
(account code+name, partner, description, line date, debit, credit), and
a totals row. **Table-based layout throughout** — no `display:flex` or
`display:grid` anywhere in the template, per the dompdf 3.1.6 limitation
already documented from sub-project #3 (dompdf silently drops flex to
`block`/`inline-block`, no flex reflower exists in the vendor tree). "Печати"
button on the show/edit screen opens/downloads this PDF for the currently
loaded entry.

## 6. Delete

Permanent "Избриши" button on the entry edit screen (visible to
admin/accountant, matching `update` policy — same gate as saving). Hard
delete (`$entry->delete()`, cascades to lines via the existing
`cascadeOnDelete()` FK) — no soft-delete, no undo, per explicit
confirmation. A JS `confirm()` prompt guards the click (existing convention
elsewhere in the app for destructive actions) before the Livewire action
fires. Leaves a numbering gap (§1).

## 7. FX (devizni) checkbox

One checkbox per entry (not per line): "Овој налог е во девизи". Unchecked
(default): the currency/rate/foreign-amount columns are not rendered at all
in the line table — just Сметка / Партнер / Опис / Датум / Должи / Побарува.
Checked: those columns appear for every line simultaneously, exactly as
today's fields (`currency_code`, `exchange_rate`, `foreign_amount`) — no
change to the underlying per-line schema, since the fields already exist on
`JournalEntryLine` and already tolerate a `MKD`/`1`/`null` combination for
non-FX lines. The checkbox is purely a **display toggle** — on save, lines
still carry whatever values their fields hold (all `MKD` when unchecked,
since those inputs are simply hidden rather than removed from the model).

For an existing entry that already has non-MKD lines (saved before this
toggle existed, or edited with the checkbox off by mistake), the checkbox
auto-checks itself on load whenever any line's `currency_code !== 'MKD'`, so
existing FX data is never hidden silently.

## 8. Autocomplete (account + partner)

Alpine.js-driven, client-side filtering — no new package (Alpine is already
a dependency since Phase 2's barcode scanner). On mount, the full active
`accounts` and `partners` collections for the company (already loaded by
`JournalEntryForm::render()` today) are passed into the page as JSON and
held in an Alpine `x-data` store per row. Typing in the code/name input
filters that in-memory list (substring match against code and name,
case-insensitive) and shows a small dropdown of matches; clicking one sets
the row's `account_id`/`partner_id` (via `wire:model` on a hidden input) and
displays "**{code} — {name}**" underneath, matching the legacy app's
Конто-box behavior from the screenshot. Existing rows show their saved
selection pre-filled the same way on load.

## 9. Sticky footer

A `fixed`/sticky bottom bar (stays on screen while the line table scrolls
above it) showing three live-updating figures: Вкупно должи / Вкупно
побарува / Салдо (difference) — recalculated via Livewire on every
debit/credit input change, mirroring the legacy app's bottom "Салдо на
налогот" readout. Renders in the same non-balanced red / balanced neutral
styling already used by the existing balance-check error message.

## 10. Compact, responsive layout

- Small base font size for the line table (`text-xs`/`text-sm`) to fit more
  columns without horizontal scroll on desktop/tablet width.
- Below a phone-width breakpoint (Tailwind `sm:`), the table switches to a
  stacked-card layout: each line renders as its own bordered card with
  labeled fields stacked vertically, instead of table columns — avoiding
  horizontal scrolling entirely on narrow screens, at the cost of more
  vertical scrolling per line (an accepted, standard trade-off for
  responsive tabular data).
- The sticky footer (§9) and the journal/date/navigator header stay fixed
  in place; only the line list scrolls between them, keeping the whole
  screen navigable without the page itself needing to scroll on typical
  tablet/laptop viewport heights.

## Out of scope

- Any change to journal entry GL semantics beyond the two new columns
  (`journal_group_id`, `line_date`).
- Editing an existing entry's journal group after creation (see §3).
- Multi-entry PDF batches / range printing (§5 covers single-entry only).
- Soft-delete / recovery of deleted entries (§6 — explicitly hard delete).
- Partner/Item screens (sub-projects #5, #6) and Stock report (#7).

## Testing notes

- Numbering: a dedicated regression test creating entries across two
  journal groups in the same fiscal year must prove counters are
  independent (group A reaching 0003 does not affect group B's first entry
  being 0001).
- Migration: a test seeding a pre-migration company with existing
  no-group entries confirms they land in a `00` group with correct
  sequential renumbering and that `line_date` backfills to each entry's
  `entry_date`.
- Delete: a regression test confirms a gap is left (deleting entry 2 of 3
  in a journal leaves 1 and 3, does not shift 3 down to 2).
- FX auto-check: a test loads an existing entry with a non-MKD line created
  before this feature and confirms the checkbox renders checked without
  any user action.
- PDF: per the dompdf lesson, the test must render the actual PDF and
  inspect its content stream (not assert on the Blade/HTML string) before
  trusting any layout claim — the sub-project #3 review process is the
  template to follow here.
