<?php

declare(strict_types=1);

namespace Ejklock\Ci4Inertia\Ssr;

/**
 * The head and body markup a server-side rendering engine returned for a page.
 */
final readonly class Response
{
    public function __construct(
        public string $head,
        public string $body,
    ) {
    }
}
