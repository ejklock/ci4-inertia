<?php

declare(strict_types=1);

namespace Ejklock\Ci4Inertia\Ssr;

use Closure;
use CodeIgniter\Events\Events;
use CodeIgniter\HTTP\CURLRequest;
use CodeIgniter\HTTP\IncomingRequest;
use Ejklock\Ci4Inertia\Config\Inertia;
use Ejklock\Ci4Inertia\ResolvesCallables;
use Exception;

/**
 * Posts the page to an Inertia SSR server over HTTP: the Vite dev server when the hot file
 * exists, the production server otherwise. The protected methods are the extension points an
 * application overrides in a subclass. SSR can be switched off with a condition, skipped for
 * paths and its request adjusted before it is sent.
 */
class HttpGateway implements ConfiguresSsrRequests, DisablesSsr, ExcludesSsrPaths, Gateway, HasHealthCheck
{
    use ResolvesCallables;

    private const CONNECT_TIMEOUT = 10;
    private const DEFAULT_TIMEOUT = 30;

    /**
     * @var list<string>
     */
    protected array $except = [];

    protected bool|Closure|null $disabled   = null;
    protected ?Closure $requestConfigurator = null;

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

    /**
     * @param array<string, mixed> $page
     * @param IncomingRequest|null $request the request whose path is checked against the excluded
     *                                      paths; the current request when omitted
     */
    public function dispatch(array $page, ?IncomingRequest $request = null): ?Response
    {
        if (! $this->ssrIsEnabled($request ??= service('request'))) {
            return null;
        }

        $isHot = is_file($this->config->ssrHotFile);

        if (! $isHot && $this->shouldEnsureBundleExists() && ! $this->bundleExists()) {
            return null;
        }

        $url = $isHot ? $this->getHotUrl('/__inertia_ssr') : $this->getProductionUrl('/render');

        if ($isHot && ! $this->isHttpUrlWithHost($url)) {
            $this->handleSsrFailure($page, ['error' => 'The SSR hot URL must be an http or https URL with a host.', 'type' => 'connection']);

            return null;
        }

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

    public function disable(bool|Closure $condition): void
    {
        $this->disabled = $condition;
    }

    public function except(array|string $paths): void
    {
        $this->except = array_merge($this->except, (array) $paths);
    }

    public function configureRequestUsing(?Closure $callback = null): void
    {
        $this->requestConfigurator = $callback;
    }

    /**
     * Whether the production server answers its `/health` endpoint with a 2xx status; any failure to
     * reach it counts as unhealthy.
     */
    public function isHealthy(): bool
    {
        try {
            $status = $this->pendingRequest()->get($this->getProductionUrl('/health'))->getStatusCode();

            return $status >= 200 && $status < 300;
        } catch (Exception) {
            return false;
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
     * seconds, and it always waits at most 10 seconds to connect. The request configurator, when
     * set, receives it and may return a replacement.
     */
    protected function pendingRequest(): CURLRequest
    {
        $timeout = $this->config->ssrTimeout;

        $client = ($this->clientFactory)([
            'http_errors'     => false,
            'connect_timeout' => self::CONNECT_TIMEOUT,
            'timeout'         => $timeout !== null && $timeout > 0 ? $timeout : self::DEFAULT_TIMEOUT,
        ]);

        if ($this->requestConfigurator === null) {
            return $client;
        }

        return ($this->requestConfigurator)($client) ?? $client;
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

    /**
     * Whether SSR is enabled for the request: the condition set by `disable()` when there is one,
     * which replaces the `ssrEnabled` config flag, and the flag otherwise, and the request's
     * path not being excluded.
     */
    protected function ssrIsEnabled(IncomingRequest $request): bool
    {
        $enabled = $this->disabled === null
            ? $this->config->ssrEnabled
            : ! $this->resolveCallable($this->disabled);

        return $enabled && ! $this->inExceptArray($request);
    }

    /**
     * Whether the request's path, or the full URL the client requested with its query, matches an
     * excluded pattern. A pattern is trimmed of slashes, except `/` itself, which is the root. The
     * full URL is built only when a pattern did not match the path, and a query with more pairs than
     * PHP's `max_input_vars` cannot be parsed as the server would, so such a request counts as excluded.
     */
    protected function inExceptArray(IncomingRequest $request): bool
    {
        $path = rawurldecode(trim($request->getPath(), '/'));
        $path = $path === '' ? '/' : $path;
        $url  = null;

        foreach ($this->except as $pattern) {
            $pattern = $pattern === '/' ? $pattern : trim($pattern, '/');

            if ($this->matches($pattern, $path)) {
                return true;
            }

            $url ??= $this->fullUrl($request);

            if ($url === null || $this->matches($pattern, $url)) {
                return true;
            }
        }

        return false;
    }

    /**
     * The URL the client requested, normalized like Laravel's `fullUrl()`: no trailing slash on the
     * path, and the query sorted by key. The path and query are the server's raw request URI, so
     * the index page shows only when the client used it; without one, the request URI's path and
     * query are used. Null when the query, split by any `arg_separator.input` character with empty
     * segments counted, has more segments than `max_input_vars`, where 0 allows none.
     */
    private function fullUrl(IncomingRequest $request): ?string
    {
        $uri        = $request->getUri();
        $requestUri = $request->getServer('REQUEST_URI');

        if (is_string($requestUri) && $requestUri !== '') {
            $relative = preg_replace('#^[a-z][a-z0-9+.-]*://[^/?\\#]*#i', '', $requestUri) ?? $requestUri;
        } else {
            $relative = $uri->getPath();

            if ($uri->getQuery() !== '') {
                $relative .= '?' . $uri->getQuery();
            }
        }

        [$path, $query] = explode('?', $relative, 2) + [1 => ''];
        $url            = rtrim($uri->getScheme() . '://' . $uri->getAuthority() . '/' . ltrim($path, '/'), '/');

        $segments = $query === '' ? [] : $this->querySegments($query);

        if (count($segments) > (int) ini_get('max_input_vars')) {
            return null;
        }

        $parameters = $this->parseQuery($segments);
        ksort($parameters);
        $query = http_build_query($parameters, '', '&', PHP_QUERY_RFC3986);

        if ($query === '') {
            return $url;
        }

        return $url . (trim($path, '/') === '' ? '/?' : '?') . $query;
    }

    /**
     * Splits a query on every character of `arg_separator.input`, as `parse_str` does, keeping empty
     * segments.
     *
     * @return list<string>
     */
    private function querySegments(string $query): array
    {
        $separators = $this->inputSeparators();
        $first      = $separators[0];

        return explode($first, strtr($query, $separators, str_repeat($first, strlen($separators))));
    }

    /**
     * The characters `parse_str` splits a query on. PHP never leaves the setting empty; the `&`
     * fallback only gives the type a non-empty string.
     *
     * @return non-empty-string
     */
    private function inputSeparators(): string
    {
        $separators = (string) ini_get('arg_separator.input');

        return $separators === '' ? '&' : $separators;
    }

    /**
     * Parses query segments like Symfony's `HeaderUtils::parseQuery`, which, unlike `parse_str`,
     * keeps dots and spaces in the keys: each key is hex encoded around the parse and decoded after.
     *
     * @param list<string> $segments the query split on the input separators
     *
     * @return array<int|string, mixed>
     */
    private function parseQuery(array $segments): array
    {
        $pairs = [];

        foreach ($segments as $pair) {
            $pair = explode("\0", $pair)[0];
            $at   = strpos($pair, '=');
            $key  = urldecode($at === false ? $pair : substr($pair, 0, $at));
            $rest = $at === false ? '' : substr($pair, $at);
            $key  = ltrim(explode("\0", $key)[0], ' ');
            $open = strpos($key, '[');

            $pairs[] = $open === false
                ? bin2hex($key) . $rest
                : bin2hex(substr($key, 0, $open)) . rawurlencode(substr($key, $open)) . $rest;
        }

        parse_str(implode($this->inputSeparators()[0], $pairs), $parsed);

        $parameters = [];

        foreach ($parsed as $key => $value) {
            $key        = (string) $key;
            $underscore = strpos($key, '_');

            if ($underscore === false) {
                $parameters[(string) hex2bin($key)] = $value;
            } else {
                $parameters[substr_replace($key, hex2bin(substr($key, 0, $underscore)) . '[', 0, 1 + $underscore)] = $value;
            }
        }

        return $parameters;
    }

    private function matches(string $pattern, string $value): bool
    {
        $regex = str_replace('\\*', '.*', preg_quote($pattern, '#'));

        return preg_match('#^' . $regex . '\\z#su', $value) === 1;
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

    private function isHttpUrlWithHost(string $url): bool
    {
        $parts = parse_url($url);

        return preg_match('/[\\x00-\\x20\\x7f]/', $url) === 0
            && $parts !== false
            && in_array(strtolower($parts['scheme'] ?? ''), ['http', 'https'], true)
            && ($parts['host'] ?? '') !== '';
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
