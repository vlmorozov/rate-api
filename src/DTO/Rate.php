<?php

declare(strict_types=1);

namespace App\DTO;

use Symfony\Component\DependencyInjection\Attribute\Exclude;

#[Exclude]
final readonly class Rate implements \JsonSerializable
{
    public function __construct(
        public string $code,
        public string $rate,
    ) {
    }

    public function jsonSerialize(): array
    {
        return [
            'rate' => (float) $this->rate,
            'code' => $this->code,
        ];
    }
}
