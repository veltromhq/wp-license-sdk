<?php

namespace Veltrom\License\Http;

interface Transport
{
    /**
     * @param  array<string, mixed>|null  $json
     *
     * @throws TransportException when the server could not be reached at all
     */
    public function send(string $method, string $url, ?array $json = null): Response;
}
