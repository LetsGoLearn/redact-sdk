<?php

declare(strict_types=1);

namespace RedactSdk\DTO;

/**
 * Result of a whole-document redaction (Client::redactDocument).
 *
 * The server converted the uploaded file and classified it as ONE document, so
 * entities that depend on their neighbours — a form label ("Birthdate",
 * "Student ID #") and its value in the next cell — are detected. For `html`,
 * markup is preserved and only text nodes are rewritten; for `markdown` the
 * whole document is classified as a single buffer.
 */
final class RedactDocumentResult
{
    /**
     * @param string             $html     Set for format=html; empty otherwise.
     * @param list<Entity>       $entities Redacted spans; offsets are relative to the
     *                                     classified text (the joined text nodes for
     *                                     html, the whole document for markdown), not
     *                                     to the returned markup.
     * @param array<string, int> $counts   Redaction count per label.
     * @param string             $markdown Set for format=markdown; empty otherwise.
     */
    public function __construct(
        public readonly string $html,
        public readonly array $entities,
        public readonly array $counts,
        public readonly string $markdown = '',
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

        return new self(
            (string) ($data['html'] ?? ''),
            $entities,
            $counts,
            (string) ($data['markdown'] ?? ''),
        );
    }

    /** The redacted document, whichever format was requested. */
    public function content(): string
    {
        return $this->markdown !== '' ? $this->markdown : $this->html;
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
