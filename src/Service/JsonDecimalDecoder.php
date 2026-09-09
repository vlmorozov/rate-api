<?php

declare(strict_types=1);

namespace App\Service;

final class JsonDecimalDecoder
{
    /** @return array<array-key, mixed> */
    public function decode(string $json): array
    {
        if (!json_validate($json)) {
            throw new \JsonException(json_last_error_msg());
        }

        $json = preg_replace_callback(
            '/"(?:[^"\\\\]|\\\\.)*"|-?(?:0|[1-9]\d*)(?:\.\d+)?(?:[eE][+-]?\d+)?/s',
            static fn (array $match): string => '"' === $match[0][0] ? $match[0] : '"'.$match[0].'"',
            $json,
        );

        if (null === $json) {
            throw new \JsonException('Cannot decode decimal JSON: '.preg_last_error_msg());
        }

        $data = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($data)) {
            throw new \JsonException('Expected a JSON object or array.');
        }

        return $data;
    }
}
