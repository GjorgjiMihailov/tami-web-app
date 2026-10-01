<?php

namespace Tests\Unit\Services\Inventory;

use App\Services\Inventory\LandedCostAllocator;
use PHPUnit\Framework\TestCase;

class LandedCostAllocatorTest extends TestCase
{
    private LandedCostAllocator $allocator;

    protected function setUp(): void
    {
        parent::setUp();
        $this->allocator = new LandedCostAllocator;
    }

    public function test_zero_import_costs_leaves_landed_cost_equal_to_net_over_quantity(): void
    {
        $result = $this->allocator->allocate([
            'a' => ['net' => '500.00', 'quantity' => '10'],
        ], '0.00');

        $this->assertSame(['a' => '50.0000'], $result);
    }

    public function test_no_stock_lines_returns_empty(): void
    {
        $this->assertSame([], $this->allocator->allocate([], '100.00'));
    }

    public function test_allocates_proportionally_with_remainder_on_the_last_line(): void
    {
        $result = $this->allocator->allocate([
            'a' => ['net' => '800.00', 'quantity' => '10'],
            'b' => ['net' => '200.00', 'quantity' => '5'],
        ], '100.00');

        // a: share = 100*800/1000 = 80.00 exactly; landed value 880.00 / 10
        $this->assertSame('88.0000', $result['a']);
        // b (last): share = 100.00 - 80.00 = 20.00; landed value 220.00 / 5
        $this->assertSame('44.0000', $result['b']);
    }

    public function test_rounding_remainder_lands_on_the_last_line_not_split_evenly(): void
    {
        $result = $this->allocator->allocate([
            'a' => ['net' => '100.00', 'quantity' => '1'],
            'b' => ['net' => '100.00', 'quantity' => '1'],
            'c' => ['net' => '100.00', 'quantity' => '1'],
        ], '100.00');

        // Naive equal thirds would be 33.33/33.33/33.33 = 99.99, losing a
        // cent. a and b each round to 33.33; c absorbs the remainder: 33.34.
        $this->assertSame('133.3300', $result['a']);
        $this->assertSame('133.3300', $result['b']);
        $this->assertSame('133.3400', $result['c']);
    }

    public function test_zero_quantity_line_gets_zero_landed_cost_instead_of_dividing_by_zero(): void
    {
        $result = $this->allocator->allocate([
            'a' => ['net' => '100.00', 'quantity' => '0'],
        ], '50.00');

        $this->assertSame('0.0000', $result['a']);
    }
}
