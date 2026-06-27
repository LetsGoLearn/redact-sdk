<?php

declare(strict_types=1);

namespace RedactSdk\Http;

use RedactSdk\Exceptions\TransportException;

/**
 * Pluggable HTTP transport. The default is CurlTransport; tests and advanced
 * users can supply their own implementation.
 */
interface Transport
{
    /**
     * Send a single request.
     *
     * @throws TransportException on a connection-level failure.
     */
    public function send(Request $request): Response;

    /**
     * Send many requests concurrently. Transport-level failures are reported as
     * a Response with status 0 (never thrown) so one bad item does not abort the
     * batch. Results preserve the input array keys.
     *
     * @param array<array-key, Request> $requests
     * @return array<array-key, Response>
     */
    public function sendMany(array $requests, int $concurrency = 8): array;
}
