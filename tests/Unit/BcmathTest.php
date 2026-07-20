<?php

namespace Tests\Unit;

use App\Support\Bcmath;
use Tests\TestCase;

class BcmathTest extends TestCase
{
    public function test_it_rounds_half_up_at_the_given_scale(): void
    {
        $this->assertSame('1.235', Bcmath::roundHalfUp('1.2345', 3));
        $this->assertSame('1.234', Bcmath::roundHalfUp('1.2344', 3));
        $this->assertSame('10.00', Bcmath::roundHalfUp('9.995', 2));
    }

    public function test_it_rounds_negative_values_correctly(): void
    {
        $this->assertSame('-1.235', Bcmath::roundHalfUp('-1.2345', 3));
    }
}
