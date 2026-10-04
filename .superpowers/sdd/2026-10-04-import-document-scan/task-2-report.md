# Task 2 Report: CustomsTariffAggregator

## RED: Test failure (before implementation)

```
php artisan test tests/Unit/Services/Inventory/CustomsTariffAggregatorTest.php
```

**Output:**
```
{"tool":"phpunit","result":"failed","tests":4,"passed":0,"assertions":0,"duration_ms":12,"errors":4}
```

All 4 tests fail with: `Class "App\Services\Inventory\CustomsTariffAggregator" not found`

## Files Created

- `tests/Unit/Services/Inventory/CustomsTariffAggregatorTest.php` (71 lines)
- `app/Services/Inventory/CustomsTariffAggregator.php` (71 lines)

## GREEN: All tests passing

```
php artisan test tests/Unit/Services/Inventory/CustomsTariffAggregatorTest.php
```

**Output:**
```
{"tool":"phpunit","result":"passed","tests":4,"passed":4,"assertions":12,"duration_ms":10}
```

✓ 4 tests, 4 passed, 12 assertions

## Commit

```
git add app/Services/Inventory/CustomsTariffAggregator.php tests/Unit/Services/Inventory/CustomsTariffAggregatorTest.php
git commit -m "Add CustomsTariffAggregator summing ECD items by tariff code"
```

**SHA:** `1101ba5`  
**Subject:** Add CustomsTariffAggregator summing ECD items by tariff code

## Self-Review

✓ **Names and signature:** Class `CustomsTariffAggregator`, method `aggregate(array $items): array` — matches brief exactly  
✓ **bcmath only:** All arithmetic via `bcadd()`, non-numeric amounts validated via `is_numeric()` and become '0'  
✓ **No extra features:** Pure class, no dependencies, one public method, one private helper, two constants  

## Test Coverage

1. **test_it_sums_items_by_tariff_code_in_first_seen_order:** Verifies summation by tariff code, preserves order, all totals correct
   - 234.61 + 193.36 = 427.97 (first tariff foreign)
   - 2533 + 2088 = 4621.00 (duty)
   - Grand totals: duty 4860.00, vat 6056.00, foreign 466.64

2. **test_other_charge_codes_are_reported_and_not_summed:** Non-A00/B00 codes (A10, C99) collected, sorted, reported but excluded from sums

3. **test_unreadable_amounts_count_as_zero_and_a_missing_tariff_code_gets_a_dash:** Invalid numbers ('x', ''), empty strings, and null tariff code all handled correctly

4. **test_no_items_gives_empty_result:** Empty input produces empty rows and '0.00' defaults

## Result

**Status:** DONE  
**All arithmetic verified.** No concerns.


## Fix report (review round 1)

**Changed**
- `app/Support/Bcmath.php`: added `Bcmath::isPlainNumber(?string)`. Uses `/^-?\d+(\.\d+)?$/D` — the `D` flag was added beyond the brief because plain `$` also accepts a trailing newline (`"12\n"`), which bcmath rejects.
- `CustomsTariffAggregator::amount()` now uses `Bcmath::isPlainNumber()` instead of `is_numeric`, so `' 12'`, `'12 '`, `'1e3'` count as 0 and `aggregate()` never throws.
- Tariff code is `trim((string) ...)`-ed before the empty check (whitespace-only becomes the dash row).

**Covering tests**
- `tests/Unit/BcmathTest.php::test_is_plain_number_accepts_only_what_bcmath_accepts` (true: '12','12.50','-3','0.5'; false: ' 12','12 ','1e3','.5','5.','1,5','',null,'abc',"12\n").
- `CustomsTariffAggregatorTest::test_amounts_bcmath_would_reject_count_as_zero_instead_of_throwing`
- `CustomsTariffAggregatorTest::test_tariff_codes_differing_only_by_whitespace_merge_into_one_row`

**Command:** `php artisan test tests/Unit/Services/Inventory/CustomsTariffAggregatorTest.php tests/Unit/BcmathTest.php`
**Output:** `passed, tests: 9, passed: 9, assertions: 41`
