<?php

declare(strict_types=1);

namespace App\Service;

use App\DTO\ConvertResult;
use App\DTO\Rate;
use App\DTO\RateCollection;
use App\DTO\RatesResult;
use App\Exception\UnknownCurrencyException;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

final readonly class CurrencyConverter
{
    public function __construct(
        private RateStorage $storage,
        #[Autowire(env: 'DEFAULT_BASE_CURRENCY')]
        private string $defaultBaseCurrency,
    ) {
    }

    public function rates(?string $base = null): RatesResult
    {
        $base = $this->code($base ?? $this->defaultBaseCurrency);
        $rates = $this->storage->load();
        $baseRate = $this->find($rates, $base)->rate;
        $result = [];
        foreach ($rates->rates as $rate) {
            $result[] = new Rate($rate->code, Decimal::divide($rate->rate, $baseRate));
        }

        return new RatesResult(
            base: $base,
            rates: new RateCollection($result),
        );
    }

    public function convert(string $amount, string $from, string $to): ConvertResult
    {
        $amount = Decimal::normalize($amount);
        if (str_starts_with($amount, '-')) {
            throw new \InvalidArgumentException('Amount must be non-negative.');
        }
        $from = $this->code($from);
        $to = $this->code($to);
        $rates = $this->storage->load();
        $fromRate = $this->find($rates, $from)->rate;
        $toRate = $this->find($rates, $to)->rate;

        return new ConvertResult(
            amount: Decimal::convert($amount, $fromRate, $toRate),
            from: new Rate($from, Decimal::divide($fromRate, $toRate)),
            to: new Rate($to, '1'),
        );
    }

    private function code(string $code): string
    {
        $code = strtoupper(trim($code));
        if (!preg_match('/\A[A-Z0-9]{1,16}\z/', $code)) {
            throw new \InvalidArgumentException('Currency code must contain 1 to 16 letters or digits.');
        }

        return $code;
    }

    private function find(RateCollection $rates, string $code): Rate
    {
        foreach ($rates->rates as $rate) {
            if ($rate->code === $code) {
                return $rate;
            }
        }

        throw new UnknownCurrencyException($code);
    }
}
