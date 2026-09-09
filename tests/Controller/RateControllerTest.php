<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\DTO\Rate;
use App\DTO\RateCollection;
use App\Exception\RatesUnavailableException;
use App\Service\CurrencyConverter;
use App\Service\RateStorage;
use PHPUnit\Framework\Attributes\DataProvider;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Filesystem\Filesystem;

final class RateControllerTest extends WebTestCase
{
    private KernelBrowser $client;
    private Filesystem $filesystem;
    private string $filePath;
    private ?string $previousBaseCurrency;

    protected function setUp(): void
    {
        parent::setUp();
        $this->previousBaseCurrency = $_ENV['DEFAULT_BASE_CURRENCY'] ?? null;
        $_ENV['DEFAULT_BASE_CURRENCY'] = 'USD';
        $this->filesystem = new Filesystem();
        $this->filePath = $this->filesystem->tempnam(sys_get_temp_dir(), 'rate-controller-test-');
        $storage = new RateStorage($this->filePath, $this->filesystem, 'USD');
        $storage->save(new RateCollection([
            new Rate('USD', '1'),
            new Rate('EUR', '0.8'),
            new Rate('JPY', '160'),
        ]));

        $this->client = static::createClient();
        static::getContainer()->set(RateStorage::class, $storage);
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        $this->filesystem->remove($this->filePath);
        if (null === $this->previousBaseCurrency) {
            unset($_ENV['DEFAULT_BASE_CURRENCY']);
        } else {
            $_ENV['DEFAULT_BASE_CURRENCY'] = $this->previousBaseCurrency;
        }
    }

    public function testRatesWithoutQueryUsesDefaultBase(): void
    {
        $this->client->request('GET', '/api/rates');

        self::assertResponseIsSuccessful();
        self::assertResponseHeaderSame('Content-Type', 'application/json');
        self::assertSame([
            ['rate' => 0.8, 'code' => 'EUR'],
            ['rate' => 160, 'code' => 'JPY'],
            ['rate' => 1, 'code' => 'USD'],
        ], json_decode($this->client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR));
    }

    public function testRatesMapsAndValidatesBaseCurrency(): void
    {
        $this->client->request('GET', '/api/rates', ['base' => ' eur ']);

        self::assertResponseIsSuccessful();
        self::assertSame([
            ['rate' => 1, 'code' => 'EUR'],
            ['rate' => 200, 'code' => 'JPY'],
            ['rate' => 1.25, 'code' => 'USD'],
        ], json_decode($this->client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR));
    }

    public function testConvertMapsQueryToRequest(): void
    {
        $this->client->request('GET', '/api/convert', ['amount' => '10', 'from' => ' eur ', 'to' => 'jpy']);

        self::assertResponseIsSuccessful();
        self::assertSame([
            'amount' => 2000,
            'currency_from' => ['rate' => 0.005, 'code' => 'EUR'],
            'currency_to' => ['rate' => 1, 'code' => 'JPY'],
        ], json_decode($this->client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR));
    }

    public function testConvertSerializesFractionalAmountAsJsonNumber(): void
    {
        $this->client->request('GET', '/api/convert', ['amount' => '1.25', 'from' => 'EUR', 'to' => 'USD']);

        self::assertResponseIsSuccessful();
        self::assertSame([
            'amount' => 1.5625,
            'currency_from' => ['rate' => 0.8, 'code' => 'EUR'],
            'currency_to' => ['rate' => 1, 'code' => 'USD'],
        ], json_decode($this->client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR));
    }

    public function testServiceRejectingBaseCurrencyReturnsErrorResponse(): void
    {
        $storage = new RateStorage($this->filePath, $this->filesystem, 'USD');
        static::getContainer()->set(CurrencyConverter::class, new CurrencyConverter($storage, 'XYZ'));
        $this->client->catchExceptions(false);

        $this->client->request('GET', '/api/rates');

        self::assertResponseStatusCodeSame(400);
        self::assertSame([
            'success' => false,
            'error' => 'Unknown currency: XYZ.',
        ], json_decode($this->client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR));
    }

    #[DataProvider('serviceRequests')]
    public function testUnavailableRatesReturnErrorResponse(string $path, array $query): void
    {
        $storage = new RateStorage($this->filePath.'.missing', $this->filesystem, 'USD');
        static::getContainer()->set(CurrencyConverter::class, new CurrencyConverter($storage, 'USD'));
        $this->client->catchExceptions(false);

        $this->client->request('GET', $path, $query);

        self::assertResponseStatusCodeSame(503);
        self::assertSame([
            'success' => false,
            'error' => 'Rates are currently unavailable.',
        ], json_decode($this->client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR));
    }

    /** @param array<string, string> $query */
    #[DataProvider('serviceRequests')]
    public function testUnexpectedServiceFailureDoesNotExposeInternalDetails(string $path, array $query): void
    {
        $storage = new RateStorage($this->filePath."\0", $this->filesystem, 'USD');
        static::getContainer()->set(CurrencyConverter::class, new CurrencyConverter($storage, 'USD'));
        $this->client->catchExceptions(false);

        $this->client->request('GET', $path, $query);

        self::assertResponseStatusCodeSame(500);
        self::assertSame([
            'success' => false,
            'error' => 'Internal server error.',
        ], json_decode($this->client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR));
    }

    public static function serviceRequests(): iterable
    {
        yield 'rates' => ['/api/rates', []];
        yield 'convert' => ['/api/convert', ['amount' => '10', 'from' => 'EUR', 'to' => 'JPY']];
    }

    #[DataProvider('unavailableSnapshots')]
    public function testUnavailableRatesDuringValidationReturnPublicError(string $path, array $query, ?string $snapshot): void
    {
        if (null === $snapshot) {
            $this->filesystem->remove($this->filePath);
        } else {
            $this->filesystem->dumpFile($this->filePath, $snapshot);
        }

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())
            ->method('error')
            ->with('Rate API request failed.', self::callback(
                static fn (array $context): bool => ($context['exception'] ?? null) instanceof RatesUnavailableException,
            ));
        static::getContainer()->set('logger', $logger);

        $this->client->request('GET', $path, $query);

        self::assertResponseStatusCodeSame(503);
        self::assertResponseHeaderSame('Content-Type', 'application/json');
        self::assertSame([
            'success' => false,
            'error' => 'Rates are currently unavailable.',
        ], json_decode($this->client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR));
    }

    public static function unavailableSnapshots(): iterable
    {
        foreach (['missing' => null, 'invalid' => '{invalid json'] as $state => $snapshot) {
            yield $state.' rates' => ['/api/rates', ['base' => 'USD'], $snapshot];
            yield $state.' convert' => ['/api/convert', ['amount' => '10', 'from' => 'USD', 'to' => 'EUR'], $snapshot];
        }
    }

    #[DataProvider('invalidQueries')]
    public function testInvalidQueryReturnsBadRequest(string $path, array $query, array $fields): void
    {
        $this->client->request('GET', $path, $query);

        self::assertResponseStatusCodeSame(400);
        self::assertResponseHeaderSame('Content-Type', 'application/json');
        $response = json_decode($this->client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame(['success', 'error', 'errors'], array_keys($response));
        self::assertFalse($response['success']);
        self::assertSame('Validation failed.', $response['error']);
        self::assertSame($fields, array_keys($response['errors']));
        foreach ($response['errors'] as $messages) {
            self::assertIsArray($messages);
            self::assertNotEmpty($messages);
            foreach ($messages as $message) {
                self::assertIsString($message);
                self::assertNotSame('', $message);
            }
        }
    }

    public function testUnknownBaseReturnsOnlyPublicValidationDetails(): void
    {
        $this->client->request('GET', '/api/rates', ['base' => 'XXX']);

        self::assertResponseStatusCodeSame(400);
        self::assertSame([
            'success' => false,
            'error' => 'Validation failed.',
            'errors' => ['base' => ['Unknown currency: XXX.']],
        ], json_decode($this->client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR));
    }

    public function testMultipleInvalidCurrenciesReturnErrorsForBothFields(): void
    {
        $this->client->request('GET', '/api/convert', ['amount' => '1', 'from' => 'XXX', 'to' => 'YYY']);

        self::assertResponseStatusCodeSame(400);
        self::assertSame([
            'success' => false,
            'error' => 'Validation failed.',
            'errors' => [
                'from' => ['Unknown currency: XXX.'],
                'to' => ['Unknown currency: YYY.'],
            ],
        ], json_decode($this->client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR));
    }

    public static function invalidQueries(): iterable
    {
        yield 'unknown base' => ['/api/rates', ['base' => 'XYZ'], ['base']];
        yield 'invalid base syntax' => ['/api/rates', ['base' => 'US/D'], ['base']];
        yield 'empty base' => ['/api/rates', ['base' => ''], ['base']];
        yield 'base is array' => ['/api/rates', ['base' => ['USD']], ['base']];
        yield 'empty conversion' => ['/api/convert', [], ['amount', 'from', 'to']];
        yield 'missing amount' => ['/api/convert', ['from' => 'USD', 'to' => 'EUR'], ['amount']];
        yield 'invalid amount' => ['/api/convert', ['amount' => 'NaN', 'from' => 'USD', 'to' => 'EUR'], ['amount']];
        yield 'negative amount' => ['/api/convert', ['amount' => '-1', 'from' => 'USD', 'to' => 'EUR'], ['amount']];
        yield 'unknown source' => ['/api/convert', ['amount' => '1', 'from' => 'XYZ', 'to' => 'EUR'], ['from']];
        yield 'unknown target' => ['/api/convert', ['amount' => '1', 'from' => 'USD', 'to' => 'XYZ'], ['to']];
    }
}
