<?php

namespace App\Http\Controllers;

use App\Models\Company;
use App\Models\Warehouse;
use App\Services\Inventory\StockLevelQuery;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class StockOnHandPdfController extends Controller
{
    public function __invoke(Company $company, Request $request)
    {
        Gate::authorize('view', $company);

        $warehouseId = $request->integer('warehouseId') ?: null;

        if ($warehouseId) {
            $warehouseName = Warehouse::where('company_id', $company->id)->findOrFail($warehouseId)->name;
            $rows = StockLevelQuery::stockOnHand($company, $warehouseId)->map(fn ($row) => [
                'item_code' => $row['item_code'],
                'item_name' => $row['item_name'],
                'quantity' => $row['quantity_on_hand'],
                'cost_value' => $row['value'],
                'selling_value' => $row['selling_value'],
            ]);
        } else {
            $warehouseName = null;
            $rows = StockLevelQuery::stockOnHandTotals($company)->map(fn ($row) => [
                'item_code' => $row['item_code'],
                'item_name' => $row['item_name'],
                'quantity' => $row['total_quantity'],
                'cost_value' => $row['total_value'],
                'selling_value' => $row['total_selling_value'],
            ]);
        }

        $pdf = Pdf::loadView('pdf.stock-on-hand', [
            'company' => $company,
            'rows' => $rows,
            'warehouseName' => $warehouseName,
        ]);

        return $pdf->download('zaliha.pdf');
    }
}
