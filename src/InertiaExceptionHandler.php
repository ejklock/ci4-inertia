<?php

declare(strict_types=1);

namespace Ejklock\Ci4Inertia;

use Closure;
use CodeIgniter\Debug\ExceptionHandler;
use CodeIgniter\Debug\ExceptionHandlerInterface;
use CodeIgniter\HTTP\CLIRequest;
use CodeIgniter\HTTP\Exceptions\HTTPException;
use CodeIgniter\HTTP\IncomingRequest;
use CodeIgniter\HTTP\RequestInterface;
use CodeIgniter\HTTP\ResponseInterface;
use Config\Exceptions;
use LogicException;
use Throwable;

/**
 * Answers an uncaught exception with an Inertia page when the application registered a callback
 * through `Inertia::handleExceptionsUsing()`, and otherwise leaves the answer to CodeIgniter's own
 * handler: when no callback is registered, when it returns `null`, and for a CLI request. An
 * application returns it from `Config\Exceptions::handler()`; the package never installs it itself.
 *
 * The callback lives here, not in the `inertia` service, because an exception can be handled
 * before a filter ran or without a usable shared service.
 */
final class InertiaExceptionHandler implements ExceptionHandlerInterface
{
    private static ?Closure $callback = null;

    public function __construct(private readonly Exceptions $config)
    {
    }

    /**
     * Registers the callback that decides the answer to an uncaught exception, replacing any
     * earlier one; `null` clears it. The callback receives an `ExceptionResponse` and returns that
     * (rendered or not), a `ResponseInterface`, or `null` to leave the answer to CodeIgniter.
     */
    public static function respondUsing(?callable $callback): void
    {
        self::$callback = $callback === null ? null : $callback(...);
    }

    /**
     * @throws LogicException When the callback returns anything but `null`, an
     *                        `ExceptionResponse` or a `ResponseInterface`.
     */
    public function handle(
        Throwable $exception,
        RequestInterface $request,
        ResponseInterface $response,
        int $statusCode,
        int $exitCode,
    ): void {
        if (self::$callback === null || ! $request instanceof IncomingRequest) {
            $this->handleByCodeIgniter($exception, $request, $response, $statusCode, $exitCode);

            return;
        }

        $statusCode = $this->applyStatus($response, $statusCode);
        $result     = (self::$callback)(new ExceptionResponse($exception, $request, $response, $statusCode));

        if ($result === null) {
            $this->handleByCodeIgniter($exception, $request, $response, $statusCode, $exitCode);

            return;
        }

        if ($result instanceof ExceptionResponse) {
            $result = $result->toResponse();
        }

        if (! $result instanceof ResponseInterface) {
            throw new LogicException('The exception callback must return an ExceptionResponse, a ResponseInterface or null.');
        }

        $result->send();

        if (ENVIRONMENT !== 'testing') {
            // @codeCoverageIgnoreStart
            exit($exitCode);
            // @codeCoverageIgnoreEnd
        }
    }

    private function handleByCodeIgniter(
        Throwable $exception,
        RequestInterface $request,
        ResponseInterface $response,
        int $statusCode,
        int $exitCode,
    ): void {
        /** @var CLIRequest|IncomingRequest $request CodeIgniter hands a handler only these two. */
        (new ExceptionHandler($this->config))->handle($exception, $request, $response, $statusCode, $exitCode);
    }

    /**
     * Sets `$statusCode` on the response, falling back to `500` as CodeIgniter's handler does when
     * it is not a valid HTTP status; returns the status the response ended with.
     */
    private function applyStatus(ResponseInterface $response, int $statusCode): int
    {
        try {
            $response->setStatusCode($statusCode);
        } catch (HTTPException) {
            $statusCode = 500;
            $response->setStatusCode($statusCode);
        }

        return $statusCode;
    }
}
