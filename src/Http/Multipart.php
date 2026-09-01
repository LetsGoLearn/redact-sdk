<?php

declare(strict_types=1);

namespace RedactSdk\Http;

/**
 * A multipart/form-data body: one uploaded file plus scalar fields.
 *
 * The file is referenced by path rather than read into memory, so cURL streams
 * it straight from disk — a 20 MB document never lands in PHP's heap.
 */
final class Multipart
{
    /**
     * @param string                $path     Absolute path to the file to upload.
     * @param string                $field    Form field name for the file part.
     * @param string|null           $filename Filename reported to the server.
     * @param array<string, string> $fields   Additional scalar form fields.
     */
    public function __construct(
        public readonly string $path,
        public readonly string $field = 'file',
        public readonly ?string $filename = null,
        public readonly array $fields = [],
    ) {
    }
}
