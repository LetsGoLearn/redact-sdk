<?php

declare(strict_types=1);

namespace RedactSdk\DTO;

/**
 * A supported entity label and its friendly tag (from GET /v1/labels).
 */
final class Label
{
    public function __construct(
        public readonly string $label,
        public readonly string $tag,
    ) {
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        return new self((string) ($data['label'] ?? ''), (string) ($data['tag'] ?? ''));
    }
}
