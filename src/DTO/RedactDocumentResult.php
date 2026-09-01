<?php

declare(strict_types=1);

namespace RedactSdk\DTO;

/**
 * Result of a whole-document redaction (Client::redactDocument).
 *
 * The server converted the uploaded file to HTML and classified every text node
 * as ONE document, so entities that depend on neighboring nodes — a form label
 * ("Birthdate", "Student ID #") and its value in the next cell — are detected.
 * Markup is preserved; only text nodes are rewritten.
 */
final class RedactDocumentResult
{
    /**
     * @param string             $html     Converted document with text redacted in place.
     * @param list<Entity>       $entities Redacted spans; offsets are relative to the
     *                                     joined text of the document's text nodes,
     *                                     not to the HTML.
     * @param array<string, int> $counts   Redaction count per label.
     */
    public function __construct(
        public readonly string $html,
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

        return new self((string) ($data['html'] ?? ''), $entities, $counts);
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
