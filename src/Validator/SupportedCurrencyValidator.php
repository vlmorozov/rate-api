<?php

declare(strict_types=1);

namespace App\Validator;

use App\Service\RateStorage;
use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\ConstraintValidator;
use Symfony\Component\Validator\Exception\UnexpectedTypeException;
use Symfony\Component\Validator\Exception\UnexpectedValueException;

final class SupportedCurrencyValidator extends ConstraintValidator
{
    public function __construct(private readonly RateStorage $storage)
    {
    }

    public function validate(mixed $value, Constraint $constraint): void
    {
        if (!$constraint instanceof SupportedCurrency) {
            throw new UnexpectedTypeException($constraint, SupportedCurrency::class);
        }

        if (null === $value || '' === $value) {
            return;
        }

        if (!is_string($value)) {
            throw new UnexpectedValueException($value, 'string');
        }

        $rates = $this->storage->load();
        foreach ($rates->rates as $rate) {
            if ($rate->code === $value) {
                return;
            }
        }

        $this->context->buildViolation($constraint->message)
            ->setParameter('{{ code }}', $value)
            ->addViolation();
    }
}
