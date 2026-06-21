<?php

declare(strict_types=1);

namespace Componenta\Http\Cache\Key;

use Componenta\Http\Cache\Policy\HttpCachePolicy;
use Psr\Http\Message\ServerRequestInterface;

interface CacheKeyGeneratorInterface
{
    public function generate(ServerRequestInterface $request, HttpCachePolicy $policy): string;
}
