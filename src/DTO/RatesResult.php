<?php

declare(strict_types=1);

namespace App\DTO;

use Symfony\Component\DependencyInjection\Attribute\Exclude;

#[Exclude]
final readonly class RatesResult
{
    public function __construct(
        public string $base,
        public RateCollection $rates,
    ) {
    }
}
