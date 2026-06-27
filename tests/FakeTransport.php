<?php

declare(strict_types=1);

namespace RedactSdk\Tests;

use RedactSdk\Http\Request;
use RedactSdk\Http\Response;
use RedactSdk\Http\Transport;

/**
 * In-memory transport for tests. Returns queued responses and records the
 * requests it received so assertions can inspect payloads.
 */
final class FakeTransport implements Transport
{
    /** @var list<Request> */
    public array $requests = [];

    /** @var list<Response> */
    private array $queue = [];

    public function queue(Response $response): self
    {
        $this->queue[] = $response;

        return $this;
    }

    /**
     * @param array<string, mixed> $data
     */
    public function queueJson(int $status, array $data): self
    {
        return $this->queue(new Response($status, $data, (string) json_encode($data)));
    }

    public function send(Request $request): Response
    {
        $this->requests[] = $request;

        return array_shift($this->queue) ?? new Response(200, [], '{}');
    }

    public function sendMany(array $requests, int $concurrency = 8): array
    {
        $out = [];
        foreach ($requests as $key => $request) {
            $out[$key] = $this->send($request);
        }

        return $out;
    }

    public function lastRequest(): ?Request
    {
        return $this->requests[count($this->requests) - 1] ?? null;
    }
}
