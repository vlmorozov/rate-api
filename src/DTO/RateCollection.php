<?php

declare(strict_types=1);

namespace App\DTO;

use Symfony\Component\DependencyInjection\Attribute\Exclude;

#[Exclude]
final readonly class RateCollection implements \JsonSerializable
{
    public function __construct(
        public array $rates,
    ) {
    }

    public function toArray(): array
    {
        return $this->rates;
    }

    public function jsonSerialize(): array
    {
        return array_values($this->rates);
    }
}
