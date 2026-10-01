<?php

namespace Veltrom\License\Testing;

use Veltrom\License\Http\Response;
use Veltrom\License\Http\Transport;
use Veltrom\License\Http\TransportException;

/**
 * Answers API calls from a script, keyed by path (`/licenses/activate`), and
 * records every request. `offline()` makes every call fail like an
 * unreachable server.
 */
final class FakeTransport implements Transport
{
    /** @var array<string, list<Response>> */
    private array $responses = [];

    /** @var array<string, true> paths whose only remaining answer has been served */
    private array $served = [];

    /** @var list<array{method: string, path: string, json: array<string, mixed>|null}> */
    public array $requests = [];

    private bool $offline = false;

    /**
     * Queue an answer for a path. Answers are served in order and the last
     * one repeats until another is queued.
     *
     * @param  array<string, mixed>  $json
     */
    public function respond(string $path, int $status, array $json): self
    {
        // An answer already served stops repeating once a new one is queued.
        if (isset($this->served[$path])) {
            $this->responses[$path] = [];
            unset($this->served[$path]);
        }

        $this->responses[$path][] = new Response($status, $json, 'req_test');

        return $this;
    }

    /**
     * @param  array<string, mixed>  $extra
     */
    public function error(string $path, int $status, string $code, array $extra = []): self
    {
        return $this->respond($path, $status, ['success' => false, 'error' => ['code' => $code, 'message' => $code] + $extra]);
    }

    public function offline(bool $offline = true): self
    {
        $this->offline = $offline;

        return $this;
    }

    public function send(string $method, string $url, ?array $json = null): Response
    {
        $path = (string) preg_replace('#^.*/v1#', '', (string) parse_url($url, PHP_URL_PATH));
        $this->requests[] = ['method' => $method, 'path' => $path, 'json' => $json];

        if ($this->offline) {
            throw new TransportException('cURL error 6: Could not resolve host');
        }

        $queue = $this->responses[$path] ?? [];

        if ($queue === []) {
            return new Response(404, ['success' => false, 'error' => ['code' => 'invalid_request', 'message' => 'not scripted']]);
        }

        if (count($queue) > 1) {
            array_shift($this->responses[$path]);
        } else {
            $this->served[$path] = true;
        }

        return $queue[0];
    }

    /**
     * @return list<array{method: string, path: string, json: array<string, mixed>|null}>
     */
    public function requestsTo(string $path): array
    {
        return array_values(array_filter($this->requests, fn (array $request) => $request['path'] === $path));
    }
}
