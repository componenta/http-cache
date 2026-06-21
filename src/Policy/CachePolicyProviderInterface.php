<?php

declare(strict_types=1);

namespace Componenta\Http\Cache\Policy;

use Psr\Http\Message\ServerRequestInterface;

interface CachePolicyProviderInterface
{
    public function policyFor(ServerRequestInterface $request): ?HttpCachePolicy;
}
