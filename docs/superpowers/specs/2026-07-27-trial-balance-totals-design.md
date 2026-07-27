# Trial Balance — Totals Row — Design

## Context

Real-time testing of the live app surfaced a batch of 12 change requests
across multiple modules (logged in the session, decomposed into 7
sub-projects, ordered with user approval). This is sub-project #1 (of 7):
the smallest, used as a warm-up before the larger Company Profile and
Sales Invoice redesigns.

## Scope

The Trial Balance report (`app/Livewire/Accounting/TrialBalanceReport.php`,
`app/Services/Accounting/TrialBalanceQuery.php`) currently renders one row
per grouping key (account, synthetic account, partner, or
account+partner) with four numeric columns: opening balance, period debit
movement, period credit movement, closing balance. There is no totals
row.

**In scope:** add a "Вкупно" (Total) row at the bottom of the table,
summing all four numeric columns across every row currently displayed.

**Out of scope:** any conditional highlighting/color-coding of the total
closing balance. Confirmed with the user: a properly-booked ledger always
nets to zero when grouped by account/synthetic (journal entries are only
ever saved balanced), and a non-zero total when grouped by
partner/account-partner is expected and not an error (lines without a
partner are legitimately excluded from that grouping) — so the total is
displayed plainly, with no special-case logic. Also out of scope: making
this row sticky/fixed while scrolling — that request was actually about
the *Journal Entry* screen's running-balance bar (a separate, later
sub-project), not this report.

## Implementation

- `TrialBalanceReport::render()` computes the four column sums from the
  already-returned `$rows` collection (`$rows->sum('opening_balance')`,
  etc.) — no change to `TrialBalanceQuery` itself, since this is a
  presentation-layer total over data the query already returns.
- The Blade view (`resources/views/livewire/accounting/trial-balance-report.blade.php`)
  gets a `<tfoot>` row with a bold "Вкупно" label and the four sums,
  formatted with the existing `App\Support\Format::money()` helper
  (matching every other cell in the table). Rendered unconditionally,
  including when `$rows` is empty (sums are then all 0.00) — the existing
  "Нема промет во овој период." empty-state row in `<tbody>` is
  unchanged.

## Testing

Extend `TrialBalanceReportTest` with one case asserting the totals row's
four values equal the sum of the individual rows' values for a period
with multiple accounts/partners in it.
