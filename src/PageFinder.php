<?php

declare(strict_types=1);

namespace Ejklock\Ci4Inertia;

/**
 * Looks a component name up as a page file: dots in the name become directories, and the first
 * existing `<path>/<name>.<extension>` wins, searching the paths in order and then the extensions.
 *
 * @internal
 */
final readonly class PageFinder
{
    /**
     * @param list<string> $paths      Directories holding the page files.
     * @param list<string> $extensions File extensions a page may have, without the dot.
     */
    public function __construct(
        private array $paths,
        private array $extensions,
    ) {
    }

    public function exists(string $component): bool
    {
        $relative = str_replace('.', '/', $component);

        foreach ($this->paths as $path) {
            foreach ($this->extensions as $extension) {
                if (file_exists($path . '/' . $relative . '.' . $extension)) {
                    return true;
                }
            }
        }

        return false;
    }
}
