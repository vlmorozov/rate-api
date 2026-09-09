<?php

declare(strict_types=1);

namespace App\Service;

final class Decimal
{
    public const int SCALE = 32;
    public const int AMOUNT_SCALE = 8;

    public static function normalize(string $value): string
    {
        if (
            strlen($value) > 256
            || !preg_match('/\A([+-]?)(\d+)(?:\.(\d+))?(?:[eE]([+-]?\d{1,3}))?\z/', $value, $parts)
        ) {
            throw new \InvalidArgumentException('Expected a decimal number (at most 256 characters).');
        }

        $exponent = (int) ($parts[4] ?? 0);
        if (abs($exponent) > 128) {
            throw new \InvalidArgumentException('Decimal exponent must be between -128 and 128.');
        }

        $fraction = $parts[3] ?? '';
        $digits = $parts[2].$fraction;
        $position = strlen($parts[2]) + $exponent;
        if ($position <= 0) {
            $value = '0.'.str_repeat('0', -$position).$digits;
        } elseif ($position >= strlen($digits)) {
            $value = $digits.str_repeat('0', $position - strlen($digits));
        } else {
            $value = substr($digits, 0, $position).'.'.substr($digits, $position);
        }

        [$integer, $fraction] = array_pad(explode('.', $value, 2), 2, '');
        $integer = ltrim($integer, '0');
        $fraction = rtrim($fraction, '0');
        $value = ('' === $integer ? '0' : $integer).('' === $fraction ? '' : '.'.$fraction);

        $normalized = '-' === $parts[1] && '0' !== $value ? '-'.$value : $value;

        return $normalized;
    }

    public static function positive(string $value): string
    {
        $value = self::normalize($value);
        if (bccomp($value, '0', self::SCALE) <= 0) {
            throw new \InvalidArgumentException('Rate must be positive and at least 1e-32.');
        }

        return $value;
    }

    public static function divide(string $numerator, string $denominator): string
    {
        return self::normalize(bcdiv($numerator, $denominator, self::SCALE));
    }

    public static function convert(string $amount, string $fromRate, string $toRate): string
    {
        $scale = self::fractionLength($amount) + self::fractionLength($toRate);
        $product = bcmul($amount, $toRate, $scale);
        $result = bcdiv($product, $fromRate, self::AMOUNT_SCALE + 1);

        return self::normalize(bcround($result, self::AMOUNT_SCALE, \RoundingMode::HalfAwayFromZero));
    }

    private static function fractionLength(string $value): int
    {
        $position = strpos($value, '.');

        return false === $position ? 0 : strlen($value) - $position - 1;
    }
}
