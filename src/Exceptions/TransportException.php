<?php

declare(strict_types=1);

namespace RedactSdk\Exceptions;

/**
 * A transport-level failure (connection refused, DNS, timeout, TLS, ...).
 * No HTTP response was received.
 */
class TransportException extends RedactException
{
}
