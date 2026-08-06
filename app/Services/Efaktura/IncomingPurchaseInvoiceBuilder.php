<?php

namespace App\Services\Efaktura;

use App\Models\Company;
use App\Models\Partner;
use App\Models\PurchaseInvoice;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class IncomingPurchaseInvoiceBuilder
{
    public function build(Company $company, array $payload, User $decidedBy): PurchaseInvoice
    {
        $document = $payload['document'];
        $header = $document['header'];
        $seller = $document['seller'];
        $docPayment = $document['docPayment'] ?? [];

        return DB::transaction(function () use ($company, $document, $header, $seller, $docPayment, $decidedBy) {
            $partner = $this->findOrCreatePartner($company, $seller);

            $invoice = PurchaseInvoice::create([
                'company_id' => $company->id,
                'partner_id' => $partner->id,
                'warehouse_id' => null,
                'supplier_invoice_number' => $header['docNumber'],
                'invoice_date' => $header['docDate'],
                'due_date' => $docPayment['docPaymentTypeDueDate'] ?? $header['docDate'],
                'status' => 'draft',
                'notes' => null,
                'created_by' => $decidedBy->id,
            ]);

            foreach ($document['docItems'] as $item) {
                $invoice->lines()->create($this->buildLine($item));
            }

            return $invoice->fresh(['lines', 'partner']);
        });
    }

    private function findOrCreatePartner(Company $company, array $seller): Partner
    {
        // Strip a leading MK/МК typed directly into the УЈП-supplied tax id, same normalization
        // EfakturaDocumentBuilder::buildParty() applies for outgoing invoices — a supplier's own
        // registry data can carry the same dirty-data pattern this app already hit once.
        $incomingTaxId = preg_replace('/^(mk|мк)/iu', '', (string) ($seller['sellerTin'] ?? ''));

        // Check for existing partners, normalizing stored tax_ids as well to handle dirty data
        $partner = Partner::where('company_id', $company->id)->get()
            ->first(function ($p) use ($incomingTaxId) {
                $storedNormalized = preg_replace('/^(mk|мк)/iu', '', $p->tax_id);
                return $storedNormalized === $incomingTaxId;
            });

        if ($partner) {
            return $partner;
        }

        $address = $seller['sellerAddress'] ?? [];

        return Partner::create([
            'company_id' => $company->id,
            'name' => $seller['sellerName'] ?? $incomingTaxId,
            'type' => 'legal_entity',
            'tax_id' => $incomingTaxId,
            'is_vat_registered' => filled($seller['sellerVatNumber'] ?? null),
            'vat_number' => $seller['sellerVatNumber'] ?? null,
            'street_address' => $address['streetAddress'] ?? null,
            'street_number' => $address['streetNumber'] ?? null,
            'postal_code' => $address['postalCode'] ?? null,
            'city' => $address['city'] ?? null,
        ]);
    }

    private function buildLine(array $item): array
    {
        $code = $item['docItemTaxIndicator'] ?? null;
        $vatRate = $code ? EfakturaTaxIndicator::fromCode($code) : null;

        return [
            'item_id' => null,
            'account_id' => null,
            'description' => $item['docItemDesc'] ?? '',
            'quantity' => $item['docItemQty'] ?? 1,
            'unit_price' => $item['docItemUnitPriceWoVat'] ?? 0,
            'vat_rate' => $vatRate ?? '0.00',
            'vat_deductible' => true,
            'needs_review' => $vatRate === null,
        ];
    }
}
