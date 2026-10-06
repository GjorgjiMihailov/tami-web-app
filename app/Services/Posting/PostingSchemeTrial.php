<?php

namespace App\Services\Posting;

use App\Models\Company;
use App\Models\PostingScheme;
use App\Models\PurchaseInvoice;
use App\Models\SalesInvoice;
use App\Support\Bcmath;
use App\Support\Posting\PostingContext;
use App\Support\Posting\PostingDocType;
use App\Support\Posting\PostingLine;
use Illuminate\Support\Collection;

/**
 * Пробно книжење: избрана вистинска фактура се пушта низ (работна) шема и се
 * покажуваат ставките што би настанале. Ништо не се запишува.
 */
class PostingSchemeTrial
{
    public function __construct(private PostingSchemeEngine $engine) {}

    /** @return Collection<int, array{id: int, label: string}> */
    public function documents(Company $company, PostingDocType $type): Collection
    {
        if ($this->isSales($type)) {
            return SalesInvoice::where('company_id', $company->id)->where('status', 'confirmed')
                ->with('partner')->orderByDesc('invoice_date')->orderByDesc('id')->limit(30)->get()
                ->map(fn (SalesInvoice $i) => ['id' => $i->id, 'label' => "{$i->invoice_number_formatted} — {$i->partner?->name} ({$i->invoice_date->format('d.m.Y')})"]);
        }

        return PurchaseInvoice::where('company_id', $company->id)->where('status', 'confirmed')
            ->with('partner')->orderByDesc('invoice_date')->orderByDesc('id')->limit(30)->get()
            ->map(fn (PurchaseInvoice $i) => ['id' => $i->id, 'label' => "{$i->partner?->name} #{$i->supplier_invoice_number} ({$i->invoice_date->format('d.m.Y')})"]);
    }

    /** @return list<PostingLine> */
    public function run(Company $company, PostingDocType $type, int $documentId, PostingScheme $scheme, bool $cash = false): array
    {
        return $this->engine->lines($scheme, $this->context($company, $type, $documentId, $cash));
    }

    private function context(Company $company, PostingDocType $type, int $documentId, bool $cash): PostingContext
    {
        if ($this->isSales($type)) {
            $invoice = SalesInvoice::where('company_id', $company->id)->with('lines.item', 'lines.stockMovement', 'company')->findOrFail($documentId);

            if ($type === PostingDocType::SALES_INVOICE) {
                return SalesInvoicePostingContext::build($invoice, (string) $invoice->invoice_number_formatted, $this->cogs($invoice));
            }

            $amount = $this->paymentAmount($invoice);
            $mkd = $invoice->isForeignCurrency() ? Bcmath::roundHalfUp(bcmul($amount, (string) $invoice->exchange_rate, 10), 2) : $amount;

            return SalesPaymentPostingContext::build($invoice, $mkd, $amount, $cash, "Payment for invoice {$invoice->invoice_number_formatted}", PostedInvoiceAccounts::receivable($invoice));
        }

        $invoice = PurchaseInvoice::where('company_id', $company->id)->with('lines.item', 'lines.account', 'partner', 'company', 'payments')->findOrFail($documentId);

        if ($type === PostingDocType::PURCHASE_INVOICE) {
            return PurchaseInvoicePostingContext::build($invoice);
        }

        return PurchasePaymentPostingContext::build(
            $invoice,
            $this->paymentAmount($invoice),
            $cash,
            "Payment for purchase bill {$invoice->partner->name} #{$invoice->supplier_invoice_number}",
            PostedInvoiceAccounts::payable($invoice)
        );
    }

    private function isSales(PostingDocType $type): bool
    {
        return in_array($type, [PostingDocType::SALES_INVOICE, PostingDocType::SALES_PAYMENT], true);
    }

    /** Преостаната сума за плаќање; ако е веќе платена — вкупниот износ (за да има што да се пробува). */
    private function paymentAmount(SalesInvoice|PurchaseInvoice $invoice): string
    {
        $invoice->loadMissing(['lines', 'payments']);
        $due = $invoice->balanceDue();

        return bccomp($due, '0', 2) > 0 ? $due : $invoice->grandTotal();
    }

    /** Набавна вредност на продадената стока: количина × цена на движењето, по ставка. */
    private function cogs(SalesInvoice $invoice): string
    {
        $total = '0.00';

        foreach ($invoice->lines as $line) {
            if ($line->stockMovement !== null) {
                $total = bcadd($total, Bcmath::roundHalfUp(bcmul((string) $line->quantity, (string) $line->stockMovement->unit_cost, 10), 2), 2);
            }
        }

        return $total;
    }
}
