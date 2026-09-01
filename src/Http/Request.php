<?php

declare(strict_types=1);

namespace RedactSdk\Http;

/**
 * A transport-agnostic HTTP request.
 *
 * `json` and `multipart` are mutually exclusive: `json` is sent as a JSON body,
 * `multipart` as multipart/form-data. `timeout` overrides the transport's
 * default for this request only — document conversion plus classification takes
 * seconds, and OCR minutes, so those calls need a longer budget than the short
 * text redactions the default is tuned for.
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
        public readonly ?Multipart $multipart = null,
        public readonly ?float $timeout = null,
    ) {
    }
}
