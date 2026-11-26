<?php

declare(strict_types=1);

namespace Componenta\Http\Cache\Protocol;

use Componenta\Http\Cache\Internal\AgeMetadata;
use Componenta\Http\Header;
use Psr\Http\Message\ResponseInterface;

final class ResponseAge
{
    public static function correctedInitialAge(
        ResponseInterface $response,
        float $requestTime,
        float $responseTime,
    ): int {
        $apparentAge = AgeMetadata::apparentAge($response->getHeaderLine(Header::DATE), $responseTime);
        $responseDelay = max(0, (int) ($responseTime - $requestTime));
        $correctedAgeValue = AgeMetadata::ageValue($response->getHeader(Header::AGE));

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
            AgeMetadata::apparentAge($response->getHeaderLine(Header::DATE), $responseTime),
            AgeMetadata::ageValue($response->getHeader(Header::AGE)),
        );
    }

    private function __construct() {}
}
