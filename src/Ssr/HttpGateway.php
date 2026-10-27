<?php

declare(strict_types=1);

namespace Ejklock\Ci4Inertia\Ssr;

use Closure;
use CodeIgniter\Events\Events;
use CodeIgniter\HTTP\CURLRequest;
use Ejklock\Ci4Inertia\Config\Inertia;
use Exception;

/**
 * Posts the page to an Inertia SSR server over HTTP: the Vite dev server when the hot file
 * exists, the production server otherwise. The protected methods are the extension points an
 * application overrides in a subclass.
 */
class HttpGateway implements Gateway
{
    private const CONNECT_TIMEOUT = 10;
    private const DEFAULT_TIMEOUT = 30;

    /**
     * @param Closure(array<string, mixed>): CURLRequest $clientFactory builds a fresh client from the
     *                                                                  given options for each dispatch, so no header or
     *                                                                  option leaks from one request to the next
     */
    public function __construct(
        protected Inertia $config,
        protected Closure $clientFactory,
        protected BundleDetector $bundleDetector,
    ) {
    }

    public function dispatch(array $page): ?Response
    {
        if (! $this->ssrIsEnabled()) {
            return null;
        }

        $isHot = is_file($this->config->ssrHotFile);

        if (! $isHot && $this->shouldEnsureBundleExists() && ! $this->bundleExists()) {
            return null;
        }

        $url = $isHot ? $this->getHotUrl('/__inertia_ssr') : $this->getProductionUrl('/render');

        try {
            $response = $this->pendingRequest()->post($url, ['json' => $page]);

            if ($response->getStatusCode() >= 400) {
                $this->handleSsrFailure($page, $this->decode($response->getBody()));

                return null;
            }

            $data = $this->decode($response->getBody());

            if ($data === null || $data === []) {
                return null;
            }

            $head = $data['head'] ?? [];

            return new Response(
                is_array($head) ? implode("\n", $head) : '',
                is_string($data['body'] ?? null) ? $data['body'] : '',
            );
        } catch (SsrException $e) {
            throw $e;
        } catch (Exception $e) {
            $this->handleSsrFailure($page, ['error' => $e->getMessage(), 'type' => 'connection']);

            return null;
        }
    }

    /**
     * The production SSR server URL for the given path.
     */
    public function getProductionUrl(string $path = '/'): string
    {
        return rtrim($this->config->ssrUrl, '/') . '/' . ltrim($path, '/');
    }

    /**
     * A fresh HTTP client for the SSR server. Without a configured timeout it gives up after 30
     * seconds, and it always waits at most 10 seconds to connect.
     */
    protected function pendingRequest(): CURLRequest
    {
        $timeout = $this->config->ssrTimeout;

        return ($this->clientFactory)([
            'http_errors'     => false,
            'connect_timeout' => self::CONNECT_TIMEOUT,
            'timeout'         => $timeout !== null && $timeout > 0 ? $timeout : self::DEFAULT_TIMEOUT,
        ]);
    }

    /**
     * Reports a render failure through the `SsrRenderFailed` event and, when configured, throws.
     *
     * @param array<string, mixed>      $page
     * @param array<string, mixed>|null $error
     *
     * @throws SsrException
     */
    protected function handleSsrFailure(array $page, ?array $error): void
    {
        $event = new SsrRenderFailed(
            page: $page,
            error: $this->text($error['error'] ?? null) ?? 'Unknown SSR error',
            type: SsrErrorType::fromString($this->text($error['type'] ?? null)),
            hint: $this->text($error['hint'] ?? null),
            browserApi: $this->text($error['browserApi'] ?? null),
            stack: $this->text($error['stack'] ?? null),
            sourceLocation: $this->text($error['sourceLocation'] ?? null),
        );

        Events::trigger(SsrRenderFailed::class, $event);

        if ($this->config->ssrThrowOnError) {
            throw SsrException::fromEvent($event);
        }
    }

    protected function ssrIsEnabled(): bool
    {
        return $this->config->ssrEnabled;
    }

    protected function shouldEnsureBundleExists(): bool
    {
        return $this->config->ssrEnsureBundleExists;
    }

    protected function bundleExists(): bool
    {
        return $this->bundleDetector->detect() !== null;
    }

    /**
     * The Vite dev server URL for the given path.
     */
    protected function getHotUrl(string $path = '/'): string
    {
        $base = $this->config->ssrHotUrl;

        if ($base === null || $base === '') {
            $base = (string) file_get_contents($this->config->ssrHotFile);
        }

        return rtrim(trim($base), '/') . '/' . ltrim($path, '/');
    }

    /**
     * @return array<string, mixed>|null
     */
    private function decode(?string $body): ?array
    {
        $data = json_decode((string) $body, true);

        return is_array($data) ? $data : null;
    }

    private function text(mixed $value): ?string
    {
        return is_string($value) ? $value : null;
    }
}
