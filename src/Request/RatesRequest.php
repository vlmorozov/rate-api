<?php

declare(strict_types=1);

namespace App\Request;

use App\Validator\SupportedCurrency;
use Symfony\Component\DependencyInjection\Attribute\Exclude;
use Symfony\Component\Validator\Constraints as Assert;

#[Exclude]
final readonly class RatesRequest
{
    #[Assert\Sequentially([
        new Assert\NotBlank(allowNull: true, message: 'Base currency must not be empty.'),
        new Assert\Regex(
            pattern: '/\A[A-Z0-9]{1,16}\z/',
            message: 'Base currency code must contain 1 to 16 letters or digits.',
        ),
        new SupportedCurrency(),
    ])]
    public ?string $base;

    public function __construct(?string $base = null)
    {
        $this->base = null === $base ? null : strtoupper(trim($base));
    }
}
