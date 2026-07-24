<?php

declare(strict_types=1);

namespace RedactSdk\DTO;

/**
 * A supported entity label and its friendly tag (from GET /v1/labels).
 *
 * The constants are the raw label strings accepted by Policy::only() and
 * Policy::label(); prefer them over hand-typed strings so new labels are
 * picked up by search/refactor tools.
 */
final class Label
{
    public const PERSON = 'private_person';
    public const EMAIL = 'private_email';
    public const PHONE = 'private_phone';
    public const ADDRESS = 'private_address';
    /** Any date the NER model detects (meetings, tests, reviews, ...). */
    public const DATE = 'private_date';
    public const URL = 'private_url';
    /** Student grade level (deterministic backstop; FERPA quasi-identifier). */
    public const GRADE = 'private_grade';
    /**
     * A date anchored to a birth-related field label ("Birthdate", "DOB",
     * "Date of Birth", "born"). Kept separate from DATE so a policy can strip
     * birthdates while preserving event dates. Server-side, a policy that
     * targets DATE always covers DATE_OF_BIRTH as well.
     */
    public const DATE_OF_BIRTH = 'date_of_birth';
    public const ACCOUNT_NUMBER = 'account_number';
    public const SECRET = 'secret';

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
