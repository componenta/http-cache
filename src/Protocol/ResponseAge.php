<?php

declare(strict_types=1);

namespace Componenta\Http\Cache\Protocol;

use Componenta\Http\Header;
use Psr\Http\Message\ResponseInterface;

final class ResponseAge
{
    public static function correctedInitialAge(
        ResponseInterface $response,
        float $requestTime,
        float $responseTime,
    ): int {
        $apparentAge = self::apparentAge($response, $responseTime);
        $responseDelay = max(0, (int) ($responseTime - $requestTime));
        $correctedAgeValue = self::ageValue($response);

        if ($correctedAgeValue > PHP_INT_MAX - $responseDelay) {
            $correctedAgeValue = PHP_INT_MAX;
        } else {
            $correctedAgeValue += $responseDelay;
        }

        return max($apparentAge, $correctedAgeValue);
    }

    public static function atReceipt(ResponseInterface $response, ?float $responseTime = null): int
    {
        $responseTime ??= microtime(true);

        return max(
            self::apparentAge($response, $responseTime),
            self::ageValue($response),
        );
    }

    private static function apparentAge(ResponseInterface $response, float $responseTime): int
    {
        if (!$response->hasHeader(Header::DATE)) {
            return 0;
        }

        $dateValue = HttpDate::parse($response->getHeaderLine(Header::DATE));

        if ($dateValue === null) {
            return 0;
        }

        return max(0, (int) ($responseTime - $dateValue));
    }

    private static function ageValue(ResponseInterface $response): int
    {
        $values = HeaderList::split($response->getHeader(Header::AGE));

        if ($values === []) {
            return 0;
        }

        $value = $values[0];

        if (preg_match('/^[0-9]+$/D', $value) !== 1) {
            return 0;
        }

        $normalized = ltrim($value, '0');

        if ($normalized === '') {
            return 0;
        }

        $max = (string) PHP_INT_MAX;

        if (strlen($normalized) > strlen($max)
            || (strlen($normalized) === strlen($max) && strcmp($normalized, $max) > 0)
        ) {
            return PHP_INT_MAX;
        }

        return (int) $normalized;
    }

    private function __construct() {}
}
