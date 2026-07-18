# Phase 1: Accounting Core — Design

**Date:** 2026-07-18
**Status:** Approved

## Purpose

Build the double-entry bookkeeping core: a per-company chart of accounts based on the Macedonian official chart of accounts (Правилник за сметковниот план, 174/2011), manual journal entries, the resulting general ledger, and the reports the user's firm actually produces day to day (analytical account cards and trial balances). This is the foundation every later module (Inventory, Invoicing, HR, Documents & Reports) will post transactions into.

## Reference Material

- **Правилник за сметковниот план и содржината на одделните сметки во сметковниот план** (174/2011) — the legally binding chart of accounts. Full text extracted and parsed into `docs/reference/official-chart-of-accounts.json` (428 official synthetic accounts across 9 used classes — class 5 is reserved/unused by the regulation). Each entry has `class`, `class_name`, `group`, `group_name`, `code` (3-digit synthetic account), `name`.
- **Sample report layouts** supplied by the user (Аналитичка картица - конто / конто+фирма / фирма; Бруто биланс / по синтетики / по фирми; Кумулатив по аналитички конта и фирми) — used to derive the report designs below from a real, already-in-use Macedonian accounting package's output.

## Domain Structure (confirmed against the regulation and sample reports)

- **Класа** (class) — 1 digit, 0–9. Fixed by law.
- **Група** (group) — 2 digits, e.g. `12`. Fixed by law.
- **Синтетичка сметка** (synthetic account) — 3 digits, e.g. `120`. Fixed by law — this is the full extent of what the regulation defines (428 accounts total).
- **Аналитичка сметка** (analytical account) — 4+ digits, e.g. `1200`, `2341`. **Not** government-mandated — each accounting firm defines its own analytical breakdown of a synthetic account as needed (e.g. official `234` gets split into `2341`/`2342`/`2343` for pension/health/supplemental-health contributions). Confirmed against the sample reports, which post and report at this 4-digit level.
- **Фирма** (business partner / counterparty) — a separate dimension, not part of the account code at all. Every ledger line can optionally be tagged with a customer/supplier/contact, used for AR/AP subledger reporting. Distinct from the existing tenant `Company` model (which represents the accounting firm's *clients*, i.e. tenants) — this is each tenant's own external contacts.

## Data Model

- **`accounts`** (scoped by `company_id`): `code` (3-digit synthetic, seeded from the official 428 at company creation, or a 4-digit+ analytical code added later), `name`, `class`, `group` (both derived from `code` and stored for query convenience), `parent_code` (null for synthetic accounts; the synthetic parent's code for analytical accounts), `is_analytical` (bool), `is_active` (bool, default true). The official 428 rows are duplicated into every company's own table rather than referenced from a shared table — trivial storage cost, and every ledger/report query stays a single-table lookup with no joins to a global reference table.
- **`partners`** (scoped by `company_id`): `name`, `tax_id` (ЕДБ/ЕМБС), `address`, `contact_info`. Built out fully now since Invoicing and е-Фактура (later phases) need the same fields.
- **`journal_entries`** (scoped by `company_id`): `entry_number` (sequential per company per fiscal year), `entry_date`, `description`, `created_by` (user id).
- **`journal_entry_lines`**: `journal_entry_id`, `account_id`, `partner_id` (nullable), `description`, `debit` / `credit` (decimal, MKD), `currency_code` (default `MKD`), `exchange_rate` (default 1), `foreign_amount` (nullable — the original-currency amount when `currency_code` isn't MKD).
- **`exchange_rates`**: `rate_date`, `currency_code`, `rate` — cached daily from NBRM's public exchange rate feed.

## Journal Entries

- A form with 2+ debit/credit lines; must balance (total debit = total credit) before it can be saved.
- **Save = posted immediately.** No draft/review workflow — the entry is live in the ledger and reports as soon as it's saved, matching how the sample reports (and the user's day-to-day bookkeeping) actually work.
- **Freely editable and deletable after posting.** No reversing-entry requirement — this is a deliberate simplicity choice for a small firm's workflow, accepted with the trade-off that there's no audit trail of what changed on an edited entry. Revisit only if that becomes an actual problem in practice.
- `entry_number` is a single sequential counter per company per calendar year (the fiscal year, by law, for Macedonian companies — confirmed by both the regulation's own language and the sample reports, which are all bounded `01-01-23 → 31-12-23`; no separate fiscal-year configuration needed) — no document-type-specific series (invoice numbers, bank statement numbers) in this phase. Those arrive naturally with the Invoicing and other later phases, which will introduce their own numbered document types that post into this same ledger.
- Each line's account is picked by code or name from that company's chart of accounts; `partner_id` is optional on every line (not restricted to AR/AP-type accounts, since that's a soft convention rather than a hard rule).

## Multi-Currency

- **MKD is always the ledger's base/reporting currency.** Every line has a debit/credit amount in MKD; foreign-currency lines additionally carry `currency_code`, `exchange_rate`, and `foreign_amount`.
- **Exchange rates are auto-fetched from NBRM's public feed** (`https://www.nbrm.mk/KLServiceNOV/GetExchangeRate` — confirmed live, free, no authentication, returns JSON with a `sreden` (mid) rate per currency per date) and cached locally in `exchange_rates` by date. When a line's date has no cached rate yet, it's fetched and cached on first use.
- The fetched rate pre-fills the line but is editable — the user can override it (e.g. to match the exact rate printed on an invoice or bank statement).
- **No automatic period-end FX revaluation or realized/unrealized gain-loss postings in this phase.** That's a distinct accounting process (comparing historical booked rates against period-end rates and posting the difference) that deserves its own phase rather than being folded into core ledger work.

## Chart of Accounts Screen

- A dedicated screen per company, listing accounts grouped by class → group → synthetic account (tree/nested view).
- Each account can be activated/deactivated (deactivated accounts are hidden from the journal entry account picker but remain in historical reports).
- Analytical (4-digit+) accounts are added here, under a chosen synthetic parent, with a name the firm defines. The synthetic accounts' own codes and legally-defined names are not editable.
- No inline "create account while posting a journal entry" shortcut in this phase — chart-of-accounts changes go through this screen only, keeping the chart intentional rather than accumulating ad hoc accounts.

## Reports

Two underlying report engines, each parameterized by a grouping dimension, together covering all seven sample layouts:

1. **Ledger card** (Аналитичка картица) — per-transaction detail with a running balance, for a date range. Grouping parameter selects the sample's three variants:
   - by account (конто)
   - by account + partner (конто+фирма)
   - by partner (фирма)
2. **Trial balance** (Бруто биланс) — opening balance / period movement / closing balance columns, for a date range. Grouping parameter selects:
   - by full account code, i.e. including 4-digit analytical accounts (Бруто биланс, the detailed default)
   - by 3-digit synthetic account only, collapsing analytical sub-accounts (по синтетики)
   - by partner (по фирми)
   - the "Кумулатив по аналитички конта и фирми" sample is the same engine grouped by account *and* partner, with per-transaction detail omitted (totals only) — a summarized variant of the same query rather than a separate report.

Both engines run against `journal_entry_lines` filtered by `company_id` and date range; the grouping parameter only changes the `GROUP BY` / row aggregation, not the underlying query shape.

## Testing

- Chart of accounts seeding: every new company ends up with all 428 official synthetic accounts, correctly classed/grouped, matching `docs/reference/official-chart-of-accounts.json`.
- Journal entry balance validation: an unbalanced entry (debit ≠ credit) is rejected; a balanced entry posts and appears immediately in the ledger.
- Ledger and trial balance correctness: seeded test entries with known figures, asserting the report output (opening/movement/closing, running balances) matches hand-calculated expected values.
- FX line posting: a foreign-currency line correctly fetches/caches an NBRM rate and computes the right MKD equivalent; an overridden rate is respected over the fetched one.
- Multi-tenancy: a company only ever sees its own accounts, partners, and journal entries (consistent with the existing Phase 0 `company_id` scoping and policies).

## Out of Scope (this phase)

- Fiscal year setup / opening balance import for companies onboarding mid-year (deferred — this phase assumes a company starts clean or its opening balances are entered as a normal journal entry).
- VAT-specific tagging or ДДВ-04 filing logic (Documents & Reports phase).
- Automatic FX period-end revaluation and gain/loss postings.
- Draft/approval workflow for journal entries.
- Reversing-entry-only correction model (entries are freely editable instead).
- Auto-posting from Inventory, Invoicing, or HR modules — those phases will post into this ledger once built, but that integration isn't part of this phase.
- Document-type-specific numbering series (invoice numbers, bank statement numbers) — this phase uses one simple sequential journal number per company per fiscal year.
