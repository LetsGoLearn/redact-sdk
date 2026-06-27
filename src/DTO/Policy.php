<?php

declare(strict_types=1);

namespace RedactSdk\DTO;

use RedactSdk\Enums\Mode;

/**
 * Per-label redaction policy. Immutable; the `with*` helpers return new
 * instances so a policy can be composed fluently and reused safely.
 */
final class Policy
{
    /**
     * @param Mode|string|null              $default Mode for labels without an override (server default: tag).
     * @param array<string, Mode|string>    $byLabel Per-label mode overrides, keyed by raw label (e.g. "private_person").
     * @param list<string>                  $labels  Allow-list; when non-empty only these labels are redacted.
     */
    public function __construct(
        public readonly Mode|string|null $default = null,
        public readonly array $byLabel = [],
        public readonly array $labels = [],
    ) {
    }

    public static function make(): self
    {
        return new self();
    }

    public function withDefault(Mode|string $mode): self
    {
        return new self($mode, $this->byLabel, $this->labels);
    }

    /** Set the mode for a single label (e.g. ->label('private_person', Mode::KeepFirst)). */
    public function label(string $label, Mode|string $mode): self
    {
        return new self($this->default, [...$this->byLabel, $label => $mode], $this->labels);
    }

    /** Restrict redaction to the given labels (allow-list). */
    public function only(string ...$labels): self
    {
        return new self($this->default, $this->byLabel, array_values($labels));
    }

    /**
     * @return array{default?: string, byLabel?: array<string, string>, labels?: list<string>}
     */
    public function toArray(): array
    {
        $out = [];
        if ($this->default !== null) {
            $out['default'] = $this->normalize($this->default);
        }
        if ($this->byLabel !== []) {
            $byLabel = [];
            foreach ($this->byLabel as $label => $mode) {
                $byLabel[$label] = $this->normalize($mode);
            }
            $out['byLabel'] = $byLabel;
        }
        if ($this->labels !== []) {
            $out['labels'] = array_values($this->labels);
        }

        return $out;
    }

    public function isEmpty(): bool
    {
        return $this->toArray() === [];
    }

    private function normalize(Mode|string $mode): string
    {
        return $mode instanceof Mode ? $mode->value : $mode;
    }
}
