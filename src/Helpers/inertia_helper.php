<?php

declare(strict_types=1);

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
