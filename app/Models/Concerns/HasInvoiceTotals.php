<?php

namespace App\Models\Concerns;

trait HasInvoiceTotals
{
    public function subtotal(): string
    {
        return $this->lines->reduce(fn ($carry, $line) => bcadd($carry, $line->lineTotal(), 2), '0.00');
    }

    public function vatTotal(): string
    {
        return $this->lines->reduce(fn ($carry, $line) => bcadd($carry, $line->vatAmount(), 2), '0.00');
    }

    public function grandTotal(): string
    {
        return bcadd($this->subtotal(), $this->vatTotal(), 2);
    }

    public function paidTotal(): string
    {
        return $this->payments->reduce(fn ($carry, $payment) => bcadd($carry, (string) $payment->amount, 2), '0.00');
    }

    public function balanceDue(): string
    {
        return bcsub($this->grandTotal(), $this->paidTotal(), 2);
    }

    public function paymentStatus(): string
    {
        if ($this->status !== 'confirmed') {
            return 'n/a';
        }

        $paid = $this->paidTotal();

        if (bccomp($paid, '0', 2) <= 0) {
            return 'unpaid';
        }

        if (bccomp($paid, $this->grandTotal(), 2) >= 0) {
            return 'paid';
        }

        return 'partially_paid';
    }

    public function isOverdue(): bool
    {
        return in_array($this->paymentStatus(), ['unpaid', 'partially_paid'], true)
            && $this->due_date->isPast();
    }
}
