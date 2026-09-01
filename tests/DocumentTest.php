<?php

declare(strict_types=1);

namespace RedactSdk\Tests;

use PHPUnit\Framework\TestCase;
use RedactSdk\Client;
use RedactSdk\Config;
use RedactSdk\DTO\Policy;
use RedactSdk\Enums\Mode;
use RedactSdk\Exceptions\NeedsOcrException;
use RedactSdk\Http\Response;

final class DocumentTest extends TestCase
{
    private string $file;

    protected function setUp(): void
    {
        $this->file = (string) tempnam(sys_get_temp_dir(), 'redactdoc_test_');
        file_put_contents($this->file, '%PDF-1.4 stub');
    }

    protected function tearDown(): void
    {
        @unlink($this->file);
    }

    private function client(FakeTransport $transport): Client
    {
        return new Client(new Config(baseUri: 'http://example.test', apiKey: 'k'), $transport);
    }

    public function testSendsMultipartWithPolicyAndOptions(): void
    {
        $transport = (new FakeTransport)->queueJson(200, [
            'html'     => '<p>Email [EMAIL]</p>',
            'entities' => [['start' => 6, 'end' => 19, 'score' => 0.99, 'label' => 'private_email']],
            'counts'   => ['private_email' => 1],
        ]);

        $result = $this->client($transport)->redactDocument(
            path: $this->file,
            policy: Policy::make()->label('private_person', Mode::KeepFirst),
            threshold: 0.7,
            filename: 'report.pdf',
        );

        $request = $transport->lastRequest();
        self::assertSame('POST', $request->method);
        self::assertSame('/v1/documents:redact', $request->path);
        self::assertNull($request->json, 'a document upload must not be sent as a JSON body');
        self::assertNotNull($request->multipart);

        $multipart = $request->multipart;
        self::assertSame($this->file, $multipart->path);
        self::assertSame('file', $multipart->field);
        self::assertSame('report.pdf', $multipart->filename);
        self::assertSame('off', $multipart->fields['ocr']);
        self::assertSame('0.7', $multipart->fields['threshold']);
        self::assertSame(
            ['byLabel' => ['private_person' => 'keepFirst']],
            json_decode($multipart->fields['policy'], true),
        );

        self::assertSame('<p>Email [EMAIL]</p>', $result->html);
        self::assertSame(1, $result->count());
        self::assertSame(1, $result->count('private_email'));
        self::assertTrue($result->hasPii());
    }

    public function testOcrFlagAndTimeoutSelection(): void
    {
        $transport = (new FakeTransport)
            ->queueJson(200, ['html' => '<p>x</p>'])
            ->queueJson(200, ['html' => '<p>x</p>']);

        $client = $this->client($transport);

        $client->redactDocument(path: $this->file);
        self::assertSame('off', $transport->lastRequest()->multipart->fields['ocr']);
        // Conversion far exceeds the short text-redaction timeout, so the
        // document call must carry its own longer budget.
        self::assertSame(120.0, $transport->lastRequest()->timeout);

        $client->redactDocument(path: $this->file, ocr: true);
        self::assertSame('auto', $transport->lastRequest()->multipart->fields['ocr']);
        self::assertSame(600.0, $transport->lastRequest()->timeout);
    }

    public function testNeedsOcrGetsItsOwnException(): void
    {
        $transport = (new FakeTransport)->queueJson(422, [
            'error'     => 'needs_ocr',
            'needs_ocr' => true,
        ]);

        $this->expectException(NeedsOcrException::class);
        $this->client($transport)->redactDocument(path: $this->file);
    }

    public function testOtherErrorsStillRaiseApiException(): void
    {
        $transport = (new FakeTransport)->queueJson(413, ['error' => 'uploaded document too large']);

        $this->expectException(\RedactSdk\Exceptions\ApiException::class);
        $this->client($transport)->redactDocument(path: $this->file);
    }

    public function testTransportFailureSurfaces(): void
    {
        $transport = (new FakeTransport)->queue(new Response(0, null, '', 'connect timed out'));

        $this->expectException(\RedactSdk\Exceptions\TransportException::class);
        $this->client($transport)->redactDocument(path: $this->file);
    }

    public function testNoPolicySendsNoPolicyField(): void
    {
        $transport = (new FakeTransport)->queueJson(200, ['html' => '<p>Jane Doe</p>']);

        $this->client($transport)->redactDocument(path: $this->file);

        self::assertArrayNotHasKey('policy', $transport->lastRequest()->multipart->fields);
    }
}
