<?php

declare(strict_types=1);

namespace Componenta\Http\Cache\Tests\Fixture;

use Componenta\Http\Cache\Attribute\CacheResponse;
use Psr\Http\Message\ServerRequestInterface;
use RuntimeException;

#[CacheResponse(
    ttl: 60,
    tags: ['catalog'],
    varyHeaders: ['Accept-Language'],
    when: self::allows(...),
    tagsFactory: self::tags(...),
)]
final class CallbackCachedHandler
{
    public function __invoke(): void {}

    public static function allows(ServerRequestInterface $request): bool
    {
        $probe = $request->getAttribute('probe');
        if ($probe instanceof CallbackProbe) {
            $probe->calls[] = 'when';
        }
        $failure = $request->getAttribute('failure');
        if ($failure instanceof RuntimeException) {
            throw $failure;
        }

        return $request->getAttribute('preview', false) === false;
    }

    /** @return list<string> */
    public static function tags(ServerRequestInterface $request): array
    {
        $probe = $request->getAttribute('probe');
        if ($probe instanceof CallbackProbe) {
            $probe->calls[] = 'tags';
        }

        return ['category:' . $request->getHeaderLine('X-Category')];
    }
}
