<?php

declare(strict_types=1);

namespace RedactSdk;

use RedactSdk\DTO\Label;
use RedactSdk\DTO\Policy;
use RedactSdk\DTO\RedactResult;
use RedactSdk\Exceptions\ApiException;
use RedactSdk\Exceptions\TransportException;
use RedactSdk\Http\CurlTransport;
use RedactSdk\Http\Request;
use RedactSdk\Http\Response;
use RedactSdk\Http\Transport;

/**
 * Client for the redactor PII-redaction API.
 *
 * Reuse a single instance: the underlying transport keeps connections warm, so
 * sharing one Client (the Laravel binding registers it as a singleton) is both
 * correct and fast.
 */
final class Client
{
    private readonly Transport $transport;

    public function __construct(
        private readonly Config $config,
        ?Transport $transport = null,
    ) {
        $this->transport = $transport ?? new CurlTransport(
            baseUri: $config->baseUri,
            headers: $config->headers(),
            timeout: $config->timeout,
            connectTimeout: $config->connectTimeout,
            retries: $config->retries,
            retryBaseDelayMs: $config->retryDelayMs,
        );
    }

    /**
     * Convenience constructor.
     *
     * @param array<string, mixed> $options Extra Config fields (timeout, retries, ...).
     */
    public static function make(string $baseUri, ?string $apiKey = null, array $options = []): self
    {
        return new self(new Config(
            baseUri: $baseUri,
            apiKey: $apiKey,
            timeout: (float) ($options['timeout'] ?? 10.0),
            connectTimeout: (float) ($options['connect_timeout'] ?? 3.0),
            retries: (int) ($options['retries'] ?? 2),
            retryDelayMs: (int) ($options['retry_delay_ms'] ?? 100),
            defaultThreshold: isset($options['default_threshold']) ? (float) $options['default_threshold'] : null,
        ));
    }

    /**
     * Redact PII from a single string.
     *
     * @param Policy|array<string, mixed>|null $policy
     */
    public function redact(string $text, Policy|array|null $policy = null, ?float $threshold = null): RedactResult
    {
        $response = $this->transport->send($this->redactRequest($text, $policy, $threshold));

        return RedactResult::fromArray($this->unwrap($response));
    }

    /**
     * Redact many strings concurrently. Returns results keyed by the input keys,
     * in input order. Throws on the first failed item.
     *
     * @param iterable<array-key, string>     $texts
     * @param Policy|array<string, mixed>|null $policy
     * @return array<array-key, RedactResult>
     */
    public function redactMany(iterable $texts, Policy|array|null $policy = null, ?float $threshold = null, int $concurrency = 8): array
    {
        $requests = [];
        foreach ($texts as $key => $text) {
            $requests[$key] = $this->redactRequest($text, $policy, $threshold);
        }

        $results = [];
        foreach ($this->transport->sendMany($requests, $concurrency) as $key => $response) {
            $results[$key] = RedactResult::fromArray($this->unwrap($response));
        }

        return $results;
    }

    /**
     * Like redactMany() but tolerant of partial failures: each element is
     * ['result' => RedactResult|null, 'error' => \Throwable|null].
     *
     * @param iterable<array-key, string>      $texts
     * @param Policy|array<string, mixed>|null $policy
     * @return array<array-key, array{result: RedactResult|null, error: \Throwable|null}>
     */
    public function redactManySettled(iterable $texts, Policy|array|null $policy = null, ?float $threshold = null, int $concurrency = 8): array
    {
        $requests = [];
        foreach ($texts as $key => $text) {
            $requests[$key] = $this->redactRequest($text, $policy, $threshold);
        }

        $results = [];
        foreach ($this->transport->sendMany($requests, $concurrency) as $key => $response) {
            try {
                $results[$key] = ['result' => RedactResult::fromArray($this->unwrap($response)), 'error' => null];
            } catch (\Throwable $e) {
                $results[$key] = ['result' => null, 'error' => $e];
            }
        }

        return $results;
    }

    /**
     * List the labels the server can detect.
     *
     * @return list<Label>
     */
    public function labels(): array
    {
        $data = $this->unwrap($this->transport->send(new Request('GET', '/v1/labels')));

        $labels = [];
        foreach ($data['labels'] ?? [] as $label) {
            $labels[] = Label::fromArray($label);
        }

        return $labels;
    }

    /** True if the engine is loaded and ready (GET /readyz). Never throws. */
    public function ready(): bool
    {
        return $this->ping('/readyz');
    }

    /** True if the service is up (GET /healthz). Never throws. */
    public function healthy(): bool
    {
        return $this->ping('/healthz');
    }

    public function transport(): Transport
    {
        return $this->transport;
    }

    /**
     * @param Policy|array<string, mixed>|null $policy
     */
    private function redactRequest(string $text, Policy|array|null $policy, ?float $threshold): Request
    {
        $payload = ['text' => $text];

        $threshold ??= $this->config->defaultThreshold;
        if ($threshold !== null) {
            $payload['threshold'] = $threshold;
        }

        if ($policy !== null) {
            $serialized = $policy instanceof Policy ? $policy->toArray() : $policy;
            if ($serialized !== []) {
                $payload['policy'] = $serialized;
            }
        }

        return new Request('POST', '/v1/redact', $payload);
    }

    /**
     * Validate a response and return its decoded body, or throw the right error.
     *
     * @return array<string, mixed>
     */
    private function unwrap(Response $response): array
    {
        if ($response->isTransportError()) {
            throw new TransportException($response->error ?? 'transport error');
        }
        if (!$response->successful()) {
            throw ApiException::fromResponse($response->status, $response->data);
        }

        return $response->data ?? [];
    }

    private function ping(string $path): bool
    {
        try {
            return $this->transport->send(new Request('GET', $path))->successful();
        } catch (TransportException) {
            return false;
        }
    }
}
