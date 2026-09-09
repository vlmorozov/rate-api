<?php

declare(strict_types=1);

namespace App\Tests\Service\RateProvider;

use App\DTO\Rate;
use App\DTO\RateCollection;
use App\Service\Downloader\Downloader;
use App\Service\JsonDecimalDecoder;
use App\Service\RateProvider\CoinPaprikaProvider;
use App\Service\RateProvider\FloatRatesProvider;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;

final class RateProviderTest extends KernelTestCase
{
    #[DataProvider('floatRatesResponses')]
    public function testFloatRatesReturnsCurrencyDtosAndPreservesPrecision(string $payload): void
    {
        $provider = new FloatRatesProvider(
            new Downloader($this->createHttpClient($payload)),
            new JsonDecimalDecoder(),
            'https://provider.example/rates',
        );

        self::assertEquals(new RateCollection([
            new Rate('EUR', '0.91234567890123456789'),
            new Rate('JPY', '150'),
        ]), $provider->fetch('USD'));
    }

    public static function floatRatesResponses(): iterable
    {
        yield 'currency-keyed object with JSON numbers' => ['{"eur":{"code":"EUR","rate":0.91234567890123456789},"jpy":{"code":"JPY","rate":150}}'];
        yield 'list with duplicate currency' => ['[{"code":"EUR","rate":"0.9"},{"code":"JPY","rate":"150"},{"code":"EUR","rate":"0.91234567890123456789"}]'];
    }

    public function testFloatRatesRejectsInvalidCurrencyRateInObjectResponse(): void
    {
        $provider = new FloatRatesProvider(
            new Downloader($this->createHttpClient('{"eur":{"code":"EUR","rate":null}}')),
            new JsonDecimalDecoder(),
            'https://provider.example/rates',
        );

        $this->expectException(\UnexpectedValueException::class);
        $this->expectExceptionMessage('FloatRates returned an invalid currency rate.');

        $provider->fetch('USD');
    }

    public function testCoinPaprikaReturnsOneDtoPerCurrencyUsingPreferredMarket(): void
    {
        $provider = new CoinPaprikaProvider(
            new Downloader($this->createHttpClient(<<<'JSON'
                [
                    {"category":"Spot","outlier":false,"pair":"BTC/USDT","quotes":{"USD":{"price":"10000","volume_24h":"10000"}}},
                    {"category":"Spot","outlier":false,"pair":"BTC/USD","quotes":{"USD":{"price":"20000","volume_24h":"100"}}},
                    {"category":"Spot","outlier":false,"pair":"BTC/USD","quotes":{"USD":{"price":"40000","volume_24h":"200"}}},
                    {"category":"Spot","outlier":false,"pair":"BTC/EUR","quotes":{"USD":{"price":"10000","volume_24h":"20000"}}},
                    {"category":"Spot","outlier":true,"pair":"BTC/USD","quotes":{"USD":{"price":"1","volume_24h":"30000"}}},
                    {"category":"Spot","outlier":false,"pair":"ETH/USDT","quotes":{"USD":{"price":"2000","volume_24h":"100"}}}
                ]
                JSON)),
            new JsonDecimalDecoder(),
            'https://provider.example/rates',
        );

        $result = $provider->fetch('USD');

        self::assertInstanceOf(RateCollection::class, $result);
        self::assertCount(2, $result->rates);
        self::assertInstanceOf(Rate::class, $result->rates[0]);
        self::assertSame('BTC', $result->rates[0]->code);
        self::assertSame(0, bccomp('0.000025', $result->rates[0]->rate, 24));
        self::assertInstanceOf(Rate::class, $result->rates[1]);
        self::assertSame('ETH', $result->rates[1]->code);
        self::assertSame(0, bccomp('0.0005', $result->rates[1]->rate, 24));
    }

    public function testCoinPaprikaUsesRequestedCurrencyForQuotesAndMarketSelection(): void
    {
        $provider = new CoinPaprikaProvider(
            new Downloader($this->createHttpClient(<<<'JSON'
                [
                    {"category":"Spot","outlier":false,"pair":"BTC/USD","quotes":{"EUR":{"price":5000000,"volume_24h":900},"USD":{"price":60000,"volume_24h":10}}},
                    {"category":"Spot","outlier":false,"pair":"BTC/EUR","quotes":{"EUR":{"price":4000000,"volume_24h":100}}},
                    {"category":"Spot","outlier":false,"pair":"BTC/EUR","quotes":{"EUR":{"price":2000000,"volume_24h":50}}},
                    {"category":"Spot","outlier":false,"pair":"ETH/USD","quotes":{"EUR":{"price":200000,"volume_24h":100},"USD":{"price":2000,"volume_24h":900}}},
                    {"category":"Spot","outlier":false,"pair":"ETH/GBP","quotes":{"EUR":{"price":250000,"volume_24h":200},"USD":{"price":2500,"volume_24h":50}}},
                    {"category":"Spot","outlier":false,"pair":"EUR/USD","quotes":{"EUR":{"price":1,"volume_24h":100}}}
                ]
                JSON, 'https://provider.example/rates?quotes=EUR')),
            new JsonDecimalDecoder(),
            'https://provider.example/rates?quotes=%s',
        );

        self::assertEquals(new RateCollection([
            new Rate('BTC', '0.00000025'),
            new Rate('ETH', '0.000004'),
        ]), $provider->fetch('eur'));
    }

    public function testCoinPaprikaRejectsMissingRequestedQuoteWithoutUsingUsd(): void
    {
        $provider = new CoinPaprikaProvider(
            new Downloader($this->createHttpClient('[{"category":"Spot","outlier":false,"pair":"BTC/USD","quotes":{"USD":{"price":40000,"volume_24h":100}}}]')),
            new JsonDecimalDecoder(),
            'https://provider.example/rates',
        );

        $this->expectException(\UnexpectedValueException::class);
        $this->expectExceptionMessage('CoinPaprika returned an invalid EUR quote.');

        $provider->fetch('EUR');
    }

    #[DataProvider('emptyProviders')]
    public function testEmptyResponsesAreStillRejected(string $providerClass, string $message, string $payload): void
    {
        $provider = new $providerClass(
            new Downloader($this->createHttpClient($payload)),
            new JsonDecimalDecoder(),
            'https://provider.example/rates',
        );

        $this->expectException(\UnexpectedValueException::class);
        $this->expectExceptionMessage($message);

        $provider->fetch('USD');
    }

    public static function emptyProviders(): iterable
    {
        yield 'FloatRates object' => [FloatRatesProvider::class, 'FloatRates returned no rates.', '{}'];
        yield 'FloatRates list' => [FloatRatesProvider::class, 'FloatRates returned no rates.', '[]'];
        yield 'CoinPaprika' => [CoinPaprikaProvider::class, 'CoinPaprika returned no usable rates.', '[]'];
    }

    private function createHttpClient(string $payload, string $url = 'https://provider.example/rates'): HttpClientInterface
    {
        $response = $this->createStub(ResponseInterface::class);
        $response->method('getContent')->willReturn($payload);

        $client = $this->createMock(HttpClientInterface::class);
        $client->expects(self::once())
            ->method('request')
            ->with('GET', $url)
            ->willReturn($response);

        return $client;
    }
}
