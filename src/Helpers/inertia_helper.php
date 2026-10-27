<?php

declare(strict_types=1);

use CodeIgniter\HTTP\RedirectResponse;
use CodeIgniter\HTTP\ResponseInterface;
use Ejklock\Ci4Inertia\InertiaResponse;
use Ejklock\Ci4Inertia\ProvidesInertiaProperties;
use Ejklock\Ci4Inertia\ResponseFactory;

if (! function_exists('inertia_app')) {
    /**
     * Prints the page object as the JSON script tag an Inertia client reads on a first visit,
     * followed by the element it mounts on. `JSON_HEX_TAG` keeps a prop value from closing the
     * tag; the other flags match the Inertia JSON response so both forms decode to the same page.
     *
     * @param array<string, mixed> $page The page object `InertiaResponse` hands the root view.
     * @param string               $id   The id of the script's `data-page` and of the mount element.
     *
     * @throws JsonException When the page holds a value that cannot be encoded as JSON.
     */
    function inertia_app(array $page, string $id = 'app'): string
    {
        $json = json_encode($page, JSON_HEX_TAG | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        $id   = esc($id, 'attr');

        return '<script data-page="' . $id . '" type="application/json">' . $json . '</script><div id="' . $id . '"></div>';
    }
}

if (! function_exists('inertia')) {
    /**
     * Reaches the shared response factory, or renders a page when a component is given.
     *
     * @param BackedEnum|string|UnitEnum|null                   $component A falsy value returns the factory.
     * @param array<array-key, mixed>|ProvidesInertiaProperties $props     Per-render props.
     *
     * @return ($component is ''|'0'|null ? ResponseFactory : InertiaResponse)
     */
    function inertia(BackedEnum|string|UnitEnum|null $component = null, array|ProvidesInertiaProperties $props = []): InertiaResponse|ResponseFactory
    {
        $factory = service('inertia');

        return $component ? $factory->render($component, $props) : $factory;
    }
}

if (! function_exists('inertia_location')) {
    /**
     * Answers a stale-asset reload through the shared response factory.
     *
     * @see ResponseFactory::location()
     */
    function inertia_location(RedirectResponse|string $url): ResponseInterface
    {
        return service('inertia')->location($url);
    }
}
