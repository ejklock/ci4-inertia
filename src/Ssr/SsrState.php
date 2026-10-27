<?php

declare(strict_types=1);

namespace Ejklock\Ci4Inertia\Ssr;

/**
 * Holds the page of the current request and the server-side render of it, so the head and the
 * body of the root view share one trip to the gateway.
 */
class SsrState
{
    /**
     * The page data for the current request.
     *
     * @var array<string, mixed>
     */
    public array $page = [];

    /**
     * The server-side render of the current page.
     */
    public ?Response $response = null;

    /**
     * Whether the gateway has been dispatched for the current page.
     */
    protected bool $dispatched = false;

    /**
     * Sets the page of the current request; a page that differs from the previous one forgets
     * its render.
     *
     * @param array<string, mixed> $page
     */
    public function setPage(array $page): static
    {
        if ($page !== $this->page) {
            $this->dispatched = false;
            $this->response   = null;
        }

        $this->page = $page;

        return $this;
    }

    /**
     * Sends the page to the gateway, or returns the render it already gave. The page counts as
     * dispatched before the gateway runs, so a gateway that throws is not called again for it.
     */
    public function dispatch(): ?Response
    {
        if (! $this->dispatched) {
            $this->dispatched = true;
            $this->response   = service('inertiaSsrGateway')->dispatch($this->page);
        }

        return $this->response;
    }
}
