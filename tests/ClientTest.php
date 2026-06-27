<?php

declare(strict_types=1);

namespace RedactSdk\Tests;

use PHPUnit\Framework\TestCase;
use RedactSdk\Client;
use RedactSdk\Config;
use RedactSdk\DTO\Policy;
use RedactSdk\Enums\Mode;
use RedactSdk\Exceptions\AuthenticationException;
use RedactSdk\Exceptions\TransportException;
use RedactSdk\Exceptions\ValidationException;
use RedactSdk\Http\Response;

final class ClientTest extends TestCase
{
    private function client(FakeTransport $transport, ?float $defaultThreshold = null): Client
    {
        return new Client(new Config('http://redactor.test', 'key', defaultThreshold: $defaultThreshold), $transport);
    }

    public function testRedactBuildsPayloadAndMapsResult(): void
    {
        $transport = (new FakeTransport())->queueJson(200, [
            'redacted' => 'Email Jane [LAST] at [EMAIL]',
            'entities' => [
                ['start' => 6, 'end' => 14, 'score' => 0.99, 'label' => 'private_person'],
                ['start' => 18, 'end' => 31, 'score' => 0.98, 'label' => 'private_email'],
            ],
            'counts' => ['private_person' => 1, 'private_email' => 1],
        ]);

        $result = $this->client($transport)->redact(
            'Email Jane Doe at jane@acme.com',
            Policy::make()->label('private_person', Mode::KeepFirst),
            0.5,
        );

        $request = $transport->lastRequest();
        $this->assertNotNull($request);
        $this->assertSame('POST', $request->method);
        $this->assertSame('/v1/redact', $request->path);
        $this->assertSame([
            'text' => 'Email Jane Doe at jane@acme.com',
            'threshold' => 0.5,
            'policy' => ['byLabel' => ['private_person' => 'keepFirst']],
        ], $request->json);

        $this->assertSame('Email Jane [LAST] at [EMAIL]', $result->redacted);
        $this->assertCount(2, $result->entities);
        $this->assertSame('private_person', $result->entities[0]->label);
        $this->assertSame(2, $result->count());
        $this->assertSame(1, $result->count('private_email'));
        $this->assertTrue($result->hasPii());
    }

    public function testDefaultThresholdApplied(): void
    {
        $transport = (new FakeTransport())->queueJson(200, ['redacted' => 'x', 'entities' => [], 'counts' => []]);
        $this->client($transport, defaultThreshold: 0.7)->redact('hello');

        $this->assertSame(0.7, $transport->lastRequest()?->json['threshold'] ?? null);
    }

    public function testNoPolicyOrThresholdOmitsThoseKeys(): void
    {
        $transport = (new FakeTransport())->queueJson(200, ['redacted' => 'x', 'entities' => [], 'counts' => []]);
        $this->client($transport)->redact('hello');

        $this->assertSame(['text' => 'hello'], $transport->lastRequest()?->json);
    }

    public function testAuthErrorThrowsAuthenticationException(): void
    {
        $transport = (new FakeTransport())->queueJson(401, ['error' => 'missing or invalid API key']);

        $this->expectException(AuthenticationException::class);
        $this->expectExceptionMessage('missing or invalid API key');
        $this->client($transport)->redact('hello');
    }

    public function testValidationErrorThrowsValidationException(): void
    {
        $transport = (new FakeTransport())->queueJson(400, ['error' => "field 'text' is required"]);

        try {
            $this->client($transport)->redact('');
            $this->fail('expected ValidationException');
        } catch (ValidationException $e) {
            $this->assertSame(400, $e->status);
            $this->assertSame("field 'text' is required", $e->getMessage());
        }
    }

    public function testTransportErrorThrowsTransportException(): void
    {
        $transport = (new FakeTransport())->queue(new Response(0, null, '', 'curl error: Connection refused'));

        $this->expectException(TransportException::class);
        $this->client($transport)->redact('hello');
    }

    public function testLabelsAreParsed(): void
    {
        $transport = (new FakeTransport())->queueJson(200, [
            'labels' => [
                ['label' => 'private_person', 'tag' => 'PERSON'],
                ['label' => 'private_email', 'tag' => 'EMAIL'],
            ],
        ]);

        $labels = $this->client($transport)->labels();

        $this->assertCount(2, $labels);
        $this->assertSame('private_person', $labels[0]->label);
        $this->assertSame('EMAIL', $labels[1]->tag);
        $this->assertSame('/v1/labels', $transport->lastRequest()?->path);
    }

    public function testRedactManyPreservesKeysAndOrder(): void
    {
        $transport = new FakeTransport();
        $transport->queueJson(200, ['redacted' => 'A', 'entities' => [], 'counts' => []]);
        $transport->queueJson(200, ['redacted' => 'B', 'entities' => [], 'counts' => []]);

        $results = $this->client($transport)->redactMany(['first' => 'a', 'second' => 'b']);

        $this->assertSame(['first', 'second'], array_keys($results));
        $this->assertSame('A', $results['first']->redacted);
        $this->assertSame('B', $results['second']->redacted);
    }

    public function testRedactManySettledCapturesErrors(): void
    {
        $transport = new FakeTransport();
        $transport->queueJson(200, ['redacted' => 'ok', 'entities' => [], 'counts' => []]);
        $transport->queue(new Response(0, null, '', 'curl error: timeout'));

        $results = $this->client($transport)->redactManySettled(['a', 'b']);

        $this->assertSame('ok', $results[0]['result']?->redacted);
        $this->assertNull($results[0]['error']);
        $this->assertNull($results[1]['result']);
        $this->assertInstanceOf(TransportException::class, $results[1]['error']);
    }

    public function testReadyReturnsFalseOnTransportError(): void
    {
        $transport = (new FakeTransport())->queue(new Response(0, null, '', 'down'));
        $this->assertFalse($this->client($transport)->ready());
    }

    public function testReadyTrueOn200(): void
    {
        $transport = (new FakeTransport())->queueJson(200, ['status' => 'ready']);
        $this->assertTrue($this->client($transport)->ready());
    }
}
