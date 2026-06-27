<?php

declare(strict_types=1);

namespace RedactSdk\Laravel\Facades;

use Illuminate\Support\Facades\Facade;
use RedactSdk\Client;
use RedactSdk\DTO\Policy;
use RedactSdk\DTO\RedactResult;

/**
 * @method static RedactResult redact(string $text, Policy|array|null $policy = null, ?float $threshold = null)
 * @method static array redactMany(iterable $texts, Policy|array|null $policy = null, ?float $threshold = null, int $concurrency = 8)
 * @method static array redactManySettled(iterable $texts, Policy|array|null $policy = null, ?float $threshold = null, int $concurrency = 8)
 * @method static array labels()
 * @method static bool ready()
 * @method static bool healthy()
 *
 * @see Client
 */
class Redact extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return 'redact';
    }
}
