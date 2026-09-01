<?php

declare(strict_types=1);

namespace RedactSdk\Http;

use CurlHandle;
use CurlShareHandle;
use RedactSdk\Exceptions\TransportException;

/**
 * cURL-based transport. Dependency-free and tuned for throughput:
 *
 *  - a single persistent easy-handle is reused across `send()` calls, so the
 *    underlying TCP/TLS connection stays warm (keep-alive);
 *  - a shared handle pools DNS, connections, and TLS sessions across the
 *    parallel handles used by `sendMany()`;
 *  - `sendMany()` drives a `curl_multi` rolling window so at most `concurrency`
 *    requests are in flight at once;
 *  - transient failures (connect/timeout, HTTP 5xx) are retried with backoff.
 *
 * Under Laravel Octane the transport (held by the singleton Client) survives
 * between requests, so connections are reused across the whole worker lifetime.
 */
final class CurlTransport implements Transport
{
    private CurlHandle $handle;
    private CurlShareHandle $share;

    /**
     * @param list<string> $headers Default headers sent on every request (e.g. auth).
     */
    public function __construct(
        private readonly string $baseUri,
        private readonly array $headers = [],
        private readonly float $timeout = 10.0,
        private readonly float $connectTimeout = 3.0,
        private readonly int $retries = 2,
        private readonly int $retryBaseDelayMs = 100,
    ) {
        if (!\extension_loaded('curl')) {
            throw new TransportException('the curl extension is required');
        }

        $this->handle = curl_init();
        $this->share = curl_share_init();
        // Share DNS cache and TLS sessions across handles, but NOT connections:
        // sharing CURL_LOCK_DATA_CONNECT funnels the parallel sendMany() handles
        // onto a single reused connection and serializes them. The persistent
        // single handle keeps its own connection warm regardless (handle-level
        // keep-alive), so sequential send() calls still reuse the connection.
        curl_share_setopt($this->share, CURLSHOPT_SHARE, CURL_LOCK_DATA_DNS);
        curl_share_setopt($this->share, CURLSHOPT_SHARE, CURL_LOCK_DATA_SSL_SESSION);
    }

    public function send(Request $request): Response
    {
        for ($attempt = 0; ; $attempt++) {
            $this->apply($this->handle, $request);
            $body = curl_exec($this->handle);
            $errno = curl_errno($this->handle);

            if ($errno !== 0) {
                if ($attempt < $this->retries && $this->transient($errno)) {
                    $this->backoff($attempt);
                    continue;
                }
                throw new TransportException('curl error: ' . curl_error($this->handle), $errno);
            }

            $status = (int) curl_getinfo($this->handle, CURLINFO_RESPONSE_CODE);
            if ($status >= 500 && $attempt < $this->retries) {
                $this->backoff($attempt);
                continue;
            }

            return new Response($status, $this->decode((string) $body), (string) $body);
        }
    }

    public function sendMany(array $requests, int $concurrency = 8): array
    {
        if ($requests === []) {
            return [];
        }

        $concurrency = max(1, $concurrency);
        $keys = array_keys($requests);
        $queue = $keys;
        $pos = 0;
        $results = [];

        $multi = curl_multi_init();
        /** @var array<int, array{key: array-key, handle: CurlHandle, attempt: int}> $inflight */
        $inflight = [];

        $start = function (int|string $key, int $attempt) use ($multi, $requests, &$inflight): void {
            $ch = curl_init();
            $this->apply($ch, $requests[$key]);
            curl_multi_add_handle($multi, $ch);
            $inflight[spl_object_id($ch)] = ['key' => $key, 'handle' => $ch, 'attempt' => $attempt];
        };

        while ($pos < count($queue) && count($inflight) < $concurrency) {
            $start($queue[$pos++], 0);
        }

        do {
            curl_multi_exec($multi, $running);
            if ($running) {
                curl_multi_select($multi, 0.5);
            }

            while ($info = curl_multi_info_read($multi)) {
                /** @var CurlHandle $ch */
                $ch = $info['handle'];
                $meta = $inflight[spl_object_id($ch)];
                unset($inflight[spl_object_id($ch)]);
                $key = $meta['key'];
                $attempt = $meta['attempt'];

                $errno = curl_errno($ch);
                $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
                $body = (string) curl_multi_getcontent($ch);
                $retryable = ($errno !== 0 && $this->transient($errno)) || $status >= 500;

                // No curl_close(): a no-op since PHP 8.0 (handles are freed when
                // the last reference drops) and deprecated as of 8.5. Removing
                // it from the multi handle and letting $ch fall out of scope is
                // what actually releases it.
                curl_multi_remove_handle($multi, $ch);

                if ($retryable && $attempt < $this->retries) {
                    $this->backoff($attempt);
                    $start($key, $attempt + 1);
                } elseif ($errno !== 0) {
                    $results[$key] = new Response(0, null, '', 'curl error: ' . curl_strerror($errno));
                } else {
                    $results[$key] = new Response($status, $this->decode($body), $body);
                }

                if ($pos < count($queue)) {
                    $start($queue[$pos++], 0);
                }
            }
        } while ($running || $inflight !== [] || $pos < count($queue));

        curl_multi_close($multi);

        // Preserve the caller's key order.
        $ordered = [];
        foreach ($keys as $key) {
            $ordered[$key] = $results[$key];
        }

        return $ordered;
    }

    private function apply(CurlHandle $ch, Request $request): void
    {
        curl_reset($ch);

        $headers = $this->headers;
        $headers[] = 'Accept: application/json';

        $options = [
            CURLOPT_URL => $this->baseUri . $request->path,
            CURLOPT_CUSTOMREQUEST => $request->method,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HEADER => false,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_CONNECTTIMEOUT_MS => (int) ($this->connectTimeout * 1000),
            CURLOPT_TIMEOUT_MS => (int) (($request->timeout ?? $this->timeout) * 1000),
            CURLOPT_TCP_KEEPALIVE => 1,
            CURLOPT_SHARE => $this->share,
            CURLOPT_ENCODING => '', // accept + transparently decode gzip/deflate
        ];

        if ($request->json !== null) {
            $payload = json_encode($request->json, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            if ($payload === false) {
                throw new TransportException('failed to encode request JSON: ' . json_last_error_msg());
            }
            $options[CURLOPT_POSTFIELDS] = $payload;
            $headers[] = 'Content-Type: application/json';
        } elseif ($request->multipart !== null) {
            $multipart = $request->multipart;
            if (!is_file($multipart->path) || !is_readable($multipart->path)) {
                throw new TransportException("upload file is not readable: {$multipart->path}");
            }
            // Passing an array makes cURL build the multipart body and set its
            // own Content-Type with the boundary — do not set one here. CURLFile
            // streams from disk rather than buffering the document in memory.
            $options[CURLOPT_POSTFIELDS] = $multipart->fields + [
                $multipart->field => new \CURLFile(
                    $multipart->path,
                    'application/octet-stream',
                    $multipart->filename ?? basename($multipart->path),
                ),
            ];
        }

        $options[CURLOPT_HTTPHEADER] = $headers;
        curl_setopt_array($ch, $options);
    }

    /**
     * @return array<string, mixed>|null
     */
    private function decode(string $body): ?array
    {
        if ($body === '') {
            return null;
        }
        $decoded = json_decode($body, true);

        return is_array($decoded) ? $decoded : null;
    }

    private function transient(int $errno): bool
    {
        return in_array($errno, [
            CURLE_OPERATION_TIMEOUTED,
            CURLE_COULDNT_CONNECT,
            CURLE_GOT_NOTHING,
            CURLE_SEND_ERROR,
            CURLE_RECV_ERROR,
            CURLE_COULDNT_RESOLVE_HOST,
        ], true);
    }

    private function backoff(int $attempt): void
    {
        if ($this->retryBaseDelayMs <= 0) {
            return;
        }
        // Exponential backoff: base * 2^attempt, in microseconds.
        usleep($this->retryBaseDelayMs * 1000 * (2 ** $attempt));
    }

    // No __destruct: curl_close() has been a no-op since PHP 8.0 and is
    // deprecated as of 8.5, and the handle is released with the object anyway.
}
