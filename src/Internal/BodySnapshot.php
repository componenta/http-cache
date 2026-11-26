<?php

declare(strict_types=1);

namespace Componenta\Http\Cache\Internal;

use Psr\Http\Message\StreamInterface;

/** @internal */
final class BodySnapshot
{
    public static function read(StreamInterface $body, int $maxBytes): ?string
    {
        if (!$body->isReadable() || !$body->isSeekable() || $maxBytes < 0) {
            return null;
        }

        $size = $body->getSize();
        if ($size !== null && ($size < 0 || $size > $maxBytes)) {
            return null;
        }

        $position = $body->tell();
        $contents = '';

        try {
            $body->rewind();
            while (!$body->eof()) {
                $chunk = $body->read(8192);
                if ($chunk === '') {
                    if (!$body->eof()) {
                        return null;
                    }
                    break;
                }
                if (strlen($chunk) > $maxBytes - strlen($contents)) {
                    return null;
                }
                $contents .= $chunk;
            }

            return $size !== null && strlen($contents) !== $size ? null : $contents;
        } finally {
            $body->seek($position);
        }
    }

    private function __construct() {}
}
