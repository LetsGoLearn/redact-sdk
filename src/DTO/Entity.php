<?php

declare(strict_types=1);

namespace RedactSdk\DTO;

/**
 * A detected PII span. Start/End are byte offsets into the original UTF-8 text.
 */
final class Entity
{
    public function __construct(
        public readonly int $start,
        public readonly int $end,
        public readonly float $score,
        public readonly string $label,
    ) {
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            (int) ($data['start'] ?? 0),
            (int) ($data['end'] ?? 0),
            (float) ($data['score'] ?? 0.0),
            (string) ($data['label'] ?? ''),
        );
    }
}
