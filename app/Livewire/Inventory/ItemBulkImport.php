<?php

namespace App\Livewire\Inventory;

use App\Imports\ItemsImport;
use App\Models\Company;
use App\Models\Item;
use App\Services\Inventory\ItemImportParser;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Layout;
use Livewire\Component;
use Livewire\WithFileUploads;
use Maatwebsite\Excel\Facades\Excel;

#[Layout('layouts.app')]
class ItemBulkImport extends Component
{
    use WithFileUploads;

    public Company $company;

    public $importFile = null;

    public array $parsedRows = [];

    public ?string $summary = null;

    public function mount(Company $company): void
    {
        Gate::authorize('view', $company);
        $this->company = $company;
    }

    public function preview(): void
    {
        Gate::authorize('create', Item::class);

        $this->validate(['importFile' => 'required|file|max:5120']);

        try {
            $sheets = Excel::toArray(new ItemsImport(), $this->importFile);
        } catch (\Throwable $e) {
            $this->addError('importFile', 'Фајлот не можеше да се прочита како табела (.xlsx или .csv). Проверете дека е точниот формат.');
            $this->importFile = null;

            return;
        }

        $rows = $sheets[0] ?? [];

        $this->parsedRows = app(ItemImportParser::class)->parse($rows, $this->company->id);
        $this->summary = null;
        $this->importFile = null;
    }

    public function confirmImport(): void
    {
        Gate::authorize('create', Item::class);

        $created = 0;
        $updated = 0;
        $skipped = 0;

        DB::transaction(function () use (&$created, &$updated, &$skipped): void {
            foreach ($this->parsedRows as $row) {
                if ($row['action'] === 'error') {
                    $skipped++;

                    continue;
                }

                if ($row['action'] === 'new') {
                    Item::create([
                        'company_id' => $this->company->id,
                        'code' => $row['code'],
                        'name' => $row['name'],
                        'unit_of_measure' => $row['unit_of_measure'],
                        'category' => $row['category'],
                        'vat_rate' => $row['vat_rate'],
                        'selling_price' => $row['selling_price'],
                        'type' => $row['type'],
                        'is_made_in_mk' => $row['is_made_in_mk'],
                        'barcode' => $row['barcode'],
                        'is_active' => true,
                    ]);
                    $created++;

                    continue;
                }

                $item = Item::findOrFail($row['existing_item_id']);
                Gate::authorize('update', $item);

                $updateData = ['name' => $row['name']];

                if ($row['unit_of_measure'] !== null) {
                    $updateData['unit_of_measure'] = $row['unit_of_measure'];
                }
                if ($row['category_provided']) {
                    $updateData['category'] = $row['category'];
                }
                if ($row['vat_rate'] !== null) {
                    $updateData['vat_rate'] = $row['vat_rate'];
                }
                if ($row['selling_price_provided']) {
                    $updateData['selling_price'] = $row['selling_price'];
                }
                if ($row['type'] !== null) {
                    $updateData['type'] = $row['type'];
                }
                if ($row['is_made_in_mk'] !== null) {
                    $updateData['is_made_in_mk'] = $row['is_made_in_mk'];
                }
                if ($row['barcode_provided']) {
                    $updateData['barcode'] = $row['barcode'];
                }

                $item->update($updateData);
                $updated++;
            }
        });

        $this->summary = "{$created} додадени, {$updated} ажурирани, {$skipped} прескокнати.";
        $this->parsedRows = [];
    }

    public function render()
    {
        return view('livewire.inventory.item-bulk-import');
    }
}
