<?php

namespace Tests\Unit\Models;

use App\Models\PurchaseInvoiceLine;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PurchaseInvoiceLineNeedsReviewTest extends TestCase
{
    use RefreshDatabase;

    public function test_needs_review_defaults_to_false(): void
    {
        $line = PurchaseInvoiceLine::factory()->create();

        $this->assertFalse($line->fresh()->needs_review);
    }

    public function test_needs_review_can_be_set_true(): void
    {
        $line = PurchaseInvoiceLine::factory()->create(['needs_review' => true]);

        $this->assertTrue($line->fresh()->needs_review);
    }
}
