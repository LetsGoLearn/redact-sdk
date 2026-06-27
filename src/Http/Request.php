<?php

declare(strict_types=1);

namespace RedactSdk\Http;

/**
 * A transport-agnostic HTTP request. `json`, when non-null, is sent as a JSON body.
 */
final class Request
{
    /**
     * @param array<string, mixed>|null $json
     */
    public function __construct(
        public readonly string $method,
        public readonly string $path,
        public readonly ?array $json = null,
    ) {
    }
}
