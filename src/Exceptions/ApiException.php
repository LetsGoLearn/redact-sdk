<?php

declare(strict_types=1);

namespace RedactSdk\Exceptions;

/**
 * The API returned a non-2xx response.
 */
class ApiException extends RedactException
{
    /**
     * @param array<string, mixed>|null $body Decoded response body, when JSON.
     */
    public function __construct(
        string $message,
        public readonly int $status,
        public readonly ?array $body = null,
    ) {
        parent::__construct($message, $status);
    }

    /**
     * Build the most specific exception for a status code.
     *
     * @param array<string, mixed>|null $body
     */
    public static function fromResponse(int $status, ?array $body): self
    {
        $message = is_array($body) && isset($body['error']) && is_string($body['error'])
            ? $body['error']
            : "HTTP {$status}";

        return match ($status) {
            400 => new ValidationException($message, $status, $body),
            401, 403 => new AuthenticationException($message, $status, $body),
            default => new self($message, $status, $body),
        };
    }
}
