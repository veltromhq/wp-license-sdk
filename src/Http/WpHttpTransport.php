<?php

namespace Veltrom\License\Http;

final class WpHttpTransport implements Transport
{
    public function __construct(private readonly int $timeoutSeconds = 15)
    {
    }

    public function send(string $method, string $url, ?array $json = null): Response
    {
        $args = [
            'method' => $method,
            'timeout' => $this->timeoutSeconds,
            'headers' => ['Accept' => 'application/json'],
        ];

        if ($json !== null) {
            $args['headers']['Content-Type'] = 'application/json';
            $args['body'] = (string) wp_json_encode($json);
        }

        $response = wp_remote_request($url, $args);

        if (is_wp_error($response)) {
            throw new TransportException($response->get_error_message());
        }

        $decoded = json_decode((string) wp_remote_retrieve_body($response), true);
        $requestId = wp_remote_retrieve_header($response, 'x-request-id');

        return new Response(
            (int) wp_remote_retrieve_response_code($response),
            is_array($decoded) ? $decoded : null,
            is_string($requestId) && $requestId !== '' ? $requestId : null,
        );
    }
}
