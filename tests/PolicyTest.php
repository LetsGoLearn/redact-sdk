<?php

declare(strict_types=1);

namespace RedactSdk\Tests;

use PHPUnit\Framework\TestCase;
use RedactSdk\DTO\Policy;
use RedactSdk\Enums\Mode;

final class PolicyTest extends TestCase
{
    public function testEmptyPolicySerializesToEmptyArray(): void
    {
        $this->assertSame([], Policy::make()->toArray());
        $this->assertTrue(Policy::make()->isEmpty());
    }

    public function testFluentBuildAndSerialize(): void
    {
        $policy = Policy::make()
            ->withDefault(Mode::Tag)
            ->label('private_person', Mode::KeepFirst)
            ->label('secret', 'drop');

        $this->assertSame([
            'default' => 'tag',
            'byLabel' => [
                'private_person' => 'keepFirst',
                'secret' => 'drop',
            ],
        ], $policy->toArray());
    }

    public function testOnlyAllowList(): void
    {
        $policy = Policy::make()->only('private_email', 'private_phone');

        $this->assertSame([
            'labels' => ['private_email', 'private_phone'],
        ], $policy->toArray());
    }

    public function testImmutability(): void
    {
        $base = Policy::make()->withDefault(Mode::Tag);
        $derived = $base->label('private_person', Mode::KeepFirst);

        $this->assertSame([], $base->byLabel, 'withers must not mutate the original');
        $this->assertArrayHasKey('private_person', $derived->byLabel);
    }
}
