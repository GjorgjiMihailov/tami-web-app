<?php

namespace Tests\Feature\Inventory;

use App\Models\Company;
use App\Models\Item;
use App\Models\StockLevel;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\Inventory\StockMovementService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class StockImportShareTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private Item $item;

    private Warehouse $warehouse;

    private int $userId;

    private StockMovementService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->company = Company::factory()->create();
        $this->item = Item::factory()->for($this->company)->create(['type' => 'product']);
        $this->warehouse = Warehouse::factory()->for($this->company)->create();
        $this->userId = User::factory()->create()->id;
        $this->service = app(StockMovementService::class);
    }

    private function level(?Warehouse $warehouse = null): StockLevel
    {
        return StockLevel::where('item_id', $this->item->id)->where('warehouse_id', ($warehouse ?? $this->warehouse)->id)->firstOrFail();
    }

    /** 10 домашно по 10 (=100) + 10 од увоз по 20 (=200): вредност 300, просек 15, увоз 200. */
    private function mixed(): void
    {
        $this->service->receipt($this->item, $this->warehouse, '10', '10.00', '2026-03-01', $this->userId);
        $this->service->receipt($this->item, $this->warehouse, '10', '20.00', '2026-03-02', $this->userId, '200.000000');
    }

    public function test_a_domestic_receipt_adds_no_import_value_and_an_import_receipt_adds_its_value(): void
    {
        $this->service->receipt($this->item, $this->warehouse, '10', '10.00', '2026-03-01', $this->userId);
        $this->assertSame('0.000000', (string) $this->level()->import_value);

        $movement = $this->service->receipt($this->item, $this->warehouse, '10', '20.00', '2026-03-02', $this->userId, '200.000000');

        $this->assertSame('200.000000', (string) $this->level()->import_value);
        $this->assertSame('200.000000', (string) $movement->import_value);
        $this->assertSame('15.0000', (string) $this->level()->average_cost);
    }

    public function test_the_import_part_of_a_receipt_cannot_exceed_its_value(): void
    {
        $this->service->receipt($this->item, $this->warehouse, '2', '10.00', '2026-03-01', $this->userId, '999.000000');

        $this->assertSame('20.000000', (string) $this->level()->import_value);
    }

    public function test_an_issue_takes_the_import_part_in_proportion_to_the_share(): void
    {
        $this->mixed();

        $issue = $this->service->issue($this->item, $this->warehouse, '5', '2026-03-10', $this->userId);

        // излегува 5 × 15 = 75; уделот од увоз е 200/300 → 50
        $this->assertSame('50.000000', (string) $issue->import_value);
        $this->assertSame('150.000000', (string) $this->level()->import_value);
        $this->assertSame('15.000', (string) $this->level()->quantity_on_hand);
    }

    public function test_issuing_everything_clears_the_import_value(): void
    {
        $this->mixed();

        $this->service->issue($this->item, $this->warehouse, '20', '2026-03-10', $this->userId);

        $this->assertSame('0.000000', (string) $this->level()->import_value);
    }

    public function test_reversing_a_lone_import_receipt_takes_back_exactly_its_import_value(): void
    {
        $this->service->receipt($this->item, $this->warehouse, '10', '20.00', '2026-03-02', $this->userId, '200.000000');

        $this->service->issue($this->item, $this->warehouse, '10', '2026-03-10', $this->userId, '200.000000');

        $this->assertSame('0.000000', (string) $this->level()->import_value);
    }

    public function test_an_explicit_import_part_never_exceeds_the_value_that_leaves(): void
    {
        $this->mixed();

        // излегуваат 10 × 15 = 150; барани се 200 од увоз → се ограничува на 150
        $issue = $this->service->issue($this->item, $this->warehouse, '10', '2026-03-10', $this->userId, '200.000000');

        $this->assertSame('150.000000', (string) $issue->import_value);
        $this->assertSame('50.000000', (string) $this->level()->import_value);
    }

    public function test_a_transfer_moves_the_import_share_with_the_goods(): void
    {
        $this->mixed();
        $other = Warehouse::factory()->for($this->company)->create();

        $transfer = $this->service->transfer($this->item, $this->warehouse, $other, '5', '2026-03-10', $this->userId);

        $this->assertSame('50.000000', (string) $transfer->import_value);
        $this->assertSame('150.000000', (string) $this->level()->import_value);
        $this->assertSame('50.000000', (string) $this->level($other)->import_value);
    }

    public function test_an_adjustment_keeps_the_share(): void
    {
        $this->mixed();

        $this->service->adjustment($this->item, $this->warehouse, '-5', 'расипано', '2026-03-10', $this->userId);

        // 15 од 20 останува → 200 × 15/20 = 150
        $this->assertSame('150.000000', (string) $this->level()->import_value);
    }
}
