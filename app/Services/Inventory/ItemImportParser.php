<?php

namespace App\Services\Inventory;

use App\Models\Item;

class ItemImportParser
{
    public function parse(array $rows, int $companyId): array
    {
        $parsed = [];
        $codesSeenInFile = [];
        $barcodesSeenInFile = [];

        foreach ($rows as $index => $row) {
            if ($index === 0) {
                continue; // heading row
            }

            if ($this->isBlankRow($row)) {
                continue;
            }

            $rowNumber = $index + 1;
            $errors = [];

            $code = trim((string) ($row[0] ?? ''));
            $name = trim((string) ($row[1] ?? ''));
            $unitOfMeasureRaw = trim((string) ($row[2] ?? ''));
            $categoryRaw = trim((string) ($row[3] ?? ''));
            $vatRateRaw = trim((string) ($row[4] ?? ''));
            $sellingPriceRaw = trim((string) ($row[5] ?? ''));
            $typeRaw = trim((string) ($row[6] ?? ''));
            $madeInMkRaw = trim((string) ($row[7] ?? ''));
            $barcodeRaw = trim((string) ($row[8] ?? ''));

            if ($code === '') {
                $errors[] = 'Шифрата е задолжителна.';
            } elseif (isset($codesSeenInFile[$code])) {
                $errors[] = "Шифрата „{$code}“ се појавува повеќе пати во табелата.";
            }

            if ($name === '') {
                $errors[] = 'Називот е задолжителен.';
            }

            $existingItem = $code !== ''
                ? Item::where('company_id', $companyId)->where('code', $code)->first()
                : null;
            $action = $existingItem ? 'update' : 'new';

            $unitOfMeasure = $unitOfMeasureRaw !== '' ? $unitOfMeasureRaw : null;
            if ($unitOfMeasure === null && $action === 'new') {
                $errors[] = 'Мерната единица е задолжителна за нов артикл.';
            }

            $vatRate = null;
            if ($vatRateRaw !== '') {
                if (! is_numeric($vatRateRaw) || (float) $vatRateRaw < 0 || (float) $vatRateRaw > 100) {
                    $errors[] = 'Стапката на ДДВ мора да биде број од 0 до 100.';
                } else {
                    $vatRate = number_format((float) $vatRateRaw, 2, '.', '');
                }
            } elseif ($action === 'new') {
                $vatRate = '18.00';
            }

            $sellingPriceProvided = $sellingPriceRaw !== '';
            $sellingPrice = null;
            if ($sellingPriceProvided) {
                if (! is_numeric($sellingPriceRaw) || (float) $sellingPriceRaw < 0) {
                    $errors[] = 'Продажната цена мора да биде позитивен број.';
                } else {
                    $sellingPrice = number_format((float) $sellingPriceRaw, 2, '.', '');
                }
            }

            $type = null;
            if ($typeRaw !== '') {
                $normalized = mb_strtolower($typeRaw);
                if ($normalized === 'производ') {
                    $type = 'product';
                } elseif ($normalized === 'услуга') {
                    $type = 'service';
                } else {
                    $errors[] = "Невалидна вредност за тип: „{$typeRaw}“ (дозволено: производ, услуга, или празно).";
                }
            } elseif ($action === 'new') {
                $type = 'product';
            }

            $isMadeInMk = null;
            if ($madeInMkRaw !== '') {
                $normalized = mb_strtolower($madeInMkRaw);
                if ($normalized === 'да') {
                    $isMadeInMk = true;
                } elseif ($normalized === 'не') {
                    $isMadeInMk = false;
                } else {
                    $errors[] = "Невалидна вредност за МК-производство: „{$madeInMkRaw}“ (дозволено: Да, Не, или празно).";
                }
            } elseif ($action === 'new') {
                $isMadeInMk = false;
            }

            $barcodeProvided = $barcodeRaw !== '';
            if ($barcodeProvided) {
                if (isset($barcodesSeenInFile[$barcodeRaw])) {
                    $errors[] = "Баркодот „{$barcodeRaw}“ се појавува повеќе пати во табелата.";
                } else {
                    $conflict = Item::where('company_id', $companyId)
                        ->where('barcode', $barcodeRaw)
                        ->when($existingItem, fn ($q) => $q->whereKeyNot($existingItem->id))
                        ->first();

                    if ($conflict) {
                        $errors[] = "Баркодот „{$barcodeRaw}“ веќе е искористен кај артикл „{$conflict->code}“.";
                    }
                }
            }

            if ($code !== '') {
                $codesSeenInFile[$code] = true;
            }
            if ($barcodeProvided) {
                $barcodesSeenInFile[$barcodeRaw] = true;
            }

            $parsed[] = [
                'row_number' => $rowNumber,
                'action' => $errors === [] ? $action : 'error',
                'errors' => $errors,
                'code' => $code,
                'name' => $name !== '' ? $name : null,
                'unit_of_measure' => $unitOfMeasure,
                'category' => $categoryRaw !== '' ? $categoryRaw : null,
                'category_provided' => $categoryRaw !== '',
                'vat_rate' => $vatRate,
                'selling_price' => $sellingPrice,
                'selling_price_provided' => $sellingPriceProvided,
                'type' => $type,
                'is_made_in_mk' => $isMadeInMk,
                'barcode' => $barcodeProvided ? $barcodeRaw : null,
                'barcode_provided' => $barcodeProvided,
                'existing_item_id' => $existingItem?->id,
            ];
        }

        return $parsed;
    }

    private function isBlankRow(array $row): bool
    {
        foreach ($row as $cell) {
            if (trim((string) $cell) !== '') {
                return false;
            }
        }

        return true;
    }
}
