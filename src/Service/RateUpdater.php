<?php

declare(strict_types=1);

namespace App\Service;

use App\DTO\Rate;
use App\DTO\RateCollection;
use App\Service\RateProvider\RateProviderInterface;
use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;

final readonly class RateUpdater
{
    /** @param iterable<RateProviderInterface> $providers */
    public function __construct(
        #[AutowireIterator('app.rate_provider')]
        private iterable $providers,
        private RateStorage $storage,
    ) {
    }

    public function update(string $baseCurrency): int
    {
        $rates = [$baseCurrency => new Rate($baseCurrency, '1')];
        foreach ($this->providers as $provider) {
            try {
                $provided = $provider->fetch($baseCurrency);
                if ([] === $provided->rates) {
                    throw new \RuntimeException('Provider returned no rates.');
                }
                foreach ($provided->rates as $rate) {
                    $rates[$rate->code] ??= $rate;
                }
            } catch (\Throwable $exception) {
                throw new \RuntimeException($provider::class.': '.$exception->getMessage(), 0, $exception);
            }
        }

        $this->storage->save(new RateCollection(array_values($rates)), $baseCurrency);

        return count($rates);
    }
}
