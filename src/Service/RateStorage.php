<?php

declare(strict_types=1);

namespace App\Service;

use App\DTO\Rate;
use App\DTO\RateCollection;
use App\Exception\RatesUnavailableException;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Filesystem\Filesystem;

final readonly class RateStorage
{
    public function __construct(
        #[Autowire('%kernel.project_dir%/%env(RATES_FILE)%')]
        private string $filePath,
        private Filesystem $filesystem,
        #[Autowire(env: 'DEFAULT_BASE_CURRENCY')]
        private string $defaultBaseCurrency,
    ) {
    }

    public function save(RateCollection $rates, ?string $baseCurrency = null): void
    {
        $baseCurrency ??= $this->defaultBaseCurrency;
        $rates = $this->validate($rates, $baseCurrency);
        $snapshot = [];
        foreach ($rates->rates as $rate) {
            $snapshot[$rate->code] = $rate->rate;
        }
        ksort($snapshot);
        $json = json_encode([
            'base' => $baseCurrency,
            'updated_at' => (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format(DATE_ATOM),
            'rates' => $snapshot,
        ], JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

        $this->filesystem->dumpFile($this->filePath, $json."\n");
    }

    public function load(): RateCollection
    {
        $json = @file_get_contents($this->filePath);
        if (false === $json) {
            throw new RatesUnavailableException('Rates are unavailable. Run app:rates:update first.');
        }

        try {
            $data = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
            if (!is_array($data) || !is_string($data['base'] ?? null) || !is_array($data['rates'] ?? null)) {
                throw new \InvalidArgumentException('Invalid rates file structure.');
            }

            $rates = [];
            foreach ($data['rates'] as $code => $rate) {
                if (!is_string($code) || !is_string($rate)) {
                    throw new \InvalidArgumentException('Invalid currency or decimal rate in snapshot.');
                }

                $rates[] = new Rate($code, $rate);
            }

            return $this->validate(new RateCollection($rates), $data['base']);
        } catch (\JsonException|\InvalidArgumentException $exception) {
            throw new RatesUnavailableException('Rates file is invalid. Run app:rates:update again.', 0, $exception);
        }
    }

    private function validate(RateCollection $rates, string $baseCurrency): RateCollection
    {
        $validated = [];
        foreach ($rates->rates as $rate) {
            if (!$rate instanceof Rate || !preg_match('/\A[A-Z0-9]{1,16}\z/', $rate->code)) {
                throw new \InvalidArgumentException('Invalid currency or decimal rate in snapshot.');
            }
            $validated[$rate->code] = new Rate($rate->code, Decimal::positive($rate->rate));
        }

        if (($validated[$baseCurrency]->rate ?? null) !== '1' || count($validated) < 2) {
            throw new \InvalidArgumentException(sprintf('Snapshot must contain %s = 1 and at least one other currency.', $baseCurrency));
        }

        return new RateCollection(array_values($validated));
    }
}
