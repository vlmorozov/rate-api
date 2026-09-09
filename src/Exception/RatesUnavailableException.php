<?php

declare(strict_types=1);

namespace App\Exception;

use Symfony\Component\DependencyInjection\Attribute\Exclude;

#[Exclude]
final class RatesUnavailableException extends \RuntimeException
{
}
