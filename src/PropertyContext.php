<?php

declare(strict_types=1);

namespace Ejklock\Ci4Inertia;

use CodeIgniter\HTTP\IncomingRequest;

/**
 * What a `ProvidesInertiaProperty` learns about the prop it is converted for.
 */
final readonly class PropertyContext
{
    /**
     * @param string               $key     The prop's dot path, e.g. `auth.user` for a nested prop.
     * @param array<string, mixed> $props   The props at the same level, as declared.
     * @param IncomingRequest      $request The current request.
     */
    public function __construct(
        public string $key,
        public array $props,
        public IncomingRequest $request,
    ) {
    }
}
