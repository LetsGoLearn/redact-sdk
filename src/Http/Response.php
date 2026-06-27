<?php

declare(strict_types=1);

namespace RedactSdk\Http;

/**
 * A transport response. A status of 0 indicates a transport-level failure with
 * no HTTP response (see `error`); this is how batch sends report per-item
 * connection errors without aborting the whole batch.
 */
final class Response
{
    /**
     * @param array<string, mixed>|null $data Decoded JSON body, when parseable.
     */
    public function __construct(
        public readonly int $status,
        public readonly ?array $data,
        public readonly string $body,
        public readonly ?string $error = null,
    ) {
    }

    public function successful(): bool
    {
        return $this->status >= 200 && $this->status < 300;
    }

    public function isTransportError(): bool
    {
        return $this->status === 0;
    }
}
