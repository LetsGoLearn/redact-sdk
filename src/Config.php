<?php

declare(strict_types=1);

namespace RedactSdk;

/**
 * Immutable client configuration.
 */
final class Config
{
    public function __construct(
        public readonly string $baseUri,
        public readonly ?string $apiKey = null,
        public readonly float $timeout = 10.0,
        public readonly float $connectTimeout = 3.0,
        public readonly int $retries = 2,
        public readonly int $retryDelayMs = 100,
        public readonly ?float $defaultThreshold = null,
        public readonly string $userAgent = 'redact-sdk-php/1.0',
    ) {
    }

    /**
     * Default headers for every request (User-Agent + bearer auth).
     *
     * @return list<string>
     */
    public function headers(): array
    {
        $headers = ['User-Agent: ' . $this->userAgent];
        if ($this->apiKey !== null && $this->apiKey !== '') {
            $headers[] = 'Authorization: Bearer ' . $this->apiKey;
        }

        return $headers;
    }
}
