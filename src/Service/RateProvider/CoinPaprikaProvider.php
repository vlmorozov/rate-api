<?php

declare(strict_types=1);

namespace App\Service\RateProvider;

use App\DTO\Rate;
use App\DTO\RateCollection;
use App\Service\Decimal;
use App\Service\Downloader\DownloaderInterface;
use App\Service\JsonDecimalDecoder;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

final readonly class CoinPaprikaProvider implements RateProviderInterface
{
    public function __construct(
        private DownloaderInterface $downloader,
        private JsonDecimalDecoder $decoder,
        #[Autowire(env: 'CRYPTO_URL')]
        private string $url,
    ) {
    }

    public function fetch(string $baseCurrency): RateCollection
    {
        $baseCurrency = strtoupper($baseCurrency);
        $url = sprintf($this->url, $baseCurrency);
        $content = $this->downloader->download($url);
        $data = $this->decoder->decode($content);
        if (!array_is_list($data)) {
            throw new \UnexpectedValueException('CoinPaprika returned an invalid markets list.');
        }

        $rates = [];
        $markets = [];
        foreach ($data as $market) {
            if (!is_array($market)) {
                throw new \UnexpectedValueException('CoinPaprika returned an invalid market.');
            }
            if (($market['category'] ?? null) !== 'Spot' || ($market['outlier'] ?? true) !== false) {
                continue;
            }
            if (
                !is_string($market['pair'] ?? null)
                || !preg_match('/\A([A-Z0-9]{1,16})\/([A-Z0-9]{1,16})\z/', $market['pair'], $pair)
                || !is_array($market['quotes'] ?? null)
                || !is_array($market['quotes'][$baseCurrency] ?? null)
                || !is_string($market['quotes'][$baseCurrency]['price'] ?? null)
                || !is_string($market['quotes'][$baseCurrency]['volume_24h'] ?? null)
            ) {
                throw new \UnexpectedValueException(sprintf('CoinPaprika returned an invalid %s quote.', $baseCurrency));
            }

            $code = $pair[1];
            if ($code === $baseCurrency) {
                continue;
            }
            $price = Decimal::positive($market['quotes'][$baseCurrency]['price']);
            $volume = Decimal::normalize($market['quotes'][$baseCurrency]['volume_24h']);
            $direct = $pair[2] === $baseCurrency;
            $previous = $markets[$code] ?? null;

            // Prefer a direct market in the requested currency; otherwise choose the most liquid quote.
            if (null !== $previous) {
                if ($previous['direct'] && !$direct) {
                    continue;
                }
                if ($previous['direct'] === $direct && bccomp($volume, $previous['volume'], Decimal::SCALE) <= 0) {
                    continue;
                }
            }

            $rates[$code] = new Rate($code, Decimal::positive(Decimal::divide('1', $price)));
            $markets[$code] = ['direct' => $direct, 'volume' => $volume];
        }

        if ([] === $rates) {
            throw new \UnexpectedValueException('CoinPaprika returned no usable rates.');
        }

        return new RateCollection(array_values($rates));
    }
}
