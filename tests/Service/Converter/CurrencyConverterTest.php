<?php

declare(strict_types=1);

namespace App\Tests\Service\Converter;

use App\DTO\ConvertResult;
use App\DTO\Rate;
use App\DTO\RateCollection;
use App\DTO\RatesResult;
use App\Exception\UnknownCurrencyException;
use App\Service\CurrencyConverter;
use App\Service\RateStorage;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Filesystem\Filesystem;

final class CurrencyConverterTest extends KernelTestCase
{
    private Filesystem $filesystem;
    private string $filePath;
    private CurrencyConverter $converter;

    protected function setUp(): void
    {
        parent::setUp();
        $this->filesystem = new Filesystem();
        $this->filePath = $this->filesystem->tempnam(sys_get_temp_dir(), 'currency-converter-test-');
        $storage = new RateStorage($this->filePath, $this->filesystem, 'USD');
        $storage->save(new RateCollection([
            new Rate('USD', '1'),
            new Rate('EUR', '0.8'),
            new Rate('JPY', '160'),
            new Rate('BTC', '0.00002'),
        ]));
        $this->converter = new CurrencyConverter($storage, 'USD');
    }

    protected function tearDown(): void
    {
        $this->filesystem->remove($this->filePath);
        parent::tearDown();
    }

    public function testRatesReadRateCollection(): void
    {
        self::assertEquals(new RatesResult(
            base: 'USD',
            rates: new RateCollection([
                new Rate('BTC', '0.00002'),
                new Rate('EUR', '0.8'),
                new Rate('JPY', '160'),
                new Rate('USD', '1'),
            ]),
        ), $this->converter->rates());
    }

    public function testRatesRebaseAndNormalizeCurrencyCode(): void
    {
        self::assertEquals(new RatesResult(
            base: 'EUR',
            rates: new RateCollection([
                new Rate('BTC', '0.000025'),
                new Rate('EUR', '1'),
                new Rate('JPY', '200'),
                new Rate('USD', '1.25'),
            ]),
        ), $this->converter->rates(' eur '));
    }

    public function testUsesConfiguredDefaultBaseCurrency(): void
    {
        $storage = new RateStorage($this->filePath, $this->filesystem, 'EUR');
        $storage->save(new RateCollection([new Rate('EUR', '1'), new Rate('USD', '1.25')]));
        $converter = new CurrencyConverter($storage, 'EUR');

        self::assertEquals(new RatesResult(
            base: 'EUR',
            rates: new RateCollection([new Rate('EUR', '1'), new Rate('USD', '1.25')]),
        ), $converter->rates());
        self::assertSame('12.5', $converter->convert('10', 'EUR', 'USD')->amount);
    }

    public function testConversionReturnsRatesRelativeToTargetCurrency(): void
    {
        self::assertEquals(new ConvertResult(
            amount: '2000',
            from: new Rate('EUR', '0.005'),
            to: new Rate('JPY', '1'),
        ), $this->converter->convert('10', ' eur ', 'jpy'));
    }

    #[DataProvider('conversionCases')]
    public function testConvertsWithoutFloatingPointPrecisionLoss(string $amount, string $from, string $to, string $expected): void
    {
        self::assertSame($expected, $this->converter->convert($amount, $from, $to)->amount);
    }

    public static function conversionCases(): iterable
    {
        yield 'from storage base' => ['10', 'USD', 'EUR', '8'];
        yield 'to storage base' => ['10', 'EUR', 'USD', '12.5'];
        yield 'to crypto' => ['1', 'USD', 'BTC', '0.00002'];
        yield 'from crypto' => ['0.00002', 'BTC', 'USD', '1'];
        yield 'round half up at eight decimals' => ['1.234567895', 'JPY', 'JPY', '1.2345679'];
        yield 'large fractional amount' => ['9007199254740993.12345678', 'USD', 'EUR', '7205759403792794.49876542'];
        yield 'zero' => ['0', 'USD', 'EUR', '0'];
    }

    #[DataProvider('unknownCurrencyCases')]
    public function testRejectsUnknownCurrency(string $operation): void
    {
        $this->expectException(UnknownCurrencyException::class);
        $this->expectExceptionMessage('Unknown currency: XYZ.');

        match ($operation) {
            'base' => $this->converter->rates('xyz'),
            'source' => $this->converter->convert('1', 'xyz', 'USD'),
            'target' => $this->converter->convert('1', 'USD', 'xyz'),
            default => self::fail('Unknown test operation: '.$operation),
        };
    }

    public static function unknownCurrencyCases(): iterable
    {
        yield 'base' => ['base'];
        yield 'source' => ['source'];
        yield 'target' => ['target'];
    }

    #[DataProvider('invalidAmounts')]
    public function testRejectsInvalidAmount(string $amount): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->converter->convert($amount, 'USD', 'EUR');
    }

    public static function invalidAmounts(): iterable
    {
        yield 'negative' => ['-0.01'];
        yield 'very small negative' => ['-1e-100'];
        yield 'not numeric' => ['NaN'];
        yield 'empty' => [''];
    }

    public function testRejectsInvalidCurrencyCode(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->converter->rates('US/D');
    }
}
