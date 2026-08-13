<?php

namespace Tests\Unit\Support;

use App\Support\Embg;
use PHPUnit\Framework\TestCase;

class EmbgTest extends TestCase
{
    public function test_it_accepts_a_number_with_a_correct_check_digit(): void
    {
        // Worked example from the algorithm's source: weights 7,6,5,4,3,2
        // repeated over the first 12 digits give a sum of 145; 145 mod 11 = 2;
        // 11 - 2 = 9, the final digit.
        $this->assertTrue(Embg::isValid('3101980455019'));
    }

    public function test_it_rejects_a_wrong_check_digit(): void
    {
        $this->assertFalse(Embg::isValid('3101980455018'));
    }

    public function test_it_rejects_anything_that_is_not_thirteen_digits(): void
    {
        $this->assertFalse(Embg::isValid('310198045501'));
        $this->assertFalse(Embg::isValid('31019804550199'));
        $this->assertFalse(Embg::isValid('310198045501X'));
        $this->assertFalse(Embg::isValid(''));
        $this->assertFalse(Embg::isValid('3101980 455019'));
    }

    public function test_it_rejects_an_impossible_birth_date(): void
    {
        // Day 32 — the first two digits are the day of birth.
        $this->assertFalse(Embg::isValid('3201980455010'));
    }

    public function test_a_remainder_of_one_is_never_a_valid_number(): void
    {
        // For the prefix 010199045009 the weighted sum is
        //   0·7 + 1·6 + 0·5 + 1·4 + 9·3 + 9·2 + 0·7 + 4·6 + 5·5 + 0·4 + 0·3 + 9·2
        //   = 0 + 6 + 0 + 4 + 27 + 18 + 0 + 24 + 25 + 0 + 0 + 18 = 122
        // and 122 mod 11 = 1, so the check digit would have to be 11 - 1 = 10 —
        // impossible in one position. No such ЕМБГ was ever issued, so every
        // one of the ten possible endings must be rejected.
        foreach (range(0, 9) as $digit) {
            $this->assertFalse(
                Embg::isValid('010199045009'.$digit),
                "010199045009{$digit} has a remainder of 1 and cannot be a real ЕМБГ."
            );
        }
    }
}
