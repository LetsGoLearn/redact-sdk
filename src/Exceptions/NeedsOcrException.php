<?php

declare(strict_types=1);

namespace RedactSdk\Exceptions;

/**
 * The uploaded PDF has no text layer, and the request asked not to run OCR.
 *
 * This is an expected outcome, not a failure: OCR runs Tesseract per page and
 * takes minutes, so callers should catch this and queue an asynchronous pass
 * with `ocr: true` rather than block an interactive request on it.
 */
final class NeedsOcrException extends ApiException
{
}
