<?php

declare(strict_types=1);

namespace App\Tests\Service\Storage;

use App\DTO\Rate;
use App\DTO\RateCollection;
use App\DTO\RatesResult;
use App\Exception\RatesUnavailableException;
use App\Service\RateProvider\RateProviderInterface;
use App\Service\RateStorage;
use App\Service\RateUpdater;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Filesystem\Filesystem;

final class RateStorageTest extends KernelTestCase
{
    private Filesystem $filesystem;
    private string $filePath;
    private RateStorage $storage;

    protected function setUp(): void
    {
        parent::setUp();
        $this->filesystem = new Filesystem();
        $this->filePath = $this->filesystem->tempnam(sys_get_temp_dir(), 'rate-storage-test-');
        $this->storage = new RateStorage($this->filePath, $this->filesystem, 'USD');
    }

    protected function tearDown(): void
    {
        $this->filesystem->remove($this->filePath);
        parent::tearDown();
    }

    public function testSavesAndLoadsRateCollectionWithoutLosingPrecision(): void
    {
        $usd = new Rate('USD', '1');
        $eur = new Rate('EUR', '0.91234567890123456789');
        $btc = new Rate('BTC', '0.000025');

        $this->storage->save(new RateCollection([$usd, $eur, $btc]));

        self::assertEquals(new RateCollection([$btc, $eur, $usd]), $this->storage->load());

        $snapshot = json_decode(file_get_contents($this->filePath), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('USD', $snapshot['base']);
        self::assertMatchesRegularExpression('/\A\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}\+00:00\z/', $snapshot['updated_at']);
        self::assertSame([
            'BTC' => '0.000025',
            'EUR' => '0.91234567890123456789',
            'USD' => '1',
        ], $snapshot['rates']);
    }

    public function testLoadsExistingSnapshotAsRateCollection(): void
    {
        $this->filesystem->dumpFile($this->filePath, '{"base":"USD","updated_at":"2026-09-09T00:00:00+00:00","rates":{"EUR":"0.91234567890123456789","USD":"1"}}');

        self::assertEquals(new RateCollection([
            new Rate('EUR', '0.91234567890123456789'),
            new Rate('USD', '1'),
        ]), $this->storage->load());
    }

    public function testSavesAndLoadsRatesInTheConfiguredBaseCurrency(): void
    {
        $storage = new RateStorage($this->filePath, $this->filesystem, 'EUR');
        $rates = new RateCollection([
            new Rate('EUR', '1'),
            new Rate('USD', '1.1'),
        ]);

        $storage->save($rates);

        self::assertEquals($rates, $storage->load());
        $snapshot = json_decode(file_get_contents($this->filePath), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('EUR', $snapshot['base']);
        self::assertSame(['EUR' => '1', 'USD' => '1.1'], $snapshot['rates']);
    }

    public function testValidationMessageUsesTheConfiguredBaseCurrency(): void
    {
        $storage = new RateStorage($this->filePath, $this->filesystem, 'EUR');

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Snapshot must contain EUR = 1 and at least one other currency.');

        $storage->save(new RateCollection([]));
    }

    public function testInvalidCollectionDoesNotOverwriteSnapshot(): void
    {
        $snapshot = '{"base":"USD","rates":{"EUR":"0.9","USD":"1"}}';
        $this->filesystem->dumpFile($this->filePath, $snapshot);

        $this->expectException(\InvalidArgumentException::class);

        try {
            $this->storage->save(new RateCollection([]));
        } finally {
            self::assertSame($snapshot, file_get_contents($this->filePath));
        }
    }

    public function testUpdaterSavesRateDtosAndKeepsFirstProviderRates(): void
    {
        $first = $this->createStub(RateProviderInterface::class);
        $first->method('fetch')->willReturn(new RateCollection([
            new Rate('EUR', '0.9'),
            new Rate('USD', '2'),
        ]));
        $second = $this->createStub(RateProviderInterface::class);
        $second->method('fetch')->willReturn(new RateCollection([
            new Rate('EUR', '0.8'),
            new Rate('BTC', '0.000025'),
        ]));

        $updater = new RateUpdater([$first, $second], $this->storage);

        self::assertSame(3, $updater->update('USD'));
        self::assertEquals(new RateCollection([
            new Rate('BTC', '0.000025'),
            new Rate('EUR', '0.9'),
            new Rate('USD', '1'),
        ]), $this->storage->load());
    }

    public function testFailedProviderDoesNotSavePartialCollection(): void
    {
        $snapshot = '{"base":"USD","rates":{"EUR":"0.9","USD":"1"}}';
        $this->filesystem->dumpFile($this->filePath, $snapshot);
        $first = $this->createStub(RateProviderInterface::class);
        $first->method('fetch')->willReturn(new RateCollection([new Rate('EUR', '0.8')]));
        $failed = $this->createStub(RateProviderInterface::class);
        $failed->method('fetch')->willThrowException(new \RuntimeException('Provider failed.'));

        $updater = new RateUpdater([$first, $failed], $this->storage);
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Provider failed.');

        try {
            $updater->update('USD');
        } finally {
            self::assertSame($snapshot, file_get_contents($this->filePath));
        }
    }

    public function testSnapshotMustContainUnitRateForItsDeclaredBase(): void
    {
        $this->filesystem->dumpFile($this->filePath, '{"base":"EUR","rates":{"USD":"1","EUR":"100"}}');

        $this->expectException(RatesUnavailableException::class);

        $this->storage->load();
    }

    public function testSerializerNormalizesCollectionsAsListsIncludingInsideResults(): void
    {
        $serializer = static::getContainer()->get('serializer');
        $rates = new RateCollection([2 => new Rate('EUR', '0.125')]);

        self::assertSame('[{"rate":0.125,"code":"EUR"}]', $serializer->serialize($rates, 'json'));
        self::assertSame('[]', $serializer->serialize(new RateCollection([]), 'json'));
        self::assertSame(
            '{"base":"USD","rates":[{"rate":0.125,"code":"EUR"}]}',
            $serializer->serialize(new RatesResult('USD', $rates), 'json'),
        );
    }
}
