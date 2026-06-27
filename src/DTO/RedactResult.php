<?php

declare(strict_types=1);

namespace RedactSdk\DTO;

/**
 * Result of a redaction request.
 */
final class RedactResult
{
    /**
     * @param list<Entity>          $entities Spans that were redacted, in document order.
     * @param array<string, int>    $counts   Redaction count per label.
     */
    public function __construct(
        public readonly string $redacted,
        public readonly array $entities,
        public readonly array $counts,
    ) {
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        $entities = [];
        foreach ($data['entities'] ?? [] as $e) {
            $entities[] = Entity::fromArray($e);
        }

        /** @var array<string, int> $counts */
        $counts = $data['counts'] ?? [];

        return new self((string) ($data['redacted'] ?? ''), $entities, $counts);
    }

    /** Total redactions, or the count for a single label when given. */
    public function count(?string $label = null): int
    {
        if ($label !== null) {
            return $this->counts[$label] ?? 0;
        }

        return array_sum($this->counts);
    }

    public function hasPii(): bool
    {
        return $this->entities !== [];
    }
}
