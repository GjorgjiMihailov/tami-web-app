<?php

namespace App\Services;

use App\Models\ExchangeRate;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;

class ExchangeRateService
{
    public function getRate(string $currencyCode, Carbon $date): float
    {
        $currencyCode = strtoupper($currencyCode);

        if ($currencyCode === 'MKD') {
            return 1.0;
        }

        $cached = ExchangeRate::where('rate_date', $date->toDateString())
            ->where('currency_code', $currencyCode)
            ->first();

        if ($cached) {
            return (float) $cached->rate;
        }

        $formatted = $date->format('d.m.Y');

        $response = Http::get('https://www.nbrm.mk/KLServiceNOV/GetExchangeRate', [
            'StartDate' => $formatted,
            'EndDate' => $formatted,
            'format' => 'json',
        ])->throw();

        $entry = collect($response->json())->first(fn (array $row) => $row['oznaka'] === $currencyCode);

        if (! $entry) {
            throw new \RuntimeException("No NBRM exchange rate found for {$currencyCode} on {$formatted}.");
        }

        $rate = (float) $entry['sreden'] / (float) $entry['nomin'];

        ExchangeRate::create([
            'rate_date' => $date->toDateString(),
            'currency_code' => $currencyCode,
            'rate' => $rate,
        ]);

        return $rate;
    }
}
