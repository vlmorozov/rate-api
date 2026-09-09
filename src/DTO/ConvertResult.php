<?php

declare(strict_types=1);

namespace App\DTO;

use Symfony\Component\DependencyInjection\Attribute\Exclude;

#[Exclude]
final readonly class ConvertResult implements \JsonSerializable
{
    public function __construct(
        public string $amount,
        public Rate $from,
        public Rate $to,
    ) {
    }

    public function jsonSerialize(): array
    {
        return [
            'amount' => (float) $this->amount,
            'currency_from' => $this->from,
            'currency_to' => $this->to,
        ];
    }
}
