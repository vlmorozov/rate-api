<?php

declare(strict_types=1);

namespace App\Request;

use App\Service\Decimal;
use App\Validator\SupportedCurrency;
use Symfony\Component\DependencyInjection\Attribute\Exclude;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Context\ExecutionContextInterface;

#[Assert\GroupSequence(['ConvertRequest', 'SupportedCurrencies'])]
#[Exclude]
final readonly class ConvertRequest
{
    #[Assert\Sequentially([
        new Assert\NotBlank(message: 'Source currency must not be empty.'),
        new Assert\Regex(
            pattern: '/\A[A-Z0-9]{1,16}\z/',
            message: 'Source currency code must contain 1 to 16 letters or digits.',
        ),
    ])]
    #[SupportedCurrency(groups: ['SupportedCurrencies'])]
    public string $from;

    #[Assert\Sequentially([
        new Assert\NotBlank(message: 'Target currency must not be empty.'),
        new Assert\Regex(
            pattern: '/\A[A-Z0-9]{1,16}\z/',
            message: 'Target currency code must contain 1 to 16 letters or digits.',
        ),
    ])]
    #[SupportedCurrency(groups: ['SupportedCurrencies'])]
    public string $to;

    public function __construct(public string $amount, string $from, string $to)
    {
        $this->from = strtoupper(trim($from));
        $this->to = strtoupper(trim($to));
    }

    #[Assert\Callback]
    public function validateAmount(ExecutionContextInterface $context): void
    {
        try {
            $amount = Decimal::normalize($this->amount);
        } catch (\InvalidArgumentException $exception) {
            $context
                ->buildViolation($exception->getMessage())
                ->atPath('amount')
                ->addViolation();

            return;
        }

        if (str_starts_with($amount, '-')) {
            $context
                ->buildViolation('Amount must be non-negative.')
                ->atPath('amount')
                ->addViolation();
        }
    }
}
