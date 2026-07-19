<?php

namespace Tests\Unit;

use App\Models\ExchangeRate;
use App\Services\ExchangeRateService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ExchangeRateServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_mkd_always_returns_a_rate_of_one(): void
    {
        $service = new ExchangeRateService();

        $this->assertSame(1.0, $service->getRate('MKD', Carbon::parse('2026-07-01')));
    }

    public function test_fetches_and_caches_a_rate_from_nbrm(): void
    {
        Http::fake([
            'nbrm.mk/*' => Http::response([
                ['oznaka' => 'EUR', 'sreden' => 61.6917, 'nomin' => 1, 'datum' => '2026-07-01T00:00:00'],
                ['oznaka' => 'USD', 'sreden' => 54.144, 'nomin' => 1, 'datum' => '2026-07-01T00:00:00'],
            ], 200),
        ]);

        $service = new ExchangeRateService();
        $rate = $service->getRate('EUR', Carbon::parse('2026-07-01'));

        $this->assertSame(61.6917, $rate);
        $this->assertDatabaseHas('exchange_rates', [
            'rate_date' => '2026-07-01',
            'currency_code' => 'EUR',
            'rate' => 61.6917,
        ]);
    }

    public function test_uses_the_cached_rate_without_calling_nbrm_again(): void
    {
        ExchangeRate::create(['rate_date' => '2026-07-01', 'currency_code' => 'EUR', 'rate' => 61.5]);

        Http::fake([
            'nbrm.mk/*' => Http::response('should not be called', 500),
        ]);

        $service = new ExchangeRateService();
        $rate = $service->getRate('eur', Carbon::parse('2026-07-01'));

        $this->assertSame(61.5, $rate);
        Http::assertNothingSent();
    }

    public function test_throws_when_nbrm_has_no_rate_for_the_requested_currency(): void
    {
        Http::fake([
            'nbrm.mk/*' => Http::response([
                ['oznaka' => 'USD', 'sreden' => 54.144, 'nomin' => 1, 'datum' => '2026-07-01T00:00:00'],
            ], 200),
        ]);

        $service = new ExchangeRateService();

        $this->expectException(\RuntimeException::class);

        $service->getRate('EUR', Carbon::parse('2026-07-01'));
    }
}
