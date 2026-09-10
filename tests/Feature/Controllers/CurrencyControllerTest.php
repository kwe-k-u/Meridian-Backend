<?php

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use App\Services\CurrencyService;

test('currency rates uses live wewire pair rates when available', function () {
    Cache::flush();
    Http::fake([
        'stage-capi.wewireafrica.com/v1/rates/pair*' => Http::sequence()
            ->push(['from' => 'USD', 'to' => 'GHS', 'bid' => 12.0, 'ask' => 12.2])
            ->push(['from' => 'EUR', 'to' => 'GHS', 'bid' => 13.0, 'ask' => 13.2])
            ->push(['from' => 'GBP', 'to' => 'GHS', 'bid' => 15.0, 'ask' => 15.2]),
    ]);

    $res = $this->getJson('/api/currency-rates');

    $res->assertOk()->assertJson([
        'base' => 'GHS',
        'rates' => ['GHS' => 1.0, 'USD' => 12.1, 'EUR' => 13.1, 'GBP' => 15.1],
    ]);
});

test('currency rates falls back to the hardcoded table when wewire is unreachable', function () {
    Cache::flush();
    Http::fake(function () {
        throw new \Illuminate\Http\Client\ConnectionException('connection refused');
    });

    $res = $this->getJson('/api/currency-rates');

    $res->assertOk()->assertJson([
        'base' => 'GHS',
        'rates' => CurrencyService::RATES,
    ]);
});
