<?php

declare(strict_types=1);

namespace Componenta\Http\Cache;

final class ConfigKey
{
    public const string POLICIES = 'Componenta\Http\Cache::policies';
    public const string DEBUG_HEADER = 'Componenta\Http\Cache::debug_header';
    public const string KEY_PREFIX = 'Componenta\Http\Cache::key_prefix';
    public const string MAX_ENTRY_BYTES = 'Componenta\Http\Cache::max_entry_bytes';

    private function __construct() {}
}
