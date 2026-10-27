<?php

declare(strict_types=1);

namespace Ejklock\Ci4Inertia\Ssr;

/**
 * Triggered through `CodeIgniter\Events\Events` when a server-side render fails; listen with
 * `Events::on(SsrRenderFailed::class, ...)`.
 */
final readonly class SsrRenderFailed
{
    /**
     * @param array<string, mixed> $page           The page that was being rendered
     * @param string               $error          The error message
     * @param string|null          $hint           How to fix the error
     * @param string|null          $browserApi     The browser API accessed, for a `browser-api` error
     * @param string|null          $stack          The stack trace
     * @param string|null          $sourceLocation The `file:line:column` where the error occurred
     */
    public function __construct(
        public array $page,
        public string $error,
        public SsrErrorType $type = SsrErrorType::Unknown,
        public ?string $hint = null,
        public ?string $browserApi = null,
        public ?string $stack = null,
        public ?string $sourceLocation = null,
    ) {
    }

    public function component(): string
    {
        $component = $this->page['component'] ?? null;

        return is_string($component) ? $component : 'Unknown';
    }

    public function url(): string
    {
        $url = $this->page['url'] ?? null;

        return is_string($url) ? $url : '/';
    }

    /**
     * The event as a loggable array: null fields and the stack are left out.
     *
     * @return array<string, string>
     */
    public function toArray(): array
    {
        return array_filter([
            'component'       => $this->component(),
            'url'             => $this->url(),
            'error'           => $this->error,
            'type'            => $this->type->value,
            'hint'            => $this->hint,
            'browser_api'     => $this->browserApi,
            'source_location' => $this->sourceLocation,
        ]);
    }
}
