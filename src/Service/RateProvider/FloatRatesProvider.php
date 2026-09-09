<?php

declare(strict_types=1);

namespace App\Service\RateProvider;

use App\DTO\Rate;
use App\DTO\RateCollection;
use App\Service\Decimal;
use App\Service\Downloader\DownloaderInterface;
use App\Service\JsonDecimalDecoder;
use Symfony\Component\DependencyInjection\Attribute\AsTaggedItem;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

#[AsTaggedItem(priority: 100)]
final readonly class FloatRatesProvider implements RateProviderInterface
{
    public function __construct(
        private DownloaderInterface $downloader,
        private JsonDecimalDecoder $decoder,
        #[Autowire(env: 'FIATS_URL')]
        private string $url,
    ) {
    }

    public function fetch(string $baseCurrency): RateCollection
    {
        $url = sprintf($this->url, strtolower($baseCurrency));
        $content = $this->downloader->download($url);
        $data = $this->decoder->decode($content);
        $rates = [];

        foreach ($data as $row) {
            if (
                !is_array($row)
                || !is_string($row['code'] ?? null)
                || !preg_match('/\A[A-Z]{3}\z/', $row['code'])
                || !is_string($row['rate'] ?? null)
            ) {
                throw new \UnexpectedValueException('FloatRates returned an invalid currency rate.');
            }

            $rates[$row['code']] = new Rate($row['code'], Decimal::positive($row['rate']));
        }

        if ([] === $rates) {
            throw new \UnexpectedValueException('FloatRates returned no rates.');
        }

        return new RateCollection(array_values($rates));
    }
}
