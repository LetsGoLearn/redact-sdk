<?php

declare(strict_types=1);

namespace RedactSdk\Enums;

/**
 * Redaction mode for a detected PII span. Mirrors the server's redact.Mode.
 */
enum Mode: string
{
    /** Replace with the bracketed type, e.g. "[EMAIL]". */
    case Tag = 'tag';
    /** Replace with a fixed mask string (server-configured). */
    case Mask = 'mask';
    /** Replace with a stable keyed token, e.g. "EMAIL_a1b2c3d4". */
    case Hash = 'hash';
    /** Keep the first name, strip the surname: "Jane Doe" -> "Jane [LAST]". */
    case KeepFirst = 'keepFirst';
    /** Remove the span entirely. */
    case Drop = 'drop';
}
