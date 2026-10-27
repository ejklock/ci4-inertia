<?php

declare(strict_types=1);

namespace Ejklock\Ci4Inertia;

use CodeIgniter\HTTP\IncomingRequest;

/**
 * What a `ProvidesInertiaProperties` learns about the render it provides props for.
 */
final readonly class RenderContext
{
    /**
     * @param string          $component The component being rendered.
     * @param IncomingRequest $request   The current request.
     */
    public function __construct(
        public string $component,
        public IncomingRequest $request,
    ) {
    }
}
