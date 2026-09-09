<?php

declare(strict_types=1);

namespace App\Service\RateProvider;

use App\DTO\RateCollection;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

#[AutoconfigureTag('app.rate_provider')]
interface RateProviderInterface
{
    public function fetch(string $baseCurrency): RateCollection;
}
