<?php

declare(strict_types=1);

namespace Ejklock\Ci4Inertia;

/**
 * Describes where a paginated prop stands, so the client can ask for the pages around it.
 */
interface ProvidesScrollMetadata
{
    /**
     * The query parameter that carries the page.
     */
    public function getPageName(): string;

    /**
     * The page before the current one, or `null` on the first page.
     */
    public function getPreviousPage(): int|string|null;

    /**
     * The page after the current one, or `null` on the last page.
     */
    public function getNextPage(): int|string|null;

    /**
     * The page the prop holds.
     */
    public function getCurrentPage(): int|string|null;
}
