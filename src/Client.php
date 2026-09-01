<?php

declare(strict_types=1);

namespace RedactSdk;

use RedactSdk\DTO\Label;
use RedactSdk\DTO\Policy;
use RedactSdk\DTO\RedactDocumentResult;
use RedactSdk\DTO\RedactPartsResult;
use RedactSdk\DTO\RedactResult;
use RedactSdk\Exceptions\ApiException;
use RedactSdk\Exceptions\NeedsOcrException;
use RedactSdk\Exceptions\TransportException;
use RedactSdk\Http\CurlTransport;
use RedactSdk\Http\Multipart;
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
            documentTimeout: (float) ($options['document_timeout'] ?? 120.0),
            ocrTimeout: (float) ($options['ocr_timeout'] ?? 600.0),
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
     * Redact many related strings in ONE request, with shared document
     * context. The server joins the parts with a paragraph break before
     * classification, so entities that span or depend on neighboring parts —
     * e.g. a form label ("Birthdate", "Student ID #") in one HTML text node
     * and its value in the next — are detected; each part is returned
     * redacted individually, in input order.
     *
     * Prefer this over redactMany() when the strings are fragments of one
     * document (HTML text nodes, PDF lines); use redactMany() for unrelated
     * texts (e.g. one per ticket).
     *
     * @param list<string>                     $parts
     * @param Policy|array<string, mixed>|null $policy
     */
    public function redactParts(array $parts, Policy|array|null $policy = null, ?float $threshold = null): RedactPartsResult
    {
        $payload = ['parts' => array_values($parts)];

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

        $response = $this->transport->send(new Request('POST', '/v1/redact', $payload));

        return RedactPartsResult::fromArray($this->unwrap($response));
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
     * Convert AND redact a document (PDF, DOCX, DOC, RTF, ODT, EPUB) in ONE
     * request, returning HTML with its text nodes redacted in place.
     *
     * Prefer this over converting locally and calling redactParts(): the server
     * classifies the whole document in a single pass, so a form label and its
     * value stay in the same context, and one round trip replaces one per chunk.
     *
     * @param string                           $path     Absolute path to the document.
     * @param Policy|array<string, mixed>|null $policy
     * @param bool                             $ocr      Run OCR on a scanned PDF. Minutes-slow;
     *                                                   leave false and catch NeedsOcrException
     *                                                   to queue it out of band.
     * @param bool                             $redact   False converts only, skipping
     *                                                   classification entirely. This must be
     *                                                   explicit: omitting the policy does NOT
     *                                                   mean "don't redact" — the server's
     *                                                   default policy tags every label.
     * @param float|null                       $timeout  Per-request timeout in seconds. Defaults
     *                                                   to the config's document timeout, since
     *                                                   conversion far exceeds the text-redaction
     *                                                   default.
     *
     * @throws NeedsOcrException when the PDF has no text layer and $ocr is false.
     */
    public function redactDocument(
        string $path,
        Policy|array|null $policy = null,
        ?float $threshold = null,
        bool $ocr = false,
        ?string $filename = null,
        ?float $timeout = null,
        bool $redact = true,
    ): RedactDocumentResult {
        $fields = ['ocr' => $ocr ? 'auto' : 'off'];

        // An absent policy means "use the server default", which redacts
        // everything — so convert-only has to be requested explicitly.
        if ($redact === false) {
            $fields['redact'] = 'false';
        }

        $threshold ??= $this->config->defaultThreshold;
        if ($threshold !== null) {
            $fields['threshold'] = (string) $threshold;
        }

        if ($policy !== null) {
            $serialized = $policy instanceof Policy ? $policy->toArray() : $policy;
            if ($serialized !== []) {
                $encoded = json_encode($serialized, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
                if ($encoded === false) {
                    throw new TransportException('failed to encode policy: ' . json_last_error_msg());
                }
                $fields['policy'] = $encoded;
            }
        }

        $response = $this->transport->send(new Request(
            method: 'POST',
            path: '/v1/documents:redact',
            multipart: new Multipart(
                path: $path,
                field: 'file',
                filename: $filename,
                fields: $fields,
            ),
            timeout: $timeout ?? ($ocr ? $this->config->ocrTimeout : $this->config->documentTimeout),
        ));

        // needs_ocr is a documented outcome rather than an error, so it gets its
        // own exception type instead of a generic 422.
        if ($response->status === 422 && ($response->data['error'] ?? null) === 'needs_ocr') {
            throw new NeedsOcrException(
                'document has no text layer; OCR required',
                $response->status,
                $response->data,
            );
        }

        return RedactDocumentResult::fromArray($this->unwrap($response));
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
