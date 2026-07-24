<?php

declare(strict_types=1);

namespace RedactSdk\DTO;

/**
 * Result of a multi-part redaction request (Client::redactParts). The server
 * classifies the parts as ONE joined document — so entities needing
 * cross-part context (a field label in one part, its value in the next) are
 * detected — and returns each part redacted individually.
 */
final class RedactPartsResult
{
    /**
     * @param list<string>       $parts    Redacted parts, in request order.
     * @param list<Entity>       $entities Redacted spans; offsets are relative
     *                                     to the joined document, not to parts.
     * @param array<string, int> $counts   Redaction count per label.
     */
    public function __construct(
        public readonly array $parts,
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

        /** @var list<string> $parts */
        $parts = array_map(strval(...), $data['parts'] ?? []);

        return new self($parts, $entities, $counts);
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
