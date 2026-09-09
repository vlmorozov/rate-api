<?php

declare(strict_types=1);

namespace App\Exception;

use Symfony\Component\DependencyInjection\Attribute\Exclude;

#[Exclude]
final class UnknownCurrencyException extends \InvalidArgumentException
{
    public function __construct(string $currency)
    {
        parent::__construct(sprintf('Unknown currency: %s.', $currency));
    }
}
